<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\EntityId;

interface PlanPriceRepositoryInterface
{
    public function save(PlanPrice $planPrice): void;

    public function findById(EntityId $id): ?PlanPrice;
}
