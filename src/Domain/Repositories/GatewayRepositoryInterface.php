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

    /** Active PLATFORM gateway of a provider (findAll/findAllEnabled are platform-only too). */
    public function findByProvider(string $provider): ?Gateway;

    /**
     * Gateways of an account (null = platform), active or not.
     *
     * @return Gateway[]
     */
    public function findForAccount(?string $accountRef): array;

    /** Gateway of a provider for an account (null = platform), optionally only if active. */
    public function findByProviderForAccount(string $provider, ?string $accountRef, bool $activeOnly = true): ?Gateway;
}
