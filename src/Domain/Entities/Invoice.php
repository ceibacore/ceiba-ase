<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;

final class Invoice extends AggregateRoot
{
    public function __construct(
        EntityId $id,
        private EntityId $orderId,
        private string $externalClientId,
        private string $invoiceNumber,
        private Money $total,
        private string $status,
        private ?EntityId $subscriptionId = null,
        private float $subtotal = 0,
        private float $taxAmount = 0,
        private ?\DateTimeImmutable $periodStart = null,
        private ?\DateTimeImmutable $periodEnd = null,
        private ?\DateTimeImmutable $issuedAt = null,
        private ?\DateTimeImmutable $dueAt = null,
        private ?\DateTimeImmutable $paidAt = null
    ) {
        parent::__construct($id);
    }

    public function orderId(): EntityId { return $this->orderId; }
    public function externalClientId(): string { return $this->externalClientId; }
    public function invoiceNumber(): string { return $this->invoiceNumber; }
    public function total(): Money { return $this->total; }
    public function status(): string { return $this->status; }
    public function subscriptionId(): ?EntityId { return $this->subscriptionId; }
    public function subtotal(): float { return $this->subtotal; }
    public function taxAmount(): float { return $this->taxAmount; }
    public function periodStart(): ?\DateTimeImmutable { return $this->periodStart; }
    public function periodEnd(): ?\DateTimeImmutable { return $this->periodEnd; }
    public function issuedAt(): ?\DateTimeImmutable { return $this->issuedAt; }
    public function dueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function paidAt(): ?\DateTimeImmutable { return $this->paidAt; }
}
