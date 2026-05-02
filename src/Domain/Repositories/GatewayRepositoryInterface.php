<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\ValueObjects\EntityId;

interface GatewayRepositoryInterface
{
    public function save(Gateway $gateway): void;

    public function findById(EntityId $id): ?Gateway;

    /**
     * Find all gateways (enabled or disabled).
     * 
     * @return Gateway[]
     */
    public function findAll(): array;

    /**
     * Find all ENABLED gateways only.
     * 
     * @return Gateway[]
     */
    public function findAllEnabled(): array;

    public function findByProvider(string $provider): ?Gateway;
}
