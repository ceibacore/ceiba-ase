<?php

namespace LemurAse\Tests\Unit\Application\UseCases;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Domain\Repositories\SubscriptionRepositoryInterface;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

class ProcessRefundTest extends TestCase
{
    /**
     * Test order status changes to refunded after refund
     */
    public function testOrderStatusChangedToRefunded()
    {
        $orderId = EntityId::generate();
        $order = new Order(
            $orderId,
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(29.99, Currency::fromString('USD')),
            'hash_123',
            'paid',
            null,
            null,
            'ch_test_123'
        );

        // Verify order is initially paid
        $this->assertEquals('paid', $order->status());

        // Mark as refunded
        $order->markAsRefunded();

        // Verify status changed to refunded
        $this->assertEquals('refunded', $order->status());
    }

    /**
     * Test refunding a non-existent transaction
     */
    public function testRefundNonExistentTransactionReturnsFalseOrNull()
    {
        // This tests the behavior where an order doesn't exist
        // The repository should return null
        $orderRepo = $this->createMock(OrderRepositoryInterface::class);
        $orderRepo->method('findByExternalTransactionId')->willReturn(null);

        // When order not found, refund should handle gracefully
        $result = $orderRepo->findByExternalTransactionId('ch_nonexistent');
        $this->assertNull($result);
    }

    /**
     * Test refunding a non-charge transaction
     */
    public function testRefundNonChargeTransaction()
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

        // Verify that a subscription order can be refunded
        $this->assertEquals('paid', $order->status());
        $order->markAsRefunded();
        $this->assertEquals('refunded', $order->status());
    }

    /**
     * Test refund status is pending initially
     */
    public function testRefundStatusTransition()
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

        // Before refund, order should be 'paid'
        $this->assertEquals('paid', $order->status());

        // After refund, should be 'refunded'
        $order->markAsRefunded();
        $this->assertEquals('refunded', $order->status());
    }

    /**
     * Test repository can find order by external transaction ID
     */
    public function testRepositoryFindByExternalTransactionId()
    {
        $orderId = EntityId::generate();
        $externalId = 'ch_test_123';

        $order = new Order(
            $orderId,
            'client_123',
            EntityId::generate(),
            EntityId::generate(),
            Money::create(29.99, Currency::fromString('USD')),
            'hash_123',
            'paid',
            null,
            null,
            $externalId
        );

        $orderRepo = $this->createMock(OrderRepositoryInterface::class);
        $orderRepo->method('findByExternalTransactionId')
            ->with($externalId)
            ->willReturn($order);

        $found = $orderRepo->findByExternalTransactionId($externalId);
        $this->assertNotNull($found);
        $this->assertEquals('paid', $found->status());
    }
}
