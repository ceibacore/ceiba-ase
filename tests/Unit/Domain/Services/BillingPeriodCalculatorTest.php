<?php

namespace LemurAse\Tests\Unit\Domain\Services;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\Services\BillingPeriodCalculator;
use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

class BillingPeriodCalculatorTest extends TestCase
{
    /**
     * Test monthly interval with count=1 (standard monthly)
     */
    public function testCalculateMonthlyIntervalSingleMonth()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            1 // 1 month interval
        );

        $fromDate = new \DateTimeImmutable('2024-01-15 10:30:00');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertEquals('2024-02-15 10:30:00', $result->format('Y-m-d H:i:s'));
    }

    /**
     * Test monthly interval with count=3 (quarterly billing)
     */
    public function testCalculateMonthlyIntervalTripleMonth()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(79.99, Currency::fromString('USD')),
            'month',
            3 // 3 month interval
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertEquals('2024-04-15', $result->format('Y-m-d'));
    }

    /**
     * Test monthly interval with count=6 (semi-annual)
     */
    public function testCalculateMonthlyIntervalSixMonth()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(149.99, Currency::fromString('USD')),
            'month',
            6
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertEquals('2024-07-15', $result->format('Y-m-d'));
    }

    /**
     * Test monthly interval with count=12 (annual)
     */
    public function testCalculateMonthlyIntervalTwelveMonth()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(299.99, Currency::fromString('USD')),
            'month',
            12
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertEquals('2025-01-15', $result->format('Y-m-d'));
    }

    /**
     * Test yearly interval
     */
    public function testCalculateYearlyInterval()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(999.99, Currency::fromString('USD')),
            'year',
            1
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertEquals('2025-01-15', $result->format('Y-m-d'));
    }

    /**
     * Test daily interval
     */
    public function testCalculateDailyInterval()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(0.99, Currency::fromString('USD')),
            'day',
            1
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertEquals('2024-01-16', $result->format('Y-m-d'));
    }

    /**
     * Test daily interval with count=7 (weekly)
     */
    public function testCalculateDailyIntervalWeekly()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(4.99, Currency::fromString('USD')),
            'day',
            7
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertEquals('2024-01-22', $result->format('Y-m-d'));
    }

    /**
     * Test one-time plan returns null (no renewal)
     */
    public function testCalculateOneTimePlanReturnsNull()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'one_time',
            Money::create(49.99, Currency::fromString('USD'))
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertNull($result);
    }

    /**
     * Test leap year edge case - Feb 29 should properly advance
     */
    public function testCalculateLeapYearFeb29PlusOneMonth()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            1
        );

        $fromDate = new \DateTimeImmutable('2024-02-29'); // Leap year
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        // PHP's modify() handles this gracefully - Feb 29 + 1 month = Mar 29
        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertEquals('2024-03-29', $result->format('Y-m-d'));
    }

    /**
     * Test invalid interval count throws exception
     */
    public function testCalculateInvalidIntervalCountThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid intervalCount');

        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        // Create a plan with manually set invalid interval count
        // Using reflection to set the property since class is final
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            0 // Invalid: must be >= 1
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        BillingPeriodCalculator::calculate($planPrice, $fromDate);
    }

    /**
     * Test unknown interval type throws exception
     */
    public function testCalculateUnknownIntervalThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown interval');

        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        // Use reflection to set invalid interval on the object
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'fortnight', // Invalid interval
            1
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        BillingPeriodCalculator::calculate($planPrice, $fromDate);
    }

    /**
     * Test recurring plan without interval throws exception
     */
    public function testCalculateRecurringWithoutIntervalThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no interval set');

        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        // Create recurring plan without interval
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD'))
            // No interval specified - should cause error
        );

        $fromDate = new \DateTimeImmutable('2024-01-15');
        BillingPeriodCalculator::calculate($planPrice, $fromDate);
    }

    /**
     * Test DST transition (spring forward) - UTC times should not be affected
     */
    public function testCalculateDSTTransitionSpringForward()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            1
        );

        // Use UTC to avoid DST issues
        $fromDate = new \DateTimeImmutable('2024-03-10 02:00:00', new \DateTimeZone('UTC'));
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertEquals('2024-04-10 02:00:00', $result->format('Y-m-d H:i:s'));
    }

    /**
     * Test DST transition (fall back) - UTC times should not be affected
     */
    public function testCalculateDSTTransitionFallBack()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            1
        );

        // Use UTC to avoid DST issues
        $fromDate = new \DateTimeImmutable('2024-11-03 02:00:00', new \DateTimeZone('UTC'));
        $result = BillingPeriodCalculator::calculate($planPrice, $fromDate);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertEquals('2024-12-03 02:00:00', $result->format('Y-m-d H:i:s'));
    }

    /**
     * Test immutability - original date should not be modified
     */
    public function testCalculateImmutability()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            1
        );

        $original = new \DateTimeImmutable('2024-01-15');
        $originalFormatted = $original->format('Y-m-d');

        $result = BillingPeriodCalculator::calculate($planPrice, $original);

        // Verify original date hasn't changed
        $this->assertEquals($originalFormatted, $original->format('Y-m-d'));
        // Verify result is different
        $this->assertNotEquals($originalFormatted, $result->format('Y-m-d'));
    }
}
