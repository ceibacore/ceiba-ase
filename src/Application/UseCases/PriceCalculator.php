<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\Repositories\CustomPriceRepositoryInterface;

final class PriceCalculator
{
    public function __construct(
        private readonly CustomPriceRepositoryInterface $customPriceRepo
    ) {}

    public function calculate(string $clientId, PlanPrice $planPrice): Money
    {
        $customPrice = $this->customPriceRepo->findByClientAndPlanPrice($clientId, $planPrice->id());

        if ($customPrice !== null) {
            $isValid = $customPrice->valid_until === null || $customPrice->valid_until > new \DateTimeImmutable();
            if ($isValid) {
                return Money::create($customPrice->amount, $planPrice->price()->currency());
            }
        }

        return $planPrice->price();
    }
}
