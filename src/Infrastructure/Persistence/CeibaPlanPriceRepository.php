<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;
use LemurAse\Domain\Repositories\PlanPriceRepositoryInterface;
use LemurAse\Shared\Infrastructure\CeibaInstance;
use LemurAse\Infrastructure\Persistence\TableNames;

final class CeibaPlanPriceRepository implements PlanPriceRepositoryInterface
{
    public function save(PlanPrice $planPrice): void
    {
        $db = CeibaInstance::get();
        $existing = $db->query(TableNames::PLAN_PRICES)
            ->where(['id' => $planPrice->id()->uuid()])
            ->first();

        $data = [
            'id'             => $planPrice->id()->uuid(),
            'short_id'       => $planPrice->id()->short(),
            'plan_id'        => $planPrice->planId()->uuid(),
            'type'           => $planPrice->type(),
            'amount'         => $planPrice->price()->amount(),
            'currency'       => $planPrice->price()->currency()->toString(),
            'interval'       => $planPrice->interval(),
            'interval_count' => $planPrice->intervalCount(),
            'trial_days'     => $planPrice->trialDays(),
            'is_active'      => $planPrice->isActive() ? 1 : 0,
        ];

        if ($existing) {
            unset($data['id'], $data['short_id']);   // do not overwrite PKs
            $db->query(TableNames::PLAN_PRICES)
                ->where(['id' => $planPrice->id()->uuid()])
                ->update($data);
        } else {
            $db->query(TableNames::PLAN_PRICES)->insert($data);
        }
    }

    public function findById(EntityId $id): ?PlanPrice
    {
        $db = CeibaInstance::get();
        $row = $db->query(TableNames::PLAN_PRICES)->where(['id' => $id->uuid()])->first();

        if (!$row) return null;

        return $this->hydrate($row);
    }

    /**
     * Find all active prices for a specific plan.
     */
    public function findAllActiveByPlanId(EntityId $planId): array
    {
        $db = CeibaInstance::get();
        $rows = $db->query(TableNames::PLAN_PRICES)
            ->where([
                'plan_id'   => $planId->uuid(),
                'is_active' => 1
            ])
            ->get();

        return array_map([$this, 'hydrate'], $rows);
    }

    private function hydrate(array $row): PlanPrice
    {
        return new PlanPrice(
            EntityId::fromString($row['id']),
            EntityId::fromString($row['plan_id']),
            $row['type'],
            Money::create((float)$row['amount'], Currency::fromString($row['currency'])),
            $row['interval'] ?? null,
            (int)$row['interval_count'],
            (int)$row['trial_days'],
            (bool)$row['is_active']
        );
    }
}
