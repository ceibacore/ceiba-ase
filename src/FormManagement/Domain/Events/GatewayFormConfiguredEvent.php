<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Domain\Events;

use LemurAse\Shared\Domain\Events\DomainEvent;

/**
 * Fired when an admin successfully saves or updates a gateway configuration form.
 *
 * Listeners can react to this event to:
 *  - Encrypt and persist the credentials
 *  - Invalidate cached gateway configurations
 *  - Notify the system that a new gateway is now available
 */
final class GatewayFormConfiguredEvent implements DomainEvent
{
    private readonly \DateTimeImmutable $occurredOn;

    public function __construct(
        private readonly string $provider,
        private readonly array  $credentials,
        private readonly bool   $isActive,
        private readonly string $configuredBy,   // userId
    ) {
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'gateway_form.configured';
    }

    public function payload(): array
    {
        return [
            'provider'      => $this->provider,
            'is_active'     => $this->isActive,
            'configured_by' => $this->configuredBy,
            // credentials intentionally omitted from payload for security
        ];
    }

    public function occurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    // ── Typed accessors (for listeners that need the raw data) ──────────────

    public function provider(): string  { return $this->provider; }
    public function credentials(): array { return $this->credentials; }
    public function isActive(): bool    { return $this->isActive; }
    public function configuredBy(): string { return $this->configuredBy; }
}
