<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Domain\Repositories\SubscriptionRepositoryInterface;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\ValueObjects\EntityId;

/**
 * Issues a refund for an order initiated by an admin or support agent.
 *
 * This use case calls the payment gateway to process the actual refund,
 * then updates the order and subscription records accordingly.
 *
 * Use case for admin panel integration.
 */
final class IssueRefund
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly SubscriptionRepositoryInterface $subscriptionRepo,
        private readonly PaymentGatewayInterface $paymentGateway
    ) {}

    /**
     * Execute an admin-initiated refund.
     *
     * @param string $orderId      The internal order ID (UUID)
     * @param float  $amount       Amount to refund (must be <= order amount)
     * @param string $reason       Human-readable reason for the refund
     *
     * @return array               [
     *                                'status' => 'success'|'failed',
     *                                'external_refund_id' => string|null,
     *                                'error' => string|null
     *                             ]
     *
     * @throws \InvalidArgumentException If order not found or amount invalid
     * @throws \Exception If gateway call fails unexpectedly
     */
    public function execute(string $orderId, float $amount, string $reason): array
    {
        // Load the order
        $orderEntityId = EntityId::fromString($orderId);
        $order = $this->orderRepo->findById($orderEntityId);

        if (!$order) {
            throw new \InvalidArgumentException("Order {$orderId} not found.");
        }

        // Validate amount
        $orderAmount = (float)$order->amount()->amount();
        if ($amount <= 0 || $amount > $orderAmount) {
            throw new \InvalidArgumentException(
                "Refund amount {$amount} is invalid. Must be between 0 and {$orderAmount}."
            );
        }

        $externalTxId = $order->externalOrderId();
        if (!$externalTxId) {
            throw new \InvalidArgumentException("Order has no external transaction ID.");
        }

        // Call payment gateway to process the refund
        $refundResult = $this->paymentGateway->refund($externalTxId, $amount, $reason);

        if ($refundResult['status'] !== 'success') {
            // Refund failed at gateway level
            return $refundResult;
        }

        // Refund succeeded; update local records
        $isFullRefund = $amount >= $orderAmount;

        if ($isFullRefund) {
            // Mark order as refunded
            $order->markAsRefunded();
            $this->orderRepo->save($order);

            // Cancel subscription if it exists
            $subscription = $this->subscriptionRepo->findByOrderId($order->id());
            if ($subscription) {
                $canceledSub = new Subscription(
                    $subscription->id(),
                    $subscription->orderId(),
                    $subscription->externalClientId(),
                    $subscription->planPriceId(),
                    $subscription->gatewayId(),
                    'canceled',
                    $subscription->currentPeriodStart(),
                    $subscription->currentPeriodEnd(),
                    new \DateTimeImmutable(),
                    $subscription->externalSubscriptionId()
                );
                $this->subscriptionRepo->save($canceledSub);
            }
        } else {
            // Partial refund: update order metadata to reflect partial refund
            // (This could be enhanced with a dedicated metadata field)
            // For now, just log it in the transaction log (handled by ProcessRefund)
        }

        return [
            'status' => 'success',
            'external_refund_id' => $refundResult['external_refund_id'],
            'order_id' => $orderId,
            'amount_refunded' => $amount,
            'full_refund' => $isFullRefund
        ];
    }
}
