<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\ValueObjects\EntityId;

interface TransactionLogRepositoryInterface
{
    public function log(array $data): void;
    public function exists(string $externalTransactionId): bool;
}
