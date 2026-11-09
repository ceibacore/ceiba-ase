<?php

declare(strict_types=1);

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\Gateways\Chargeable;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Domain\ValueObjects\Money;

/**
 * A one-time payment requested by a host application (context + reference), charged
 * through the gateway of an account (platform or academy).
 */
final class Payment extends AggregateRoot implements Chargeable
{
    public const STATUSES = ['pending', 'paid', 'failed', 'expired', 'refunded', 'partially_refunded', 'charged_back'];

    public function __construct(
        EntityId $id,
        private string $context,
        private string $reference,
        private ?string $accountRef,
        private string $externalClientId,
        private EntityId $gatewayId,
        private string $provider,
        private Money $amount,
        private string $description,
        private string $status = 'pending',
        private ?string $externalId = null,
        private ?string $externalPaymentId = null,
        private int $refundedMinor = 0,
        private array $metadata = [],
        private ?\DateTimeImmutable $paidAt = null,
        private ?\DateTimeImmutable $createdAt = null,
    ) {
        parent::__construct($id);
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function context(): string { return $this->context; }
    public function reference(): string { return $this->reference; }
    public function accountRef(): ?string { return $this->accountRef; }
    public function externalClientId(): string { return $this->externalClientId; }
    public function gatewayId(): EntityId { return $this->gatewayId; }
    public function provider(): string { return $this->provider; }
    public function amount(): Money { return $this->amount; }
    public function description(): string { return $this->description; }
    public function status(): string { return $this->status; }
    public function externalId(): ?string { return $this->externalId; }
    public function externalPaymentId(): ?string { return $this->externalPaymentId; }
    public function refundedMinor(): int { return $this->refundedMinor; }
    public function metadata(): array { return $this->metadata; }
    public function paidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function createdAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function currency(): string
    {
        return strtoupper($this->amount->currency()->toString());
    }

    public function amountMinor(): int
    {
        return MinorUnits::toMinor($this->amount->amount(), $this->currency());
    }

    public function attachSession(string $externalId): void
    {
        $this->externalId = $externalId;
    }

    public function markPaid(?string $externalPaymentId, \DateTimeImmutable $at): void
    {
        $this->status = 'paid';
        $this->externalPaymentId = $externalPaymentId ?: $this->externalPaymentId;
        $this->paidAt = $at;
    }

    public function markFailed(): void
    {
        if ($this->status === 'pending') {
            $this->status = 'failed';
        }
    }

    /** A pending payment nobody completed in time. A late confirmation can still settle it. */
    public function markExpired(): void
    {
        if ($this->status === 'pending') {
            $this->status = 'expired';
        }
    }

    /** The cardholder won a dispute: the provider took the money back. */
    public function markChargedBack(): void
    {
        $this->status = 'charged_back';
    }

    /** Replaces the metadata (dispute and refund bookkeeping). */
    public function replaceMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /** Adds a refund; returns true when the payment is now fully refunded. */
    public function addRefund(int $refundedMinor, bool $full): bool
    {
        $this->refundedMinor = $full ? $this->amountMinor() : min($this->amountMinor(), $this->refundedMinor + max(0, $refundedMinor));
        $isFull = $this->refundedMinor >= $this->amountMinor();
        $this->status = $isFull ? 'refunded' : 'partially_refunded';

        return $isFull;
    }

    /** Public view for host applications (no credentials, amounts in minor units). */
    public function toArray(): array
    {
        return [
            'id' => $this->id()->uuid(),
            'context' => $this->context,
            'reference' => $this->reference,
            'account_ref' => $this->accountRef,
            'external_client_id' => $this->externalClientId,
            'gateway_id' => $this->gatewayId->uuid(),
            'provider' => $this->provider,
            'amount' => $this->amount->amount(),
            'amount_minor' => $this->amountMinor(),
            'currency' => $this->currency(),
            'description' => $this->description,
            'status' => $this->status,
            'external_id' => $this->externalId,
            'external_payment_id' => $this->externalPaymentId,
            'refunded_minor' => $this->refundedMinor,
            'metadata' => $this->metadata,
            'paid_at' => $this->paidAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
        ];
    }
}
