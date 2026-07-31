<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;

final class Gateway extends AggregateRoot
{
    public function __construct(
        EntityId $id,
        private string $provider,       // 'stripe' | 'paypal'
        private array  $credentials,    // decoded from JSON
        private bool   $isActive = true
    ) {
        parent::__construct($id);
    }

    public function provider(): string    { return $this->provider; }
    public function credentials(): array  { return $this->credentials; }
    public function isActive(): bool      { return $this->isActive; }
}
