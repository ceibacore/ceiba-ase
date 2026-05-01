<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\Entities\Invoice;
use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Domain\Repositories\SubscriptionRepositoryInterface;
use LemurAse\Domain\Repositories\InvoiceRepositoryInterface;
use LemurAse\Domain\Repositories\TransactionLogRepositoryInterface;
use LemurAse\Domain\Repositories\PlanPriceRepositoryInterface;
use LemurAse\Shared\LemurInstance;
use LemurAse\Infrastructure\Persistence\TableNames;

final class ProcessWebhook
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly SubscriptionRepositoryInterface $subRepo,
        private readonly InvoiceRepositoryInterface $invoiceRepo,
        private readonly TransactionLogRepositoryInterface $logRepo,
        private readonly PlanPriceRepositoryInterface $planPriceRepo
    ) {}

    public function execute(string $action, array $eventData): bool
    {
        $extTxId = $eventData['external_transaction_id'];

        // 1. Idempotency Check
        if ($this->logRepo->exists($extTxId)) {
            return true; // Already processed
        }

        $db = LemurInstance::get();

        // 2. Atomic Transaction
        return $db->transaction(function() use ($action, $eventData) {
            match($action) {
                'INITIAL_PAYMENT' => $this->handleInitialPayment($eventData),
                'RENEWAL_PAYMENT' => $this->handleRenewal($eventData),
                'PAYMENT_FAILED' => $this->handlePaymentFailed($eventData),
                'SUBSCRIPTION_CANCELED' => $this->handleCancellation($eventData),
                default => throw new \InvalidArgumentException("Unhandled webhook action: {$action}")
            };

            // Log Transaction (only for payment attempts)
            if (in_array($action, ['INITIAL_PAYMENT', 'RENEWAL_PAYMENT', 'PAYMENT_FAILED'])) {
                $this->logTransaction($action, $eventData);
            }

            return true;
        });
    }

    private function handleInitialPayment(array $eventData): void
    {
        // Find order by internal ID passed via metadata in checkout
        $orderId = EntityId::fromString($eventData['internal_order_id']);
        $order = $this->orderRepo->findById($orderId);

        if (!$order) {
            throw new \Exception("Order not found for initial payment.");
        }

        // Update Order Status
        $order->markAsPaid();
        $this->orderRepo->save($order);

        // Create Subscription
        $subId = EntityId::generate();
        $subscription = new Subscription(
            $subId,
            $order->id(),
            $order->externalClientId(),
            $order->planPriceId(),
            $order->gatewayId(),
            'active',
            new \DateTimeImmutable(), // current_period_start
            (new \DateTimeImmutable())->modify('+1 month'), // current_period_end (should depend on plan)
            null,
            $eventData['external_subscription_id']
        );
        $this->subRepo->save($subscription);

        // Create Invoice
        $this->createInvoice($order, $subId, $eventData['amount'], 'paid');
    }

    private function handleRenewal(array $eventData): void
    {
        $subscription = $this->subRepo->findByExternalId($eventData['external_subscription_id']);
        if (!$subscription) {
            throw new \Exception("Subscription not found for renewal.");
        }

        $order = $this->orderRepo->findById($subscription->orderId());

        // Update Subscription period
        // In reality, read the exact period dates from the gateway event
        $newEnd = (new \DateTimeImmutable())->modify('+1 month'); 

        $updatedSub = new Subscription(
            $subscription->id(),
            $subscription->orderId(),
            $subscription->externalClientId(),
            $subscription->planPriceId(),
            $subscription->gatewayId(),
            'active', // restores to active if it was past_due
            new \DateTimeImmutable(), // new start
            $newEnd,
            null,
            $subscription->externalSubscriptionId()
        );
        $this->subRepo->save($updatedSub);

        // Create a NEW Invoice for this renewal (maintains billing history)
        $this->createInvoice($order, $subscription->id(), $eventData['amount'], 'paid');
    }

    private function handlePaymentFailed(array $eventData): void
    {
        $subscription = $this->subRepo->findByExternalId($eventData['external_subscription_id']);
        if (!$subscription) return;

        // Change status to past_due (Grace period logic applies on read)
        $updatedSub = new Subscription(
            $subscription->id(), $subscription->orderId(), $subscription->externalClientId(),
            $subscription->planPriceId(), $subscription->gatewayId(),
            'past_due', // FAILED -> PAST DUE
            $subscription->currentPeriodStart(), $subscription->currentPeriodEnd(),
            null, $subscription->externalSubscriptionId()
        );
        $this->subRepo->save($updatedSub);
    }

    private function handleCancellation(array $eventData): void
    {
        $subscription = $this->subRepo->findByExternalId($eventData['external_subscription_id']);
        if (!$subscription) return;

        $updatedSub = new Subscription(
            $subscription->id(), $subscription->orderId(), $subscription->externalClientId(),
            $subscription->planPriceId(), $subscription->gatewayId(),
            'canceled', 
            $subscription->currentPeriodStart(), $subscription->currentPeriodEnd(),
            new \DateTimeImmutable(), // canceled_at
            $subscription->externalSubscriptionId()
        );
        $this->subRepo->save($updatedSub);
    }

    private function createInvoice($order, $subId, float $amount, string $status): void
    {
        $invoiceNumber = $this->invoiceRepo->getNextInvoiceNumber($order->externalClientId());
        $invoice = new Invoice(
            EntityId::generate(),
            $order->id(),
            $order->externalClientId(),
            $invoiceNumber,
            $order->amount(), // Should use actual event amount ideally
            $status,
            $subId,
            $amount,
            0,
            new \DateTimeImmutable(),
            (new \DateTimeImmutable())->modify('+1 month'),
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );
        $this->invoiceRepo->save($invoice);
    }

    private function logTransaction(string $action, array $eventData): void
    {
        $status = $action === 'PAYMENT_FAILED' ? 'failed' : 'success';
        
        $this->logRepo->log([
            'id' => EntityId::generate()->uuid(),
            'short_id' => EntityId::generate()->short(),
            'external_client_id' => $eventData['external_client_id'] ?? 'unknown',
            'gateway_id' => $eventData['gateway_id'] ?? 'unknown', // Need a valid UUID here in real logic
            'external_transaction_id' => $eventData['external_transaction_id'],
            'type' => 'payment',
            'amount' => $eventData['amount'],
            'status' => $status,
            'security_hash' => 'auto-generated',
            'raw_payload' => json_encode($eventData['raw_payload'])
        ]);
    }
}
