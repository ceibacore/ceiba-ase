<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;
use LemurAse\Infrastructure\Payments\MercadoPago\MercadoPagoSignature;

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

        // ── Validate HMAC signature and time window ─────────────
        $dataId = (string) ($decoded['data']['id'] ?? '');
        if ($dataId === '') {
            throw new \Exception('Payload missing data.id');
        }
        try {
            MercadoPagoSignature::verify(
                $headers, $dataId, (string) ($config['webhook_secret'] ?? ''),
                (int) ($config['signature_tolerance'] ?? MercadoPagoSignature::DEFAULT_TOLERANCE)
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage());
        }

        // ── Fetch full payment details ───────────────────────────
        $accessToken = $config['access_token']
            ?? throw new \Exception('Missing access_token in config');

        try {
            $client = \LemurAse\Infrastructure\Http\HttpClientFactory::create([
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
            // One payment produces several notifications (approved, later refunded): the
            // idempotency key must include the status or the refund would be discarded.
            externalId: sprintf('mercadopago:%s:%s', (string) ($payment['id'] ?? $dataId), $paymentStatus ?: 'unknown'),
            provider:   'mercadopago',
            rawPayload: $decoded,
        );
    }
}
