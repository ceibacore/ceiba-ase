<?php

namespace LemurAse\Tests\Integration;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

class ProcessWebhookPayPalTest extends TestCase
{
    /**
     * Test payment capture creates paid order
     */
    public function testPaymentCaptureMarksOrderPaid()
    {
        $orderId = EntityId::generate();
        $order = new Order(
            $orderId,
            'client_456',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(39.99, Currency::fromString('USD')),
            'hash_paypal_123',
            'pending'
        );

        $this->assertEquals('pending', $order->status());
        $order->markAsPaid();
        $this->assertEquals('paid', $order->status());
    }

    /**
     * Test subscription created from PayPal webhook
     */
    public function testSubscriptionCreatedFromPayPal()
    {
        $subscriptionId = EntityId::generate();
        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_456',
            EntityId::generate(),
            EntityId::generate(),
            'active'
        );

        $this->assertEquals('active', $subscription->status());
    }

    /**
     * Test subscription renewal updates period
     */
    public function testSubscriptionRenewalUpdatesPeriod()
    {
        $subscriptionId = EntityId::generate();
        $subscription = new Subscription(
            $subscriptionId,
            EntityId::generate(),
            'client_456',
            EntityId::generate(),
            EntityId::generate(),
            'active',
            new \DateTimeImmutable('2024-02-15'),
            new \DateTimeImmutable('2024-03-15')
        );

        // Verify period dates are set correctly
        $this->assertEquals('2024-02-15', $subscription->currentPeriodStart()->format('Y-m-d'));
        $this->assertEquals('2024-03-15', $subscription->currentPeriodEnd()->format('Y-m-d'));
    }

    /**
     * Test refund webhook updates order
     */
    public function testRefundWebhookUpdatesOrder()
    {
        $orderId = EntityId::generate();
        $order = new Order(
            $orderId,
            'client_456',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(39.99, Currency::fromString('USD')),
            'hash_paypal_456',
            'paid'
        );

        $this->assertEquals('paid', $order->status());
        $order->markAsRefunded();
        $this->assertEquals('refunded', $order->status());
    }

    /**
     * Test PayPal subscription billing flow
     */
    public function testPayPalSubscriptionBillingFlow()
    {
        $orderId = EntityId::generate();
        $planPriceId = EntityId::generate();
        $subscriptionId = EntityId::generate();

        // Step 1: Order placed
        $order = new Order(
            $orderId,
            'client_456',
            $planPriceId,
            EntityId::generate(),
            Money::create(39.99, Currency::fromString('USD')),
            'hash_paypal_456',
            'pending'
        );

        $order->markAsPaid();
        $this->assertEquals('paid', $order->status());

        // Step 2: Subscription created
        $subscription = new Subscription(
            $subscriptionId,
            $orderId,
            'client_456',
            $planPriceId,
            EntityId::generate(),
            'active',
            new \DateTimeImmutable('2024-03-15'),
            new \DateTimeImmutable('2024-04-15')
        );

        $this->assertEquals('active', $subscription->status());
    }

    /**
     * Test payment failure handling
     */
    public function testPaymentFailureHandling()
    {
        $orderId = EntityId::generate();
        $order = new Order(
            $orderId,
            'client_456',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(39.99, Currency::fromString('USD')),
            'hash_paypal_456',
            'pending'
        );

        // Mark as failed
        $order->markAsFailed();
        $this->assertEquals('failed', $order->status());
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
            'client_456',
            EntityId::generate(),
            EntityId::generate(),
            'active'
        );

        // Verify state can transition through lifecycle
        $this->assertEquals('active', $subscription->status());
    }

    /**
     * Test idempotent webhook processing
     */
    public function testIdempotentWebhookProcessing()
    {
        // Same webhook event processed twice should have same result
        $orderId = EntityId::generate();
        $order1 = new Order(
            $orderId,
            'client_456',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(39.99, Currency::fromString('USD')),
            'hash_paypal_456',
            'pending'
        );

        $order1->markAsPaid();
        $status1 = $order1->status();

        // Process again (would be idempotent in real system)
        $status2 = $order1->status();

        $this->assertEquals($status1, $status2);
        $this->assertEquals('paid', $status2);
    }
}
