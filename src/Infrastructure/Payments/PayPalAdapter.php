<?php

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Order;

/**
 * PayPal payment gateway adapter.
 *
 * Implements checkout sessions, webhook validation (PayPal Webhook Signature Verification),
 * event parsing, and refunds.
 *
 * SECURITY: Webhook signature verification must use PayPal's certificate-based verification.
 */
final class PayPalAdapter implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $webhookId,
        private readonly bool $sandbox = false
    ) {
    }

    private function getAccessToken(): string
    {
        $client = new \GuzzleHttp\Client([
            'base_uri' => $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
        ]);
        $response = $client->post('/v1/oauth2/token', [
            'auth' => [$this->clientId, $this->clientSecret],
            'form_params' => ['grant_type' => 'client_credentials']
        ]);
        return json_decode((string) $response->getBody(), true)['access_token'];
    }

    /**
     * Create a PayPal checkout session (billing agreement setup or order).
     *
     * Uses PayPal credentials injected via constructor ($clientId, $clientSecret, $webhookId).
     *
     * @param Order $order The order to create checkout for
     * @return array ['checkout_url' => string, 'external_id' => string]
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        $token = $this->getAccessToken();
        $client = new \GuzzleHttp\Client([
            'base_uri' => $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
        ]);

        $response = $client->post('/v2/checkout/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'json' => [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'custom_id' => $order->id()->uuid(),
                        'amount' => [
                            'currency_code' => strtoupper($order->amount()->currency()->toString()),
                            'value' => (string) round($order->amount()->amount(), 2)
                        ]
                    ]
                ],
                'application_context' => [
                    'return_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                ]
            ]
        ]);

        $data = json_decode((string) $response->getBody(), true);

        $checkoutUrl = '';
        foreach ($data['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $checkoutUrl = $link['href'];
                break;
            }
        }

        return [
            'checkout_url' => $checkoutUrl,
            'external_id' => $data['id'] ?? null
        ];
    }

    /**
     * Validate PayPal webhook signature.
     *
     * PayPal uses certificate-based signature verification.
     * Transmit headers include: PAYPAL_TRANSMISSION_ID, PAYPAL_TRANSMISSION_TIME, 
     * PAYPAL_TRANSMISSION_SIG, PAYPAL_CERT_URL, PAYPAL_AUTH_ALGO
     *
     * CRITICAL SECURITY: Always verify the cert URL domain is api.paypal.com or api.sandbox.paypal.com
     *
     * @param array $payload The decoded webhook payload
     * @param array $headers The HTTP headers
     *
     * @return bool True if signature is valid, false otherwise
     */
    public function validateWebhook(array $payload, array $headers): bool
    {
        // Extract required headers
        $transmissionId = $headers['PAYPAL_TRANSMISSION_ID']
            ?? $headers['paypal-transmission-id']
            ?? '';
        $transmissionTime = $headers['PAYPAL_TRANSMISSION_TIME']
            ?? $headers['paypal-transmission-time']
            ?? '';
        $certUrl = $headers['PAYPAL_CERT_URL']
            ?? $headers['paypal-cert-url']
            ?? '';
        $signature = $headers['PAYPAL_TRANSMISSION_SIG']
            ?? $headers['paypal-transmission-sig']
            ?? '';
        $authAlgo = $headers['PAYPAL_AUTH_ALGO']
            ?? $headers['paypal-auth-algo']
            ?? 'SHA256withRSA';

        if (!$transmissionId || !$transmissionTime || !$certUrl || !$signature) {
            error_log("PayPal: missing required webhook headers");
            return false;
        }

        try {
            $token = $this->getAccessToken();
            $client = new \GuzzleHttp\Client([
                'base_uri' => $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
            ]);

            $verifyResponse = $client->post('/v1/notifications/verify-webhook-signature', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'auth_algo' => $authAlgo,
                    'cert_url' => $certUrl,
                    'transmission_id' => $transmissionId,
                    'transmission_sig' => $signature,
                    'transmission_time' => $transmissionTime,
                    'webhook_id' => $this->webhookId,
                    'webhook_event' => $payload
                ]
            ]);

            $result = json_decode((string) $verifyResponse->getBody(), true);

            if (($result['verification_status'] ?? '') === 'SUCCESS') {
                return true;
            }

            error_log("PayPal: signature verification failed via API. Result: " . json_encode($result));
            return false;

        } catch (\Exception $e) {
            error_log("PayPal: signature verification exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse a PayPal webhook event into normalized form.
     *
     * @param array $payload The decoded webhook payload
     * @return array Normalized event data
     */
    public function parseWebhookEvent(array $payload): array
    {
        $eventType = $payload['event_type'] ?? 'UNKNOWN';
        $resource = $payload['resource'] ?? [];

        $action = match ($eventType) {
            'CHECKOUT.ORDER.APPROVED' => 'INITIAL_PAYMENT',
            'BILLING.SUBSCRIPTION.CREATED' => 'SUBSCRIPTION_CREATED',
            'BILLING.SUBSCRIPTION.ACTIVATED' => 'SUBSCRIPTION_ACTIVATED',
            'PAYMENT.SALE.COMPLETED' => 'RENEWAL_PAYMENT',
            'PAYMENT.SALE.DENIED' => 'PAYMENT_FAILED',
            'BILLING.SUBSCRIPTION.CANCELLED' => 'SUBSCRIPTION_CANCELED',
            'PAYMENT.SALE.REFUNDED' => 'REFUND_PROCESSED',
            default => 'IGNORED'
        };

        return [
            'action' => $action,
            'external_order_id' => $resource['id'] ?? $payload['id'] ?? null,
            'external_subscription_id' => $resource['billing_agreement_id'] ?? $resource['id'] ?? null,
            'external_transaction_id' => $payload['id'] ?? null,
            'internal_order_id' => $resource['custom_id'] ?? $resource['custom'] ?? null,
            'amount' => (float) ($resource['amount']['total'] ?? $resource['gross_amount']['value'] ?? 0),
            'currency' => strtoupper($resource['amount']['currency'] ?? $resource['gross_amount']['currency_code'] ?? 'USD'),
            'status' => 'paid',
            'external_client_id' => $resource['custom_id'] ?? null,
            'full_refund' => $resource['state'] === 'refunded' || isset($resource['refund_id']),
            'raw_payload' => $payload
        ];
    }

    /**
     * Issue a refund for a PayPal sale/capture.
     *
     * @param string $externalTransactionId PayPal transaction/sale/capture ID
     * @param float  $amount                Amount to refund
     * @param string $reason                Reason for refund
     *
     * @return array ['status' => 'success'|'failed', 'external_refund_id' => string, 'error' => string|null]
     */
    public function refund(string $externalTransactionId, float $amount, string $reason): array
    {
        try {
            $token = $this->getAccessToken();
            $client = new \GuzzleHttp\Client([
                'base_uri' => $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
            ]);

            $response = $client->post("/v2/payments/captures/{$externalTransactionId}/refund", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'amount' => [
                        'value' => (string) round($amount, 2),
                        'currency_code' => 'USD'
                    ],
                    'note_to_payer' => $reason
                ]
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return [
                'status' => 'success',
                'external_refund_id' => $data['id'] ?? null
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch the current status of a PayPal transaction.
     */
    public function verifyTransaction(string $externalId): array
    {
        try {
            $token = $this->getAccessToken();
            $client = new \GuzzleHttp\Client([
                'base_uri' => $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
            ]);

            $response = $client->get("/v2/checkout/orders/{$externalId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ]
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (($data['status'] ?? '') === 'COMPLETED') {
                return [
                    'action' => 'INITIAL_PAYMENT',
                    'external_id' => $data['id'],
                    'external_transaction_id' => $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? null,
                    'amount' => (float) ($data['purchase_units'][0]['amount']['value'] ?? 0),
                    'currency' => $data['purchase_units'][0]['amount']['currency_code'] ?? 'USD',
                    'internal_order_id' => $data['purchase_units'][0]['custom_id'] ?? null,
                    'raw_payload' => $data
                ];
            }

            return ['action' => 'IGNORED', 'status' => $data['status']];
        } catch (\Exception $e) {
            return ['action' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): array
    {
        // PayPal Subscriptions API implementation would go here.
        // For now, return failed to indicate not yet implemented for PayPal.
        return ['status' => 'failed', 'error' => 'PayPal subscription cancellation not yet implemented.'];
    }

    public function pauseSubscription(string $externalId): array
    {
        return ['status' => 'failed', 'error' => 'PayPal subscription pausing not yet implemented.'];
    }
}
