<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\Infrastructure\Payments\Paystack\PaystackClient;
use LemurAse\Infrastructure\Payments\Paystack\PaystackEvents;
use LemurAse\Infrastructure\Payments\Paystack\PaystackSignature;
use LemurAse\WebhookManagement\Domain\WebhookEvent;
use LemurAse\WebhookManagement\Domain\WebhookSignatureException;

/** Paystack webhooks: verified over the raw body with the secret key, then normalized. Config: secret_key. */
final class PaystackWebhookAdapter implements WebhookAdapterInterface
{
    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        $secretKey = (string) ($config['secret_key'] ?? '');
        try {
            PaystackSignature::verify($payload, $headers, $secretKey);
        } catch (\InvalidArgumentException $e) {
            throw new WebhookSignatureException($e->getMessage(), 0, $e);
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \Exception('Invalid JSON payload');
        }

        $normalized = PaystackEvents::normalize($decoded, new PaystackClient($secretKey));

        return new WebhookEvent(
            action:     $normalized['action'],
            data:       $normalized['data'],
            externalId: $normalized['external_id'],
            provider:   'paystack',
            rawPayload: $decoded,
        );
    }
}
