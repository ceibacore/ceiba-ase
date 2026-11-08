<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\Infrastructure\Payments\Razorpay\RazorpayEvents;
use LemurAse\Infrastructure\Payments\Razorpay\RazorpaySignature;
use LemurAse\WebhookManagement\Domain\WebhookEvent;
use LemurAse\WebhookManagement\Domain\WebhookSignatureException;

/** Razorpay webhooks: verified over the raw body with the webhook secret, then normalized. Config: webhook_secret. */
final class RazorpayWebhookAdapter implements WebhookAdapterInterface
{
    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        try {
            RazorpaySignature::verify($payload, $headers, (string) ($config['webhook_secret'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw new WebhookSignatureException($e->getMessage(), 0, $e);
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \Exception('Invalid JSON payload');
        }

        $normalized = RazorpayEvents::normalize($decoded, $headers);

        return new WebhookEvent(
            action:     $normalized['action'],
            data:       $normalized['data'],
            externalId: $normalized['external_id'],
            provider:   'razorpay',
            rawPayload: $decoded,
        );
    }
}
