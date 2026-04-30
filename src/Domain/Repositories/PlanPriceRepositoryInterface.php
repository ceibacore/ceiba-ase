<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\EntityId;

interface PlanPriceRepositoryInterface
{
    public function findById(EntityId $id): ?PlanPrice;
}
