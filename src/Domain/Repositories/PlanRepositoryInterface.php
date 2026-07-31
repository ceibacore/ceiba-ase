<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\Plan;
use LemurAse\Domain\ValueObjects\EntityId;

interface PlanRepositoryInterface
{
    public function save(Plan $plan): void;

    public function findById(EntityId $id): ?Plan;

    /** @return Plan[] */
    public function findAll(bool $onlyActive = false): array;

    public function findBySlug(string $slug): ?Plan;

    public function existsBySlug(string $slug): bool;
}
