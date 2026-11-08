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

    /** A copy with extra normalized fields (the event itself is immutable). */
    public function withData(array $extra): self
    {
        return new self($this->action, array_merge($this->data, $extra), $this->externalId, $this->provider, $this->rawPayload);
    }
}
