<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\Plan;
use LemurAse\Domain\Repositories\PlanRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;

final class CreatePlan
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepo
    ) {}

    /**
     * @param  string      $slug        Unique machine-readable slug (e.g. 'pro-monthly')
     * @param  string      $name        Human-readable name (e.g. 'Pro Monthly')
     * @param  string|null $description Optional description
     * @param  bool        $isActive    Whether the plan is immediately available
     * @param  array|null  $metadata    Custom flexible attributes (JSON)
     *                                  E.g.: ['is_recommended' => true, 'max_users' => 100, 'features' => [...]]
     * @return Plan                     The newly persisted plan
     * @throws \InvalidArgumentException If slug is already taken
     */
    public function execute(
        string  $slug,
        string  $name,
        ?string $description = null,
        bool    $isActive    = true,
        ?array  $metadata    = null
    ): Plan {
        if ($this->planRepo->existsBySlug($slug)) {
            throw new \InvalidArgumentException("Plan slug '{$slug}' already exists.");
        }

        $plan = new Plan(
            EntityId::generate(),
            $slug,
            $name,
            $description,
            $isActive,
            $metadata
        );

        $this->planRepo->save($plan);
        return $plan;
    }
}
