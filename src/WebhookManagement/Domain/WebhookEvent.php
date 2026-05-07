<?php

namespace LemurAse\WebhookManagement\Domain;

/**
 * Represents a normalized webhook event within ASE.
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $action,           // e.g., INITIAL_PAYMENT, RENEWAL_PAYMENT
        public readonly array $data,             // Normalized data fields
        public readonly string $externalId,      // External transaction or event ID (for idempotency)
        public readonly string $provider,        // 'stripe', 'paypal', etc.
        public readonly array $rawPayload        // Original JSON from provider
    ) {}
}
