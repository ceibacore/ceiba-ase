<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\ValueObjects\EntityId;

interface OrderRepositoryInterface
{
    public function save(Order $order): void;
    public function findById(EntityId $id): ?Order;
    public function findExpired(\DateTimeImmutable $now): array;
    public function findByExternalTransactionId(string $externalTxId): ?Order;
}
