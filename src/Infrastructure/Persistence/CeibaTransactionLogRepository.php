<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Repositories\TransactionLogRepositoryInterface;
use LemurAse\Shared\Infrastructure\CeibaInstance;

final class CeibaTransactionLogRepository implements TransactionLogRepositoryInterface
{
    public function log(array $data): void
    {
        $db = CeibaInstance::get();
        $db->query(TableNames::TRANSACTIONS_LOG)->insert($data);
    }

    public function exists(string $externalTransactionId): bool
    {
        $db = CeibaInstance::get();
        return (bool) $db->query(TableNames::TRANSACTIONS_LOG)
            ->where(['external_transaction_id' => $externalTransactionId])
            ->first();
    }
}
