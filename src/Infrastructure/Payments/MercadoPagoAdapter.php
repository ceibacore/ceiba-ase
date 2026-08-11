<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Order;

/**
 * Mercado Pago payment gateway adapter.
 *
 * Uses the Checkout Pro API (redirect-based) via REST.
 * Authentication: Bearer token with Access Token.
 * Webhook verification: HMAC-SHA256 via x-signature header.
 *
 * Supported regions: Argentina, Brasil, México, Colombia, Chile, Uruguay, Perú.
 */
final class MercadoPagoAdapter implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(
        private readonly string $accessToken,
        private readonly string $publicKey,
        private readonly string $webhookSecret,
        private readonly bool $sandbox = false
    ) {}

    /**
     * Create a Mercado Pago Checkout Pro preference.
     *
     * @param Order  $order      The order to create checkout for
     * @param string $successUrl URL to redirect on successful payment
     * @param string $cancelUrl  URL to redirect on failure/cancellation
     *
     * @return array ['checkout_url' => string, 'external_id' => string]
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);

        $response = $client->post('/checkout/preferences', [
            'headers' => [
                'Authorization'    => 'Bearer ' . $this->accessToken,
                'Content-Type'     => 'application/json',
                'X-Idempotency-Key' => $order->id()->uuid(),
            ],
            'json' => [
                'items' => [[
                    'title'       => 'Order ' . $order->id()->uuid(),
                    'quantity'    => 1,
                    'unit_price'  => round($order->amount()->amount(), 2),
                    'currency_id' => strtoupper($order->amount()->currency()->toString()),
                ]],
                'back_urls' => [
                    'success' => $successUrl,
                    'failure' => $cancelUrl,
                    'pending' => $successUrl . '?status=pending',
                ],
                'auto_return'        => 'approved',
                'external_reference' => $order->id()->uuid(),
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        return [
            'checkout_url' => $data['init_point'] ?? null,
            'external_id'  => (string) ($data['id'] ?? ''),
        ];
    }

    /**
     * Validate Mercado Pago webhook signature using HMAC-SHA256.
     *
     * MP sends an x-signature header in format: ts=<timestamp>,v1=<hmac_hash>
     * The signed string is: id:<data.id>;request-id:<x-request-id>;ts:<timestamp>;
     *
     * @param array $payload The decoded webhook payload
     * @param array $headers The HTTP headers
     *
     * @return bool True if signature is valid
     */
    public function validateWebhook(array $payload, array $headers): bool
    {
        $xSignature = $headers['x-signature']
            ?? $headers['X-Signature']
            ?? $headers['X-SIGNATURE']
            ?? '';
        $xRequestId = $headers['x-request-id']
            ?? $headers['X-Request-Id']
            ?? $headers['X-REQUEST-ID']
            ?? '';

        if (empty($xSignature) || empty($xRequestId)) {
            error_log('MercadoPago: missing x-signature or x-request-id headers');
            return false;
        }

        // Parse ts and v1 from x-signature (format: ts=xxx,v1=yyy)
        $ts = '';
        $v1 = '';
        $parts = explode(',', $xSignature);
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                if ($kv[0] === 'ts') {
                    $ts = $kv[1];
                } elseif ($kv[0] === 'v1') {
                    $v1 = $kv[1];
                }
            }
        }

        if (empty($ts) || empty($v1)) {
            error_log('MercadoPago: x-signature missing ts or v1 components');
            return false;
        }

        $dataId = (string) ($payload['data']['id'] ?? '');
        if (empty($dataId)) {
            error_log('MercadoPago: payload missing data.id for signature verification');
            return false;
        }

        // Build the signed string
        $signedString = sprintf('id:%s;request-id:%s;ts:%s;', $dataId, $xRequestId, $ts);
        $computed = hash_hmac('sha256', $signedString, $this->webhookSecret);

        return hash_equals($computed, $v1);
    }

    /**
     * Parse a Mercado Pago webhook event into normalized form.
     *
     * MP webhooks send { "action": "payment.created", "data": { "id": "123" } }.
     * We must fetch the full payment details via GET /v1/payments/{id}.
     *
     * @param array $payload The decoded webhook payload
     * @return array Normalized event data
     */
    public function parseWebhookEvent(array $payload): array
    {
        $dataId = $payload['data']['id'] ?? null;

        if (!$dataId) {
            return [
                'action'      => 'IGNORED',
                'raw_payload' => $payload,
            ];
        }

        // Fetch full payment details from MP API
        try {
            $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);
            $response = $client->get("/v1/payments/{$dataId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
            ]);
            $payment = json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            error_log('MercadoPago: failed to fetch payment details: ' . $e->getMessage());
            return [
                'action'      => 'IGNORED',
                'error'       => $e->getMessage(),
                'raw_payload' => $payload,
            ];
        }

        $status = $payment['status'] ?? '';

        $action = match ($status) {
            'approved'              => 'INITIAL_PAYMENT',
            'refunded'              => 'REFUND_PROCESSED',
            'rejected', 'cancelled' => 'PAYMENT_FAILED',
            default                 => 'IGNORED',
        };

        return [
            'action'                   => $action,
            'external_order_id'        => (string) ($payment['id'] ?? ''),
            'external_subscription_id' => (string) ($payment['id'] ?? ''),
            'external_transaction_id'  => (string) ($payment['id'] ?? ''),
            'internal_order_id'        => $payment['external_reference'] ?? null,
            'amount'                   => (float) ($payment['transaction_amount'] ?? 0),
            'currency'                 => strtoupper($payment['currency_id'] ?? 'USD'),
            'status'                   => 'paid',
            'external_client_id'       => $payment['external_reference'] ?? null,
            'trial_end'                => null,
            'full_refund'              => ($action === 'REFUND_PROCESSED'),
            'raw_payload'              => $payload,
        ];
    }

    /**
     * Issue a refund for a Mercado Pago payment.
     *
     * @param string $externalTransactionId MP payment ID
     * @param float  $amount                Amount to refund (0 = full refund)
     * @param string $reason                Reason for refund
     *
     * @return array ['status' => 'success'|'failed', 'external_refund_id' => string|null]
     */
    public function refund(string $externalTransactionId, float $amount, string $reason): array
    {
        try {
            $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);

            $options = [
                'headers' => [
                    'Authorization'     => 'Bearer ' . $this->accessToken,
                    'Content-Type'      => 'application/json',
                    'X-Idempotency-Key' => 'refund_' . $externalTransactionId . '_' . bin2hex(random_bytes(4)),
                ],
            ];

            // Partial refund: send amount; Total refund: empty body
            if ($amount > 0) {
                $options['json'] = ['amount' => round($amount, 2)];
            }

            $response = $client->post("/v1/payments/{$externalTransactionId}/refunds", $options);
            $data = json_decode((string) $response->getBody(), true);

            return [
                'status'             => 'success',
                'external_refund_id' => (string) ($data['id'] ?? ''),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch the current status of a Mercado Pago payment.
     */
    public function verifyTransaction(string $externalId): array
    {
        try {
            $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);
            $response = $client->get("/v1/payments/{$externalId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
            ]);
            $payment = json_decode((string) $response->getBody(), true);

            if (($payment['status'] ?? '') === 'approved') {
                return [
                    'action'                   => 'INITIAL_PAYMENT',
                    'external_id'              => (string) ($payment['id'] ?? ''),
                    'external_subscription_id' => (string) ($payment['id'] ?? ''),
                    'external_transaction_id'  => (string) ($payment['id'] ?? ''),
                    'amount'                   => (float) ($payment['transaction_amount'] ?? 0),
                    'currency'                 => strtoupper($payment['currency_id'] ?? 'USD'),
                    'external_client_id'       => $payment['external_reference'] ?? null,
                    'internal_order_id'        => $payment['external_reference'] ?? null,
                    'raw_payload'              => $payment,
                ];
            }

            return ['action' => 'IGNORED', 'status' => $payment['status'] ?? 'unknown'];
        } catch (\Exception $e) {
            return ['action' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    /**
     * Cancel a Mercado Pago subscription (preapproval).
     */
    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): array
    {
        try {
            $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);
            $client->put("/preapproval/{$externalId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['status' => 'cancelled'],
            ]);

            return ['status' => 'success'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Pause a Mercado Pago subscription (preapproval).
     */
    public function pauseSubscription(string $externalId): array
    {
        try {
            $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);
            $client->put("/preapproval/{$externalId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['status' => 'paused'],
            ]);

            return ['status' => 'success'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}
