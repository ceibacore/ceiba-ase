<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\ValueObjects\EntityId;

interface SubscriptionRepositoryInterface
{
    public function save(Subscription $subscription): void;
    public function findById(EntityId $id): ?Subscription;
    public function findByExternalId(string $externalId): ?Subscription;
}
