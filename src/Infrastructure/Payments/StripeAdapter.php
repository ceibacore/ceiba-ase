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
    public function createCheckoutSession(Order $order): array
    {
        // In production, use $this->secretKey:
        // \Stripe\Stripe::setApiKey($this->secretKey);
        // $session = \Stripe\Checkout\Session::create([...]);

        // Mock response
        return [
            'checkout_url' => "https://checkout.stripe.com/pay/" . bin2hex(random_bytes(16)),
            'external_id' => "cs_test_" . bin2hex(random_bytes(16))
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
            // Webhook secret not configured; reject all webhooks
            error_log("Stripe: webhook secret not configured");
            return false;
        }

        // Get the Stripe signature header (case-insensitive)
        $sigHeader = $headers['stripe-signature'] 
                  ?? $headers['Stripe-Signature'] 
                  ?? $headers['STRIPE-SIGNATURE'] 
                  ?? '';

        if (!$sigHeader) {
            error_log("Stripe: missing stripe-signature header");
            return false;
        }

        // Parse signature header: "t=<timestamp>,v1=<signature>"
        // Example: "t=1492774428,v1=5257a869e7ecebeda32affa2d3eb21eb1ef6dc88,v0=..."
        $parts = [];
        foreach (explode(',', $sigHeader) as $part) {
            if (strpos($part, '=') === false) continue;
            [$k, $v] = explode('=', $part, 2);
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if (!$timestamp || !$signature) {
            error_log("Stripe: invalid signature header format");
            return false;
        }

        // Guard against replay attacks: timestamp must be within ±5 minutes of now
        $maxAge = 300; // 5 minutes
        $now = time();
        if (abs($now - (int)$timestamp) > $maxAge) {
            error_log("Stripe: webhook timestamp outside acceptable range (possible replay attack)");
            return false;
        }

        // Get the raw body from headers (passed by WebhookRequestHandler via AseManager)
        $rawBody = $headers['X-RAW-BODY'] ?? '';
        if (!$rawBody) {
            error_log("Stripe: missing raw body for signature verification");
            return false;
        }

        // Reconstruct the signed content: "{timestamp}.{raw_body}"
        // Using the EXACT raw bytes from the request for security
        $signedContent = "{$timestamp}.{$rawBody}";

        // Compute HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $signedContent, $this->webhookSecret);

        // Use hash_equals to prevent timing attacks
        if (!hash_equals($expectedSignature, $signature)) {
            error_log("Stripe: signature mismatch (invalid webhook or tampered data)");
            return false;
        }

        return true;
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
            // In production, call Stripe API:
            // $refund = \Stripe\Refund::create([
            //     'charge' => $externalTransactionId,
            //     'amount' => (int)($amount * 100), // Convert to cents
            //     'reason' => $reason,
            //     'metadata' => ['refund_reason' => $reason]
            // ]);
            //
            // return [
            //     'status' => 'success',
            //     'external_refund_id' => $refund->id
            // ];

            // For now, return mock success
            return [
                'status' => 'success',
                'external_refund_id' => 're_test_' . bin2hex(random_bytes(8))
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
}

