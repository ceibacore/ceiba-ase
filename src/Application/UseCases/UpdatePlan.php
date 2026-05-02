<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\Plan;
use LemurAse\Domain\Repositories\PlanRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;

final class UpdatePlan
{
    public function __construct(
        private readonly PlanRepositoryInterface $planRepo
    ) {}

    /**
     * @param  string      $planId      UUID of the plan to update
     * @param  string|null $name        New name (null = keep existing)
     * @param  string|null $description New description (null = keep existing)
     * @param  bool|null   $isActive    New active state (null = keep existing)
     * @return Plan                     The updated plan
     * @throws \InvalidArgumentException If plan not found
     */
    public function execute(
        string  $planId,
        ?string $name        = null,
        ?string $description = null,
        ?bool   $isActive    = null
    ): Plan {
        $plan = $this->planRepo->findById(EntityId::fromString($planId));
        if (!$plan) {
            throw new \InvalidArgumentException("Plan '{$planId}' not found.");
        }

        $updated = new Plan(
            $plan->id(),
            $plan->slug(),                                // slug is immutable
            $name        ?? $plan->name(),
            $description ?? $plan->description(),
            $isActive    ?? $plan->isActive()
        );

        $this->planRepo->save($updated);
        return $updated;
    }
}
