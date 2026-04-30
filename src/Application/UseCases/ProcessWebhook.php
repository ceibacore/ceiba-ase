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

    public function execute(array $eventData): bool
    {
        $extTxId = $eventData['external_transaction_id'];

        // 1. Idempotency Check
        if ($this->logRepo->exists($extTxId)) {
            return true; // Already processed
        }

        $db = LemurInstance::get();

        // 2. Atomic Transaction (using LemurDB v1.1.0 feature)
        return $db->transaction(function() use ($eventData) {
            
            // Find order by external reference or short_id if passed in metadata
            // For this demo, we assume the eventData has the internal order_id
            $orderId = EntityId::fromString($eventData['raw_payload']['data']['object']['metadata']['order_id'] ?? '');
            $order = $this->orderRepo->findById($orderId);

            if (!$order) {
                throw new \Exception("Order not found for webhook event.");
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
                new \DateTimeImmutable(),
                (new \DateTimeImmutable())->modify('+1 month'), // Simplified
                null,
                $eventData['external_order_id']
            );
            $this->subRepo->save($subscription);

            // Create Invoice
            $invoiceNumber = $this->invoiceRepo->getNextInvoiceNumber($order->externalClientId());
            $invoice = new Invoice(
                EntityId::generate(),
                $order->id(),
                $order->externalClientId(),
                $invoiceNumber,
                $order->amount(),
                'paid',
                $subId,
                $order->amount()->amount(),
                0,
                new \DateTimeImmutable(),
                (new \DateTimeImmutable())->modify('+1 month'),
                new \DateTimeImmutable(),
                new \DateTimeImmutable(),
                new \DateTimeImmutable()
            );
            $this->invoiceRepo->save($invoice);

            // Log Transaction
            $this->logRepo->log([
                'id' => EntityId::generate()->uuid(),
                'short_id' => EntityId::generate()->short(),
                'subscription_id' => $subId->uuid(),
                'order_id' => $order->id()->uuid(),
                'external_client_id' => $order->externalClientId(),
                'gateway_id' => $order->gatewayId()->uuid(),
                'external_transaction_id' => $eventData['external_transaction_id'],
                'type' => 'payment',
                'amount' => $eventData['amount'],
                'status' => 'success',
                'security_hash' => $order->securityHash(),
                'raw_payload' => json_encode($eventData['raw_payload'])
            ]);

            return true;
        });
    }
}
