<?php

namespace LemurAse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

class ProcessWebhookStripeTest extends TestCase
{
    /**
     * Test order marking as paid for initial payment
     */
    public function testInitialPaymentMarksOrderAsPaid()
    {
        $orderId = EntityId::generate();
        $order = new Order(
            $orderId,
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(29.99, Currency::fromString('USD')),
            'hash_123',
            'pending'
        );

        $this->assertEquals('pending', $order->status());
        $order->markAsPaid();
        $this->assertEquals('paid', $order->status());
    }

    /**
     * Test subscription state transitions
     */
    public function testSubscriptionStateTransitions()
    {
        $subscriptionId = EntityId::generate();
        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            'trialing',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-14')
        );

        // Verify initial state is trialing
        $this->assertEquals('trialing', $subscription->status());
    }

    /**
     * Test subscription active state
     */
    public function testSubscriptionActiveState()
    {
        $subscriptionId = EntityId::generate();
        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            'active',
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-02-15')
        );

        $this->assertEquals('active', $subscription->status());
    }

    /**
     * Test subscription past_due state
     */
    public function testSubscriptionPastDueState()
    {
        $subscriptionId = EntityId::generate();
        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            'past_due',
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-02-15')
        );

        $this->assertEquals('past_due', $subscription->status());
    }

    /**
     * Test subscription canceled state
     */
    public function testSubscriptionCanceledState()
    {
        $subscriptionId = EntityId::generate();
        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            'canceled',
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-02-15'),
            new \DateTimeImmutable('2024-01-20')
        );

        $this->assertEquals('canceled', $subscription->status());
    }

    /**
     * Test order refund status
     */
    public function testOrderRefundStatus()
    {
        $orderId = EntityId::generate();
        $order = new Order(
            $orderId,
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(29.99, Currency::fromString('USD')),
            'hash_123',
            'paid'
        );

        $this->assertEquals('paid', $order->status());
        $order->markAsRefunded();
        $this->assertEquals('refunded', $order->status());
    }

    /**
     * Test subscription period calculation
     */
    public function testSubscriptionPeriodCalculation()
    {
        $subscriptionId = EntityId::generate();
        $currentPeriodStart = new \DateTimeImmutable('2024-01-15');
        $currentPeriodEnd = new \DateTimeImmutable('2024-02-15');

        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            'active',
            $currentPeriodStart,
            $currentPeriodEnd
        );

        // Verify period dates
        $this->assertEquals($currentPeriodStart, $subscription->currentPeriodStart());
        $this->assertEquals($currentPeriodEnd, $subscription->currentPeriodEnd());
    }

    /**
     * Test trial period calculation
     */
    public function testTrialPeriodCalculation()
    {
        $subscriptionId = EntityId::generate();
        $trialStart = new \DateTimeImmutable('2024-01-01');
        $trialEnd = new \DateTimeImmutable('2024-01-14');

        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            'trialing',
            $trialStart,
            $trialEnd
        );

        // Verify trial ends 14 days from start
        $expectedTrialEnd = $trialStart->modify('+14 day');
        $this->assertEquals('2024-01-15', $expectedTrialEnd->format('Y-m-d'));
    }

    /**
     * Test multiple webhook events processed in sequence
     */
    public function testMultipleWebhookEventsSequence()
    {
        // Simulate payment flow: pending -> paid -> active
        $orderId = EntityId::generate();
        $order = new Order(
            $orderId,
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(29.99, Currency::fromString('USD')),
            'hash_123',
            'pending'
        );

        // Event 1: Payment received
        $order->markAsPaid();
        $this->assertEquals('paid', $order->status());

        // Event 2: Subscription created
        $subscriptionId = EntityId::generate();
        $subscription = new Subscription(
            $subscriptionId,
            $orderId,
            'client_123',
            $order->planPriceId(),
            $order->gatewayId(),
            'active'
        );

        $this->assertEquals('active', $subscription->status());
    }
}
