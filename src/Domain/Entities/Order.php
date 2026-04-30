<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;

final class Order extends AggregateRoot
{
    public function __construct(
        EntityId $id,
        private string $externalClientId,
        private EntityId $planPriceId,
        private EntityId $gatewayId,
        private Money $amount,
        private string $securityHash,
        private string $status = 'pending',
        private ?EntityId $customPriceId = null,
        private ?\DateTimeImmutable $expiresAt = null,
        private ?string $externalOrderId = null
    ) {
        parent::__construct($id);
    }

    public function externalClientId(): string { return $this->externalClientId; }
    public function planPriceId(): EntityId { return $this->planPriceId; }
    public function gatewayId(): EntityId { return $this->gatewayId; }
    public function amount(): Money { return $this->amount; }
    public function securityHash(): string { return $this->securityHash; }
    public function status(): string { return $this->status; }
    public function customPriceId(): ?EntityId { return $this->customPriceId; }
    public function expiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function externalOrderId(): ?string { return $this->externalOrderId; }

    public function markAsPaid(): void { $this->status = 'paid'; }
    public function markAsFailed(): void { $this->status = 'failed'; }
    public function markAsExpired(): void { $this->status = 'expired'; }
}
