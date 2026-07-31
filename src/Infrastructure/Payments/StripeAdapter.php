<?php

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\Entities\PlanPrice;

/**
 * Stripe payment gateway adapter.
 *
 * Implements checkout sessions, webhook validation (with HMAC-SHA256 signature verification),
 * event parsing, and refunds.
 *
 * SECURITY: Webhook signature verification is CRITICAL. All webhook events must be validated
 * against the Stripe webhook secret before processing.
 */
final class StripeAdapter implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $publishableKey,
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        private readonly bool   $testMode = false
    ) {}

    /**
     * Create a Stripe checkout session.
     *
     * Uses the Stripe secret key injected via constructor. If the plan has trial days
     * configured, pass trial_period_days to Stripe API. Stripe will automatically set
     * subscription.trial_end timestamp on the webhook event.
     *
     * @param Order $order The order to create checkout for
     * @return array ['checkout_url' => string, 'external_id' => string]
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        \Stripe\Stripe::setApiKey($this->secretKey);

        // Ensure Stripe session ID is appended to success URL if the developer omitted it
        if (strpos($successUrl, '{CHECKOUT_SESSION_ID}') === false) {
            $separator = (strpos($successUrl, '?') !== false) ? '&' : '?';
            $successUrl .= $separator . 'session_id={CHECKOUT_SESSION_ID}';
        }

        $userId = $order->externalClientId();
        $stripeCustomerId = \LemurAse\AseManager::getGatewayCustomerId($userId, 'stripe');

        if (!$stripeCustomerId && class_exists('App\Models\User')) {
            $user = \App\Models\User::find($userId);
            if ($user) {
                try {
                    // 1. Search Stripe for existing customer with this email
                    $search = \Stripe\Customer::search([
                        'query' => "email:'" . $user->email . "'",
                    ]);
                    if (!empty($search->data)) {
                        $stripeCustomerId = $search->data[0]->id;
                    } else {
                        // 2. Create customer if not found
                        $customer = \Stripe\Customer::create([
                            'email' => $user->email,
                            'name' => trim(($user->name ?? '') . ' ' . ($user->last_name ?? '')),
                            'metadata' => [
                                'user_id' => $userId,
                            ],
                        ]);
                        $stripeCustomerId = $customer->id;
                    }
                    \LemurAse\AseManager::saveGatewayCustomerId($userId, 'stripe', $stripeCustomerId);
                } catch (\Exception $e) {
                    error_log("Stripe customer creation/lookup failed: " . $e->getMessage());
                }
            }
        }

        $sessionParams = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($order->amount()->currency()->toString()),
                    'product_data' => [
                        'name' => 'Subscription',
                    ],
                    'unit_amount' => (int) round($order->amount()->amount() * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment', // Can be changed to 'subscription' if needed
            'client_reference_id' => $order->externalClientId(),
            'metadata' => [
                'order_id' => $order->id()->uuid(),
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if ($stripeCustomerId) {
            $sessionParams['customer'] = $stripeCustomerId;
        }

        $session = \Stripe\Checkout\Session::create($sessionParams);

        return [
            'checkout_url' => $session->url,
            'external_id' => $session->id
        ];
    }

    /**
     * Validate Stripe webhook signature using HMAC-SHA256.
     *
     * CRITICAL SECURITY: This prevents webhook spoofing.
     *
     * @param array $payload The decoded webhook payload
     * @param array $headers The HTTP headers (must include 'stripe-signature' or 'Stripe-Signature')
     *
     * @return bool True if signature is valid, false otherwise
     */
    public function validateWebhook(array $payload, array $headers): bool
    {
        if (!$this->webhookSecret) {
            error_log("Stripe: webhook secret not configured");
            return false;
        }

        $sigHeader = $headers['stripe-signature']
                  ?? $headers['Stripe-Signature']
                  ?? $headers['STRIPE-SIGNATURE']
                  ?? '';

        $rawBody = $headers['X-RAW-BODY'] ?? '';

        try {
            // This method validates the signature and the timestamp automatically
            \Stripe\WebhookSignature::verifyHeader(
                $rawBody,
                $sigHeader,
                $this->webhookSecret,
                300 // 5 minutes tolerance
            );
            return true;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log("Stripe: signature verification failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse a Stripe webhook event into normalized form.
     *
     * @param array $payload The decoded webhook payload
     * @return array Normalized event data
     */
    public function parseWebhookEvent(array $payload): array
    {
        $type = $payload['type'] ?? 'unknown';
        $data = $payload['data']['object'] ?? [];

        $action = match ($type) {
            'checkout.session.completed' => 'INITIAL_PAYMENT',
            'invoice.paid' => 'RENEWAL_PAYMENT',
            'invoice.payment_failed' => 'PAYMENT_FAILED',
            'customer.subscription.deleted', 'customer.subscription.canceled' => 'SUBSCRIPTION_CANCELED',
            'customer.subscription.updated' => 'SUBSCRIPTION_UPDATED',
            'charge.refunded' => 'REFUND_PROCESSED',
            default => 'IGNORED'
        };

        // Extract trial end if present
        $trialEnd = null;
        if (isset($data['trial_end'])) {
            $trialEnd = new \DateTimeImmutable('@' . $data['trial_end']);
        }

        // Base fields for all events
        $result = [
            'action' => $action,
            'external_order_id' => $data['id'] ?? null,
            'external_subscription_id' => $data['subscription'] ?? $data['id'] ?? null,
            'external_transaction_id' => $payload['id'] ?? null,
            'internal_order_id' => $data['metadata']['order_id'] ?? $data['client_reference_id'] ?? null,
            'amount' => ($data['amount_total'] ?? $data['amount_paid'] ?? $data['amount'] ?? 0) / 100,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'status' => 'paid',
            'external_client_id' => $data['client_reference_id'] ?? $data['customer'] ?? null,
            'trial_end' => $trialEnd,
            'full_refund' => isset($data['refunded']) && $data['refunded'],
            'raw_payload' => $payload
        ];

        // Additional fields for SUBSCRIPTION_UPDATED
        if ($action === 'SUBSCRIPTION_UPDATED') {
            $result = array_merge($result, [
                'new_status'                  => $data['status'] ?? 'active',
                'current_period_start'        => $data['current_period_start'] ?? null,
                'current_period_end'          => $data['current_period_end'] ?? null,
                'cancel_at_period_end'        => $data['cancel_at_period_end'] ?? false,
                'new_plan_price_external_id'  => $data['items']['data'][0]['price']['id'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * Issue a refund for a Stripe charge.
     *
     * @param string $externalTransactionId Stripe charge ID
     * @param float  $amount                Amount to refund (in the original currency)
     * @param string $reason                Reason for refund
     *
     * @return array ['status' => 'success'|'failed', 'external_refund_id' => string, 'error' => string|null]
     */
    public function refund(string $externalTransactionId, float $amount, string $reason): array
    {
        try {
            \Stripe\Stripe::setApiKey($this->secretKey);
            $refund = \Stripe\Refund::create([
                'charge' => $externalTransactionId,
                'amount' => (int) round($amount * 100), // Convert to cents
                'reason' => $reason === 'fraudulent' ? 'fraudulent' : 'requested_by_customer',
                'metadata' => ['refund_reason' => $reason]
            ]);

            return [
                'status' => 'success',
                'external_refund_id' => $refund->id
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch the current status of a Stripe checkout session.
     */
    public function verifyTransaction(string $externalId): array
    {
        \Stripe\Stripe::setApiKey($this->secretKey);
        
        try {
            $session = \Stripe\Checkout\Session::retrieve($externalId);
            
            if ($session->payment_status === 'paid') {
                return [
                    'action' => 'INITIAL_PAYMENT',
                    'external_id' => $session->id,
                    'external_subscription_id' => $session->subscription ?? $session->id,
                    'external_transaction_id' => $session->payment_intent ?? $session->id,
                    'amount' => $session->amount_total / 100,
                    'currency' => strtoupper($session->currency),
                    'external_client_id' => $session->client_reference_id,
                    'internal_order_id' => $session->metadata->order_id ?? null,
                    'raw_payload' => $session->toArray()
                ];
            }
            
            return [
                'action' => 'IGNORED', 
                'status' => $session->payment_status
            ];
        } catch (\Exception $e) {
             return [
                 'action' => 'FAILED', 
                 'error' => $e->getMessage()
             ];
        }
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): array
    {
        $this->init();
        try {
            if ($atPeriodEnd) {
                $sub = \Stripe\Subscription::update($externalId, [
                    'cancel_at_period_end' => true
                ]);
            } else {
                $sub = \Stripe\Subscription::retrieve($externalId);
                $sub->cancel();
            }

            return ['status' => 'success'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function pauseSubscription(string $externalId): array
    {
        $this->init();
        try {
            \Stripe\Subscription::update($externalId, [
                'pause_collection' => [
                    'behavior' => 'void'
                ]
            ]);
            return ['status' => 'success'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}

