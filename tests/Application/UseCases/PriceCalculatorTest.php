<?php

namespace LemurAse\Tests\Application\UseCases;

use PHPUnit\Framework\TestCase;
use LemurAse\Application\UseCases\PriceCalculator;
use LemurAse\Domain\Repositories\CustomPriceRepositoryInterface;
use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

class PriceCalculatorTest extends TestCase
{
    public function testReturnsBasePriceWhenNoCustomPriceExists()
    {
        $customPriceRepo = $this->createMock(CustomPriceRepositoryInterface::class);
        $customPriceRepo->method('findByClientAndPlanPrice')->willReturn(null);

        $calculator = new PriceCalculator($customPriceRepo);
        $planPriceId = EntityId::generate();
        $planPrice = new PlanPrice($planPriceId, EntityId::generate(), 'monthly', Money::create(29.99, Currency::fromString('USD')));

        $result = $calculator->calculate('client_1', $planPrice);

        $this->assertEquals(29.99, $result->amount());
    }

    public function testReturnsCustomPriceWhenValid()
    {
        $customPriceRepo = $this->createMock(CustomPriceRepositoryInterface::class);
        $customPriceRepo->method('findByClientAndPlanPrice')->willReturn((object)[
            'amount' => 15.00,
            'valid_until' => (new \DateTimeImmutable())->modify('+1 month')
        ]);

        $calculator = new PriceCalculator($customPriceRepo);
        $planPriceId = EntityId::generate();
        $planPrice = new PlanPrice($planPriceId, EntityId::generate(), 'monthly', Money::create(29.99, Currency::fromString('USD')));

        $result = $calculator->calculate('client_1', $planPrice);

        $this->assertEquals(15.00, $result->amount());
    }

    public function testReturnsBasePriceWhenCustomPriceIsExpired()
    {
        $customPriceRepo = $this->createMock(CustomPriceRepositoryInterface::class);
        $customPriceRepo->method('findByClientAndPlanPrice')->willReturn((object)[
            'amount' => 15.00,
            'valid_until' => (new \DateTimeImmutable())->modify('-1 month') // Expired
        ]);

        $calculator = new PriceCalculator($customPriceRepo);
        $planPriceId = EntityId::generate();
        $planPrice = new PlanPrice($planPriceId, EntityId::generate(), 'monthly', Money::create(29.99, Currency::fromString('USD')));

        $result = $calculator->calculate('client_1', $planPrice);

        $this->assertEquals(29.99, $result->amount());
    }
}
