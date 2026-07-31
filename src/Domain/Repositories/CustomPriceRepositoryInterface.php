<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\ValueObjects\EntityId;

interface CustomPriceRepositoryInterface
{
    public function findByClientAndPlanPrice(string $clientId, EntityId $planPriceId): ?object;
}
