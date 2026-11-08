<?php

declare(strict_types=1);

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\Payment;
use LemurAse\Domain\ValueObjects\EntityId;

interface PaymentRepositoryInterface
{
    public function save(Payment $payment): void;

    public function findById(EntityId $id): ?Payment;

    /** By the provider's checkout id or by its charge/transaction id. */
    public function findByProviderReference(string $provider, string $reference): ?Payment;

    /** @return Payment[] newest first */
    public function findByReference(string $context, string $reference): array;
}
