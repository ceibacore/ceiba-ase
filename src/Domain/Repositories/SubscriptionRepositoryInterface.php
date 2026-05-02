<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\ValueObjects\EntityId;

interface SubscriptionRepositoryInterface
{
    public function save(Subscription $subscription): void;
    public function findById(EntityId $id): ?Subscription;
    public function findByExternalId(string $externalId): ?Subscription;
    public function findByOrderId(EntityId $orderId): ?Subscription;
    
    /**
     * @return Subscription[]
     */
    public function findByClientAndStatus(string $clientId, string $status): array;
}
