<?php

declare(strict_types=1);

namespace LemurAse\Domain\Gateways;

use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;

/**
 * What a gateway needs to charge: subscription orders and one-time payments both are.
 * The id travels in the provider metadata and comes back in webhooks as internal_order_id.
 */
interface Chargeable
{
    public function id(): EntityId;

    public function amount(): Money;

    public function externalClientId(): string;

    /** Shown to the payer at the provider. */
    public function description(): string;

    /** 'subscription' for plan orders; the host-defined context for payments. */
    public function context(): string;

    /** Owner of the gateway credentials: null for the platform, 'tenant:{id}' for an academy. */
    public function accountRef(): ?string;
}
