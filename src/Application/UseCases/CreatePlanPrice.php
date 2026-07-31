<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\Repositories\PlanRepositoryInterface;
use LemurAse\Domain\Repositories\PlanPriceRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

final class CreatePlanPrice
{
    public function __construct(
        private readonly PlanRepositoryInterface      $planRepo,
        private readonly PlanPriceRepositoryInterface $planPriceRepo
    ) {}

    /**
     * @param  string      $planId        UUID of the parent plan
     * @param  string      $type          'recurring' | 'one_time'
     * @param  float       $amount        Price amount (e.g. 9.99)
     * @param  string      $currency      ISO-4217 code (e.g. 'USD')
     * @param  string|null $interval      'day' | 'month' | 'year' (required if type=recurring)
     * @param  int         $intervalCount Billing cycle multiplier (default 1)
     * @param  int         $trialDays     Number of free trial days (default 0)
     * @return PlanPrice                  The newly persisted plan price
     * @throws \InvalidArgumentException  On validation failure
     */
    public function execute(
        string  $planId,
        string  $type,
        float   $amount,
        string  $currency,
        ?string $interval      = null,
        int     $intervalCount = 1,
        int     $trialDays     = 0
    ): PlanPrice {
        if (!in_array($type, ['recurring', 'one_time'], true)) {
            throw new \InvalidArgumentException("Invalid type '{$type}'. Must be 'recurring' or 'one_time'.");
        }

        if ($type === 'recurring' && !in_array($interval, ['day', 'month', 'year'], true)) {
            throw new \InvalidArgumentException("Recurring price requires interval: 'day', 'month', or 'year'.");
        }

        if (!$this->planRepo->findById(EntityId::fromString($planId))) {
            throw new \InvalidArgumentException("Plan '{$planId}' not found.");
        }

        $planPrice = new PlanPrice(
            EntityId::generate(),
            EntityId::fromString($planId),
            $type,
            Money::create($amount, Currency::fromString(strtoupper($currency))),
            $interval,
            max(1, $intervalCount),
            max(0, $trialDays),
            true
        );

        $this->planPriceRepo->save($planPrice);
        return $planPrice;
    }
}
