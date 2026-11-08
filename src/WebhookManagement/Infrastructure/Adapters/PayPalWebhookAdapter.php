<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\Infrastructure\Payments\PayPal\PayPalOrders;
use LemurAse\Infrastructure\Payments\PayPal\PayPalWebhookGuard;
use LemurAse\WebhookManagement\Domain\WebhookEvent;

/**
 * PayPal webhook adapter.
 *
 * 1. Local pre-validation of transmission headers (certificate URL, algorithm,
 *    time window) before any network call.
 * 2. Remote signature verification through PayPal's verify-webhook-signature API.
 * 3. Approved orders are captured: approval alone does not move money, so only a
 *    COMPLETED capture becomes INITIAL_PAYMENT.
 *
 * Config: client_id, secret, webhook_id, sandbox, transmission_tolerance (seconds).
 */
final class PayPalWebhookAdapter implements WebhookAdapterInterface
{
    private const HEADERS = [
        'id'   => ['PAYPAL_TRANSMISSION_ID', 'paypal-transmission-id', 'Paypal-Transmission-Id'],
        'time' => ['PAYPAL_TRANSMISSION_TIME', 'paypal-transmission-time', 'Paypal-Transmission-Time'],
        'cert' => ['PAYPAL_CERT_URL', 'paypal-cert-url', 'Paypal-Cert-Url'],
        'sig'  => ['PAYPAL_TRANSMISSION_SIG', 'paypal-transmission-sig', 'Paypal-Transmission-Sig'],
        'algo' => ['PAYPAL_AUTH_ALGO', 'paypal-auth-algo', 'Paypal-Auth-Algo'],
    ];

    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \Exception('Invalid JSON payload');
        }

        $clientId = (string) ($config['client_id'] ?? '');
        $secret = (string) ($config['secret'] ?? '');
        $webhookId = (string) ($config['webhook_id'] ?? '');
        $sandbox = (bool) ($config['sandbox'] ?? false);
        if ($clientId === '' || $secret === '' || $webhookId === '') {
            throw new \Exception('PayPal webhook adapter: Missing client_id, secret, or webhook_id in configuration');
        }

        $transmissionId = self::header($headers, 'id');
        $transmissionTime = self::header($headers, 'time');
        $certUrl = self::header($headers, 'cert');
        $signature = self::header($headers, 'sig');
        $authAlgo = self::header($headers, 'algo') ?: 'SHA256withRSA';
        if ($transmissionId === '' || $transmissionTime === '' || $certUrl === '' || $signature === '') {
            throw new \Exception('PayPal: missing required webhook signature headers');
        }

        try {
            PayPalWebhookGuard::assertPlausible(
                $transmissionId, $transmissionTime, $certUrl, $authAlgo, $sandbox,
                (int) ($config['transmission_tolerance'] ?? PayPalWebhookGuard::DEFAULT_TOLERANCE)
            );
        } catch (\InvalidArgumentException $e) {
            throw new \Exception($e->getMessage());
        }

        $orders = new PayPalOrders($clientId, $secret, $sandbox);
        try {
            $accessToken = $orders->accessToken();
        } catch (\Throwable $e) {
            throw new \Exception('PayPal: Failed to obtain access token: ' . $e->getMessage());
        }

        try {
            $verifyRes = $orders->client()->post('/v1/notifications/verify-webhook-signature', [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Content-Type' => 'application/json'],
                'json' => [
                    'auth_algo'         => $authAlgo,
                    'cert_url'          => $certUrl,
                    'transmission_id'   => $transmissionId,
                    'transmission_sig'  => $signature,
                    'transmission_time' => $transmissionTime,
                    'webhook_id'        => $webhookId,
                    'webhook_event'     => $decoded,
                ],
            ]);
            $verifyResult = json_decode((string) $verifyRes->getBody(), true);
        } catch (\Throwable $e) {
            throw new \Exception('PayPal: signature verification exception: ' . $e->getMessage());
        }
        if (($verifyResult['verification_status'] ?? '') !== 'SUCCESS') {
            throw new \Exception('PayPal webhook signature verification failed');
        }

        $eventType = (string) ($decoded['event_type'] ?? 'UNKNOWN');
        $resource = is_array($decoded['resource'] ?? null) ? $decoded['resource'] : [];
        $eventId = (string) ($decoded['id'] ?? $resource['id'] ?? 'paypal_unknown');

        if ($eventType === 'CHECKOUT.ORDER.APPROVED') {
            return $this->approvedOrder($orders, $resource, $decoded, $eventId);
        }

        $action = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED'      => 'INITIAL_PAYMENT',
            'BILLING.SUBSCRIPTION.CREATED'   => 'SUBSCRIPTION_CREATED',
            'BILLING.SUBSCRIPTION.ACTIVATED' => 'SUBSCRIPTION_ACTIVATED',
            'PAYMENT.SALE.COMPLETED'         => 'RENEWAL_PAYMENT',
            'PAYMENT.SALE.DENIED'            => 'PAYMENT_FAILED',
            'BILLING.SUBSCRIPTION.CANCELLED' => 'SUBSCRIPTION_CANCELED',
            'PAYMENT.SALE.REFUNDED'          => 'REFUND_PROCESSED',
            default                          => 'UNKNOWN',
        };

        $normalizedData = [
            'external_subscription_id' => (string) ($resource['billing_agreement_id'] ?? $resource['id'] ?? ''),
            'external_transaction_id'  => (string) ($resource['id'] ?? $eventId),
            'amount'                   => (float) ($resource['amount']['value'] ?? $resource['amount']['total'] ?? $resource['gross_amount']['value'] ?? 0),
            'currency'                 => strtoupper((string) ($resource['amount']['currency_code'] ?? $resource['amount']['currency'] ?? $resource['gross_amount']['currency_code'] ?? 'USD')),
            'internal_order_id'        => $resource['custom_id'] ?? $resource['custom'] ?? null,
            'external_client_id'       => $resource['custom_id'] ?? null,
            'raw_payload'              => $decoded,
        ];

        return new WebhookEvent(action: $action, data: $normalizedData, externalId: $eventId,
            provider: 'paypal', rawPayload: $decoded);
    }

    /** Approval is not payment: capture the order and activate only a COMPLETED capture. */
    private function approvedOrder(PayPalOrders $orders, array $resource, array $decoded, string $eventId): WebhookEvent
    {
        $orderId = (string) ($resource['id'] ?? '');
        try {
            $capture = $orderId === '' ? null : $orders->capture($orderId);
        } catch (\Throwable $e) {
            throw new \Exception('PayPal: capture failed for approved order: ' . $e->getMessage());
        }
        if ($capture === null) {
            return new WebhookEvent(action: 'UNKNOWN', data: ['raw_payload' => $decoded], externalId: $eventId,
                provider: 'paypal', rawPayload: $decoded);
        }
        return new WebhookEvent(
            action: 'INITIAL_PAYMENT',
            data: [
                'external_subscription_id' => $capture['order_id'],
                'external_transaction_id'  => (string) ($capture['capture_id'] ?? $capture['order_id']),
                'amount'                   => $capture['amount'],
                'currency'                 => $capture['currency'],
                'internal_order_id'        => $capture['custom_id'],
                'external_client_id'       => null,
                'raw_payload'              => $decoded,
            ],
            externalId: $eventId,
            provider: 'paypal',
            rawPayload: $decoded,
        );
    }

    private static function header(array $headers, string $name): string
    {
        foreach (self::HEADERS[$name] as $key) {
            $value = $headers[$key] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }
}
