<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;

/**
 * Mercado Pago webhook adapter.
 *
 * Validates HMAC-SHA256 signature from x-signature header,
 * fetches full payment details from the MP API,
 * and normalizes the event into a WebhookEvent.
 */
final class MercadoPagoWebhookAdapter implements WebhookAdapterInterface
{
    /**
     * Parse and validate a Mercado Pago webhook notification.
     *
     * @param string $payload Raw JSON body
     * @param array  $headers HTTP headers
     * @param array  $config  Gateway credentials ['access_token', 'webhook_secret']
     *
     * @return WebhookEvent
     * @throws \Exception If signature is invalid or payload is malformed
     */
    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON payload');
        }

        // ── Validate HMAC signature ──────────────────────────────
        $xSignature = $headers['x-signature']
            ?? $headers['X-Signature']
            ?? $headers['X-SIGNATURE']
            ?? '';
        $xRequestId = $headers['x-request-id']
            ?? $headers['X-Request-Id']
            ?? $headers['X-REQUEST-ID']
            ?? '';

        if (empty($xSignature) || empty($xRequestId)) {
            throw new \Exception('Missing x-signature or x-request-id headers');
        }

        $webhookSecret = $config['webhook_secret']
            ?? throw new \Exception('Missing webhook_secret in config');

        // Parse ts and v1 from x-signature
        $ts = '';
        $v1 = '';
        foreach (explode(',', $xSignature) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                match ($kv[0]) {
                    'ts' => $ts = $kv[1],
                    'v1' => $v1 = $kv[1],
                    default => null,
                };
            }
        }

        if (empty($ts) || empty($v1)) {
            throw new \Exception('x-signature missing ts or v1 components');
        }

        $dataId = (string) ($decoded['data']['id'] ?? '');
        if (empty($dataId)) {
            throw new \Exception('Payload missing data.id');
        }

        $signedString = sprintf('id:%s;request-id:%s;ts:%s;', $dataId, $xRequestId, $ts);
        $computed = hash_hmac('sha256', $signedString, $webhookSecret);

        if (!hash_equals($computed, $v1)) {
            throw new \Exception('MercadoPago webhook signature verification failed');
        }

        // ── Fetch full payment details ───────────────────────────
        $accessToken = $config['access_token']
            ?? throw new \Exception('Missing access_token in config');

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.mercadopago.com',
            ]);
            $response = $client->get("/v1/payments/{$dataId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ],
            ]);
            $payment = json_decode((string) $response->getBody(), true);
        } catch (\Exception $e) {
            throw new \Exception('Failed to fetch payment from MercadoPago: ' . $e->getMessage());
        }

        // ── Map to normalized action ─────────────────────────────
        $paymentStatus = $payment['status'] ?? '';

        $action = match ($paymentStatus) {
            'approved'              => 'INITIAL_PAYMENT',
            'refunded'              => 'REFUND_PROCESSED',
            'rejected', 'cancelled' => 'PAYMENT_FAILED',
            default                 => 'UNKNOWN',
        };

        $normalizedData = [
            'external_subscription_id' => (string) ($payment['id'] ?? ''),
            'external_transaction_id'  => (string) ($payment['id'] ?? ''),
            'amount'                   => (float) ($payment['transaction_amount'] ?? 0),
            'currency'                 => strtoupper($payment['currency_id'] ?? 'USD'),
            'internal_order_id'        => $payment['external_reference'] ?? null,
            'external_client_id'       => $payment['external_reference'] ?? null,
            'raw_payload'              => $payment,
        ];

        return new WebhookEvent(
            action:     $action,
            data:       $normalizedData,
            externalId: (string) ($payment['id'] ?? 'mp_unknown'),
            provider:   'mercadopago',
            rawPayload: $decoded,
        );
    }
}
