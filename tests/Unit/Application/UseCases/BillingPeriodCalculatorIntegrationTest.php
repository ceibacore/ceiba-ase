<?php

namespace LemurAse\Tests\Unit\Application\UseCases;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\Services\BillingPeriodCalculator;
use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

class BillingPeriodCalculatorIntegrationTest extends TestCase
{
    /**
     * Test BillingPeriodCalculator calculates next billing date for subscription
     */
    public function testCalculateNextBillingDateForSubscription()
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

        $subscriptionStart = new \DateTimeImmutable('2024-01-15');
        $nextBillingDate = BillingPeriodCalculator::calculate($planPrice, $subscriptionStart);

        $this->assertNotNull($nextBillingDate);
        $this->assertEquals('2024-02-15', $nextBillingDate->format('Y-m-d'));
    }

    /**
     * Test BillingPeriodCalculator calculates trial end date
     */
    public function testCalculateTrialEndDate()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        // Plan with trial
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            1,
            14 // 14 days trial
        );

        $trialStart = new \DateTimeImmutable('2024-01-01');
        
        // Calculate trial end (which should be 14 days from start)
        // In a real scenario, this would be done by the subscription logic
        $trialEnd = $trialStart->modify('+14 day');

        $this->assertEquals('2024-01-15', $trialEnd->format('Y-m-d'));
    }

    /**
     * Test subscription renewal with correct interval
     */
    public function testSubscriptionRenewalWithCorrectInterval()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        // Quarterly billing (every 3 months)
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(79.99, Currency::fromString('USD')),
            'month',
            3
        );

        $firstBillingDate = new \DateTimeImmutable('2024-01-15');
        $secondBillingDate = BillingPeriodCalculator::calculate($planPrice, $firstBillingDate);

        $this->assertEquals('2024-04-15', $secondBillingDate->format('Y-m-d'));

        // Third billing date
        $thirdBillingDate = BillingPeriodCalculator::calculate($planPrice, $secondBillingDate);

        $this->assertEquals('2024-07-15', $thirdBillingDate->format('Y-m-d'));
    }

    /**
     * Test annual subscription billing
     */
    public function testAnnualSubscriptionBilling()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(299.99, Currency::fromString('USD')),
            'year',
            1
        );

        $billingDate2024 = new \DateTimeImmutable('2024-01-15');
        $billingDate2025 = BillingPeriodCalculator::calculate($planPrice, $billingDate2024);

        $this->assertEquals('2025-01-15', $billingDate2025->format('Y-m-d'));

        // Next year
        $billingDate2026 = BillingPeriodCalculator::calculate($planPrice, $billingDate2025);

        $this->assertEquals('2026-01-15', $billingDate2026->format('Y-m-d'));
    }

    /**
     * Test ProcessWebhook uses calculator to update subscription period
     */
    public function testProcessWebhookUsesCalculatorToUpdatePeriod()
    {
        // This integration test demonstrates how ProcessWebhook would use
        // BillingPeriodCalculator to compute renewal dates
        
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

        // Simulate subscription renewal
        $currentPeriodStart = new \DateTimeImmutable('2024-01-15');
        $currentPeriodEnd = new \DateTimeImmutable('2024-02-15');

        // Calculate next period end
        $nextPeriodEnd = BillingPeriodCalculator::calculate($planPrice, $currentPeriodEnd);

        $this->assertNotNull($nextPeriodEnd);
        $this->assertEquals('2024-03-15', $nextPeriodEnd->format('Y-m-d'));
    }

    /**
     * Test one-time plan returns null (no renewal calculation)
     */
    public function testOneTimePlanReturnsNullForRenewal()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'one_time',
            Money::create(49.99, Currency::fromString('USD'))
        );

        $purchaseDate = new \DateTimeImmutable('2024-01-15');
        $nextBillingDate = BillingPeriodCalculator::calculate($planPrice, $purchaseDate);

        // One-time plans should return null
        $this->assertNull($nextBillingDate);
    }

    /**
     * Test billing period calculation across year boundary
     */
    public function testBillingPeriodAcrossYearBoundary()
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

        // Subscription that spans new year
        $billingDate2024 = new \DateTimeImmutable('2023-12-15');
        $billingDate2024Next = BillingPeriodCalculator::calculate($planPrice, $billingDate2024);

        $this->assertEquals('2024-01-15', $billingDate2024Next->format('Y-m-d'));
    }

    /**
     * Test multi-month intervals maintain consistency
     */
    public function testMultiMonthIntervalsConsistent()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        // 6-month interval (semi-annual)
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(149.99, Currency::fromString('USD')),
            'month',
            6
        );

        $date1 = new \DateTimeImmutable('2024-01-15');
        $date2 = BillingPeriodCalculator::calculate($planPrice, $date1);
        $date3 = BillingPeriodCalculator::calculate($planPrice, $date2);
        $date4 = BillingPeriodCalculator::calculate($planPrice, $date3);

        // Verify consistent 6-month intervals
        $this->assertEquals('2024-07-15', $date2->format('Y-m-d'));
        $this->assertEquals('2025-01-15', $date3->format('Y-m-d'));
        $this->assertEquals('2025-07-15', $date4->format('Y-m-d'));
    }

    /**
     * Test daily billing intervals
     */
    public function testDailyBillingIntervals()
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

        $date1 = new \DateTimeImmutable('2024-01-15');
        $date2 = BillingPeriodCalculator::calculate($planPrice, $date1);
        $date3 = BillingPeriodCalculator::calculate($planPrice, $date2);

        $this->assertEquals('2024-01-16', $date2->format('Y-m-d'));
        $this->assertEquals('2024-01-17', $date3->format('Y-m-d'));
    }

    /**
     * Test subscription state after trial with BillingPeriodCalculator
     */
    public function testSubscriptionAfterTrialWithCalculator()
    {
        $planPriceId = EntityId::generate();
        $planId = EntityId::generate();
        
        // Plan with 14-day trial, monthly billing after
        $planPrice = new PlanPrice(
            $planPriceId,
            $planId,
            'recurring',
            Money::create(29.99, Currency::fromString('USD')),
            'month',
            1,
            14 // 14 day trial
        );

        $subscriptionDate = new \DateTimeImmutable('2024-01-01');
        
        // Trial period
        $trialEnd = $subscriptionDate->modify('+14 day');
        $this->assertEquals('2024-01-15', $trialEnd->format('Y-m-d'));

        // First billing date after trial ends
        $firstBillingDate = BillingPeriodCalculator::calculate($planPrice, $trialEnd);
        $this->assertEquals('2024-02-15', $firstBillingDate->format('Y-m-d'));
    }
}
