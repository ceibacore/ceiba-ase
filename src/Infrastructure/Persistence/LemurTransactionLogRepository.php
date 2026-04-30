<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Repositories\TransactionLogRepositoryInterface;
use LemurAse\Shared\LemurInstance;

final class LemurTransactionLogRepository implements TransactionLogRepositoryInterface
{
    public function log(array $data): void
    {
        $db = LemurInstance::get();
        $db->query(TableNames::TRANSACTIONS_LOG)->insert($data);
    }

    public function exists(string $externalTransactionId): bool
    {
        $db = LemurInstance::get();
        return (bool) $db->query(TableNames::TRANSACTIONS_LOG)
            ->where(['external_transaction_id' => $externalTransactionId])
            ->first();
    }
}
