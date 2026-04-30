<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;

final class Subscription extends AggregateRoot
{
    public function __construct(
        EntityId $id,
        private EntityId $orderId,
        private string $externalClientId,
        private EntityId $planPriceId,
        private EntityId $gatewayId,
        private string $status,
        private ?\DateTimeImmutable $currentPeriodStart = null,
        private ?\DateTimeImmutable $currentPeriodEnd = null,
        private ?\DateTimeImmutable $canceledAt = null,
        private ?string $externalSubscriptionId = null
    ) {
        parent::__construct($id);
    }

    public function orderId(): EntityId { return $this->orderId; }
    public function externalClientId(): string { return $this->externalClientId; }
    public function planPriceId(): EntityId { return $this->planPriceId; }
    public function gatewayId(): EntityId { return $this->gatewayId; }
    public function status(): string { return $this->status; }
    public function currentPeriodStart(): ?\DateTimeImmutable { return $this->currentPeriodStart; }
    public function currentPeriodEnd(): ?\DateTimeImmutable { return $this->currentPeriodEnd; }
    public function canceledAt(): ?\DateTimeImmutable { return $this->canceledAt; }
    public function externalSubscriptionId(): ?string { return $this->externalSubscriptionId; }
}
