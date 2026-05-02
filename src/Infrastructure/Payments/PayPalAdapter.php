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
        private readonly bool   $sandbox = false
    ) {}

    /**
     * Create a PayPal checkout session (billing agreement setup).
     *
     * Uses PayPal credentials injected via constructor ($clientId, $clientSecret, $webhookId).
     * If plan has trial_days, include it in the setup.
     *
     * @param Order $order The order to create checkout for
     * @return array ['checkout_url' => string, 'external_id' => string]
     */
    public function createCheckoutSession(Order $order): array
    {
        // In production, call PayPal API with $this->clientId and $this->clientSecret
        // Create a billing agreement or subscription on api-m.sandbox.paypal.com or api-m.paypal.com
        // based on $this->sandbox flag

        // Mock response
        return [
            'checkout_url' => "https://www.paypal.com/checkoutnow?token=" . bin2hex(random_bytes(10)),
            'external_id' => "PAYID-" . bin2hex(random_bytes(10))
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

        // Guard against replay attacks: timestamp must be recent
        try {
            $eventTime = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $transmissionTime);
            $now = new \DateTimeImmutable();
            $maxAge = 300; // 5 minutes

            if ($eventTime && $now->getTimestamp() - $eventTime->getTimestamp() > $maxAge) {
                error_log("PayPal: webhook timestamp outside acceptable range (possible replay attack)");
                return false;
            }
        } catch (\Exception $e) {
            error_log("PayPal: invalid transmission time format: {$transmissionTime}");
            return false;
        }

        // Verify cert URL domain (security check)
        $certHost = parse_url($certUrl, PHP_URL_HOST);
        if (!in_array($certHost, ['api.paypal.com', 'api.sandbox.paypal.com'], true)) {
            error_log("PayPal: cert URL has invalid domain: {$certHost}");
            return false;
        }

        // Fetch and verify certificate
        try {
            // In production, cache the certificate
            $certPem = @file_get_contents($certUrl);
            if (!$certPem) {
                error_log("PayPal: failed to fetch certificate from {$certUrl}");
                return false;
            }

            // Create verification string
            $verifyString = "{$transmissionId}|{$transmissionTime}|" . json_encode($payload) . "|{$authAlgo}";

            // Extract public key from certificate
            $cert = openssl_x509_read($certPem);
            if (!$cert) {
                error_log("PayPal: invalid certificate format");
                return false;
            }

            $pubKey = openssl_pkey_get_public($cert);
            if (!$pubKey) {
                error_log("PayPal: failed to extract public key from certificate");
                return false;
            }

            // Get the raw body from headers (passed by WebhookRequestHandler via AseManager)
            $rawBody = $headers['X-RAW-BODY'] ?? '';
            if (!$rawBody) {
                error_log("PayPal: missing raw body for signature verification");
                return false;
            }

            // Verify signature using OpenSSL
            // Signature should be base64 encoded
            $signatureBytes = base64_decode($signature);
            $result = openssl_verify(
                $verifyString,
                $signatureBytes,
                $pubKey,
                OPENSSL_ALGO_SHA256
            );

            if ($result === 1) {
                return true;
            } elseif ($result === 0) {
                error_log("PayPal: signature verification failed");
                return false;
            } else {
                error_log("PayPal: signature verification error");
                return false;
            }
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
            'amount' => (float)($resource['amount']['total'] ?? $resource['gross_amount']['value'] ?? 0),
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
            // In production, call PayPal Refund API:
            // POST /v2/payments/captures/{id}/refund or /v2/payments/sales/{id}/refund
            //
            // $response = $client->post(
            //     "/v2/payments/captures/{$externalTransactionId}/refund",
            //     [
            //         'amount' => ['currency_code' => 'USD', 'value' => $amount],
            //         'note_to_payer' => $reason
            //     ]
            // );
            //
            // return [
            //     'status' => 'success',
            //     'external_refund_id' => $response['id']
            // ];

            // For now, return mock success
            return [
                'status' => 'success',
                'external_refund_id' => 'REFUND-' . bin2hex(random_bytes(8))
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
}

