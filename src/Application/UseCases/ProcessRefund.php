<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Domain\Repositories\SubscriptionRepositoryInterface;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Shared\LemurInstance;
use LemurAse\Infrastructure\Persistence\TableNames;

/**
 * Processes refunds initiated by webhook events (e.g., charge.refunded from Stripe).
 *
 * This use case handles the business logic when a payment provider notifies
 * us of a refund. It updates order and subscription records accordingly.
 *
 * Idempotency: Uses external_refund_id as the key to prevent duplicate processing.
 */
final class ProcessRefund
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly SubscriptionRepositoryInterface $subscriptionRepo,
        private readonly PaymentGatewayInterface $paymentGateway
    ) {}

    /**
     * Execute the refund processing logic.
     *
     * @param array $webhookData Normalized webhook data including:
     *   - external_transaction_id: The original charge/transaction ID
     *   - external_refund_id: The refund ID from the payment provider (for idempotency)
     *   - external_subscription_id: Optional, to find the subscription
     *   - amount: Refund amount
     *   - full_refund: Boolean indicating if this is a full refund
     *
     * @return void
     * @throws \Exception If order or subscription not found (when required)
     */
    public function execute(array $webhookData): void
    {
        // Check idempotency: if this refund was already processed, skip
        if ($this->isRefundAlreadyProcessed($webhookData['external_refund_id'] ?? null)) {
            return;
        }

        $externalTxId = $webhookData['external_transaction_id'] ?? null;
        if (!$externalTxId) {
            throw new \InvalidArgumentException("Webhook data missing external_transaction_id");
        }

        // Find the order by external transaction ID
        $order = $this->orderRepo->findByExternalTransactionId($externalTxId);
        if (!$order) {
            // Log this as a warning but don't fail
            error_log("Refund webhook received but no order found for transaction {$externalTxId}");
            return;
        }

        $isFullRefund = $webhookData['full_refund'] ?? false;
        $refundAmount = $webhookData['amount'] ?? 0;

        // If this is a full refund, update order and cancel subscription
        if ($isFullRefund) {
            // Mark order as refunded
            $order->markAsRefunded();
            $this->orderRepo->save($order);

            // Find and cancel the subscription associated with this order
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
                    new \DateTimeImmutable(), // canceled_at = now
                    $subscription->externalSubscriptionId()
                );
                $this->subscriptionRepo->save($canceledSub);
            }
        } else {
            // Partial refund: log in metadata but don't auto-cancel
            // This is stored in the transaction log for audit trail
            $this->logPartialRefund($order->id(), $refundAmount, $webhookData);
        }

        // Log the refund transaction
        $this->logRefundTransaction($webhookData);
    }

    /**
     * Check if this refund was already processed (idempotency).
     */
    private function isRefundAlreadyProcessed(?string $externalRefundId): bool
    {
        if (!$externalRefundId) {
            return false;
        }

        $db = LemurInstance::get();
        $exists = $db->query(TableNames::TRANSACTIONS_LOG)
            ->where('external_transaction_id', $externalRefundId)
            ->where('type', 'refund')
            ->exists();

        return $exists;
    }

    /**
     * Log a partial refund for audit trail.
     */
    private function logPartialRefund(EntityId $orderId, float $amount, array $webhookData): void
    {
        // Store partial refund details in metadata or a separate audit log
        // For now, we just log it via the transaction log
        $this->logRefundTransaction($webhookData);
    }

    /**
     * Log the refund transaction in the transaction log.
     */
    private function logRefundTransaction(array $webhookData): void
    {
        $db = LemurInstance::get();

        $db->query(TableNames::TRANSACTIONS_LOG)
            ->insert([
                'id' => EntityId::generate()->uuid(),
                'short_id' => EntityId::generate()->short(),
                'external_client_id' => $webhookData['external_client_id'] ?? 'unknown',
                'gateway_id' => $webhookData['gateway_id'] ?? null,
                'external_transaction_id' => $webhookData['external_refund_id'] ?? $webhookData['external_transaction_id'],
                'type' => 'refund',
                'amount' => $webhookData['amount'] ?? 0,
                'status' => 'success',
                'security_hash' => hash('sha256', json_encode($webhookData)),
                'raw_payload' => json_encode($webhookData),
                'created_at' => date('Y-m-d H:i:s')
            ]);
    }
}
