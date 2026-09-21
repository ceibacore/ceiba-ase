<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\Plan;
use LemurAse\Domain\Repositories\PlanRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Shared\Infrastructure\CeibaInstance;

final class CeibaPlanRepository implements PlanRepositoryInterface
{
    public function save(Plan $plan): void
    {
        $db = CeibaInstance::get();
        $existing = $db->query(TableNames::PLANS)
            ->where(['id' => $plan->id()->uuid()])
            ->first();

        $data = [
            'id'          => $plan->id()->uuid(),
            'short_id'    => $plan->id()->short(),
            'slug'        => $plan->slug(),
            'name'        => $plan->name(),
            'description' => $plan->description(),
            'is_active'   => $plan->isActive() ? 1 : 0,
            'metadata'    => $plan->metadata() ? json_encode($plan->metadata()) : null,
        ];

        if ($existing) {
            unset($data['id'], $data['short_id']);   // do not overwrite PKs
            $db->query(TableNames::PLANS)
                ->where(['id' => $plan->id()->uuid()])
                ->update($data);
        } else {
            $db->query(TableNames::PLANS)->insert($data);
        }
    }

    public function findById(EntityId $id): ?Plan
    {
        $row = CeibaInstance::get()
            ->query(TableNames::PLANS)
            ->where(['id' => $id->uuid()])
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function findAll(bool $onlyActive = false): array
    {
        $q = CeibaInstance::get()->query(TableNames::PLANS);
        if ($onlyActive) {
            $q = $q->where(['is_active' => 1]);
        }
        return array_map([$this, 'hydrate'], $q->get());
    }

    public function findBySlug(string $slug): ?Plan
    {
        $row = CeibaInstance::get()
            ->query(TableNames::PLANS)
            ->where(['slug' => $slug])
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function existsBySlug(string $slug): bool
    {
        return (bool) CeibaInstance::get()
            ->query(TableNames::PLANS)
            ->where(['slug' => $slug])
            ->first();
    }

    private function hydrate(array $row): Plan
    {
        // Decode JSON metadata if present
        $metadata = null;
        if (!empty($row['metadata'])) {
            $decoded = json_decode($row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return new Plan(
            EntityId::fromString($row['id']),
            $row['slug'],
            $row['name'],
            $row['description'] ?? null,
            (bool) $row['is_active'],
            $metadata
        );
    }
}
