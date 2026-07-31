<?php

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;

/**
 * Interface for gateway-specific webhook handlers.
 */
interface WebhookAdapterInterface
{
    /**
     * Validate the webhook signature and parse it into a normalized WebhookEvent.
     *
     * @param string $payload   The raw request body.
     * @param array  $headers   Request headers (e.g., Stripe-Signature).
     * @param array  $config    Gateway credentials/secrets.
     * 
     * @return WebhookEvent
     * @throws \Exception If signature is invalid or payload is malformed.
     */
    public function parse(string $payload, array $headers, array $config): WebhookEvent;
}
