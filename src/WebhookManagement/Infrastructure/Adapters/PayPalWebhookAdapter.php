<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;

/**
 * PayPal webhook adapter.
 *
 * Validates PayPal transmission signatures and normalizes
 * the notification into a standard WebhookEvent.
 */
final class PayPalWebhookAdapter implements WebhookAdapterInterface
{
    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON payload');
        }

        $clientId = $config['client_id'] ?? '';
        $secret = $config['secret'] ?? '';
        $webhookId = $config['webhook_id'] ?? '';
        $sandbox = (bool)($config['sandbox'] ?? false);

        if (empty($clientId) || empty($secret) || empty($webhookId)) {
            throw new \Exception('PayPal webhook adapter: Missing client_id, secret, or webhook_id in configuration');
        }

        // Verify required headers
        $transmissionId = $headers['PAYPAL_TRANSMISSION_ID']
            ?? $headers['paypal-transmission-id']
            ?? $headers['Paypal-Transmission-Id']
            ?? '';
        $transmissionTime = $headers['PAYPAL_TRANSMISSION_TIME']
            ?? $headers['paypal-transmission-time']
            ?? $headers['Paypal-Transmission-Time']
            ?? '';
        $certUrl = $headers['PAYPAL_CERT_URL']
            ?? $headers['paypal-cert-url']
            ?? $headers['Paypal-Cert-Url']
            ?? '';
        $signature = $headers['PAYPAL_TRANSMISSION_SIG']
            ?? $headers['paypal-transmission-sig']
            ?? $headers['Paypal-Transmission-Sig']
            ?? '';
        $authAlgo = $headers['PAYPAL_AUTH_ALGO']
            ?? $headers['paypal-auth-algo']
            ?? $headers['Paypal-Auth-Algo']
            ?? 'SHA256withRSA';

        if (empty($transmissionId) || empty($transmissionTime) || empty($certUrl) || empty($signature)) {
            throw new \Exception('PayPal: missing required webhook signature headers');
        }

        // Obtain OAuth Access Token
        $baseUri = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $client = new \GuzzleHttp\Client(['base_uri' => $baseUri]);

        try {
            $tokenRes = $client->post('/v1/oauth2/token', [
                'auth' => [$clientId, $secret],
                'form_params' => ['grant_type' => 'client_credentials'],
                'headers' => ['Accept' => 'application/json', 'Accept-Language' => 'en_US']
            ]);
            $tokenData = json_decode((string)$tokenRes->getBody(), true);
            $accessToken = $tokenData['access_token'] ?? '';
        } catch (\Throwable $e) {
            throw new \Exception('PayPal: Failed to obtain access token: ' . $e->getMessage());
        }

        // Verify signature against PayPal API
        try {
            $verifyRes = $client->post('/v1/notifications/verify-webhook-signature', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'auth_algo' => $authAlgo,
                    'cert_url' => $certUrl,
                    'transmission_id' => $transmissionId,
                    'transmission_sig' => $signature,
                    'transmission_time' => $transmissionTime,
                    'webhook_id' => $webhookId,
                    'webhook_event' => $decoded
                ]
            ]);
            $verifyResult = json_decode((string)$verifyRes->getBody(), true);
            if (($verifyResult['verification_status'] ?? '') !== 'SUCCESS') {
                throw new \Exception('PayPal webhook signature verification failed');
            }
        } catch (\Throwable $e) {
            throw new \Exception('PayPal: signature verification exception: ' . $e->getMessage());
        }

        // Map events
        $eventType = $decoded['event_type'] ?? 'UNKNOWN';
        $resource = $decoded['resource'] ?? [];

        $action = match ($eventType) {
            'CHECKOUT.ORDER.APPROVED' => 'INITIAL_PAYMENT',
            'BILLING.SUBSCRIPTION.CREATED' => 'SUBSCRIPTION_CREATED',
            'BILLING.SUBSCRIPTION.ACTIVATED' => 'SUBSCRIPTION_ACTIVATED',
            'PAYMENT.SALE.COMPLETED' => 'RENEWAL_PAYMENT',
            'PAYMENT.SALE.DENIED' => 'PAYMENT_FAILED',
            'BILLING.SUBSCRIPTION.CANCELLED' => 'SUBSCRIPTION_CANCELED',
            'PAYMENT.SALE.REFUNDED' => 'REFUND_PROCESSED',
            default => 'UNKNOWN'
        };

        $normalizedData = [
            'external_subscription_id' => (string)($resource['billing_agreement_id'] ?? $resource['id'] ?? ''),
            'external_transaction_id'  => (string)($decoded['id'] ?? $resource['id'] ?? ''),
            'amount'                   => (float)($resource['amount']['total'] ?? $resource['gross_amount']['value'] ?? 0),
            'currency'                 => strtoupper($resource['amount']['currency'] ?? $resource['gross_amount']['currency_code'] ?? 'USD'),
            'internal_order_id'        => $resource['custom_id'] ?? $resource['custom'] ?? null,
            'external_client_id'       => $resource['custom_id'] ?? null,
            'raw_payload'              => $decoded,
        ];

        return new WebhookEvent(
            action:     $action,
            data:       $normalizedData,
            externalId: (string)($decoded['id'] ?? $resource['id'] ?? 'paypal_unknown'),
            provider:   'paypal',
            rawPayload: $decoded,
        );
    }
}