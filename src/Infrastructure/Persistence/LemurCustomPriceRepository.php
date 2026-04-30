<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\Repositories\CustomPriceRepositoryInterface;
use LemurAse\Shared\LemurInstance;
use LemurAse\Infrastructure\Persistence\TableNames;

final class LemurCustomPriceRepository implements CustomPriceRepositoryInterface
{
    public function findByClientAndPlanPrice(string $clientId, EntityId $planPriceId): ?object
    {
        $db = LemurInstance::get();
        $row = $db->query(TableNames::CUSTOM_PRICES)
            ->where([
                'external_client_id' => $clientId,
                'plan_price_id' => $planPriceId->uuid()
            ])
            ->first();

        if (!$row) return null;

        // Simple DTO-like object for CustomPrice as it's small
        return (object)[
            'amount' => (float)$row['custom_amount'],
            'valid_until' => $row['valid_until'] ? new \DateTimeImmutable($row['valid_until']) : null
        ];
    }
}
