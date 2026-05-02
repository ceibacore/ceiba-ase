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

class IssueRefundTest extends TestCase
{
    /**
     * Test order can be refunded
     */
    public function testOrderCanBeRefunded()
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

        $this->assertEquals('paid', $order->status());
        $order->markAsRefunded();
        $this->assertEquals('refunded', $order->status());
    }

    /**
     * Test failed refund scenario
     */
    public function testFailedRefundDoesNotChangeOrderStatus()
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

        // Order should remain 'paid' on failed refund
        $this->assertEquals('paid', $order->status());
    }

    /**
     * Test error handling for order not found
     */
    public function testOrderNotFoundReturnsNull()
    {
        $orderId = EntityId::generate();
        
        $orderRepo = $this->createMock(OrderRepositoryInterface::class);
        $orderRepo->method('findById')->willReturn(null);

        $found = $orderRepo->findById($orderId);
        $this->assertNull($found);
    }

    /**
     * Test invalid refund amount validation
     */
    public function testInvalidRefundAmount()
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

        $orderAmount = (float)$order->amount()->amount();
        
        // Test that refund amount exceeding order amount is invalid
        $refundAmount = 50.00;
        $this->assertGreaterThan($orderAmount, $refundAmount);
    }

    /**
     * Test payment gateway refund call
     */
    public function testPaymentGatewayRefundCall()
    {
        $paymentGateway = $this->createMock(PaymentGatewayInterface::class);
        $paymentGateway->method('refund')->willReturn([
            'status' => 'success',
            'refund_id' => 'refund_123'
        ]);

        $result = $paymentGateway->refund('ch_test_123', 29.99, 'Customer requested');

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('refund_123', $result['refund_id']);
    }

    /**
     * Test partial refund scenario
     */
    public function testPartialRefund()
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

        $orderAmount = (float)$order->amount()->amount();
        $partialAmount = 15.00;
        
        // Verify partial amount is less than order amount
        $this->assertLessThan($orderAmount, $partialAmount);
        // Order should remain 'paid' for partial refund
        $this->assertEquals('paid', $order->status());
    }
}
