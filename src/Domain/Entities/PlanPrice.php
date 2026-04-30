<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;

final class PlanPrice extends AggregateRoot
{
    public function __construct(
        EntityId $id,
        private EntityId $planId,
        private string $type, // recurring, one_time
        private Money $price,
        private ?string $interval = null,
        private int $intervalCount = 1,
        private int $trialDays = 0,
        private bool $isActive = true
    ) {
        parent::__construct($id);
    }

    public function planId(): EntityId { return $this->planId; }
    public function type(): string { return $this->type; }
    public function price(): Money { return $this->price; }
    public function interval(): ?string { return $this->interval; }
    public function intervalCount(): int { return $this->intervalCount; }
    public function trialDays(): int { return $this->trialDays; }
    public function isActive(): bool { return $this->isActive; }
}
