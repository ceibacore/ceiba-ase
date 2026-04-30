<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;
use LemurAse\Domain\Repositories\PlanPriceRepositoryInterface;
use LemurAse\Shared\LemurInstance;
use LemurAse\Infrastructure\Persistence\TableNames;

final class LemurPlanPriceRepository implements PlanPriceRepositoryInterface
{
    public function findById(EntityId $id): ?PlanPrice
    {
        $db = LemurInstance::get();
        $row = $db->query(TableNames::PLAN_PRICES)->where(['id' => $id->uuid()])->first();

        if (!$row) return null;

        return new PlanPrice(
            EntityId::fromString($row['id']),
            EntityId::fromString($row['plan_id']),
            $row['type'],
            Money::create((float)$row['amount'], Currency::fromString($row['currency'])),
            $row['interval'],
            (int)$row['interval_count'],
            (int)$row['trial_days'],
            (bool)$row['is_active']
        );
    }
}
