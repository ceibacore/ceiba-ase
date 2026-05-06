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
use LemurAse\Domain\Services\BillingPeriodCalculator;
use LemurAse\Shared\LemurInstance;
use LemurAse\Infrastructure\Persistence\TableNames;
use LemurAse\Infrastructure\Events\AseEventDispatcher;

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
                'SUBSCRIPTION_UPDATED' => $this->handleSubscriptionUpdated($eventData),
                'REFUND_PROCESSED' => $this->handleRefund($eventData),
                default => throw new \InvalidArgumentException("Unhandled webhook action: {$action}")
            };

            // Log Transaction (including SUBSCRIPTION_UPDATED)
            if (in_array($action, ['INITIAL_PAYMENT', 'RENEWAL_PAYMENT', 'PAYMENT_FAILED', 'SUBSCRIPTION_UPDATED', 'REFUND_PROCESSED'])) {
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

        // Load the PlanPrice to determine billing period and trial
        $planPrice = $this->planPriceRepo->findById($order->planPriceId());
        if (!$planPrice) {
            throw new \Exception("PlanPrice not found for order {$order->id()->uuid()}.");
        }

        // Update Order Status
        $order->markAsPaid();
        $this->orderRepo->save($order);

        // Determine subscription status based on trial period
        $now = new \DateTimeImmutable();
        $planType = $planPrice->type();
        $trialDays = $planPrice->trialDays();
        $hasTrialPeriod = $trialDays > 0;

        if ($hasTrialPeriod && $planType === 'recurring') {
            // Trial period active: set status to 'trialing'
            // Trial ends after $trialDays days, then billing starts
            $periodStart = $now;
            $periodEnd = $now->modify("+{$trialDays} day");
            $subscriptionStatus = 'trialing';
        } else {
            // No trial: calculate full billing period using BillingPeriodCalculator
            $periodStart = $now;
            $periodEnd = BillingPeriodCalculator::calculate($planPrice, $periodStart);
            $subscriptionStatus = 'active';
        }

        // Create Subscription
        $subId = EntityId::generate();
        $subscription = new Subscription(
            $subId,
            $order->id(),
            $order->externalClientId(),
            $order->planPriceId(),
            $order->gatewayId(),
            $subscriptionStatus,
            $periodStart,
            $periodEnd,
            null,
            $eventData['external_subscription_id']
        );
        $this->subRepo->save($subscription);

        // Create Invoice: for trials, amount is 0 and status is draft
        // For non-trial, amount is the order amount and status is paid
        if ($hasTrialPeriod) {
            $invoiceAmount = 0;
            $invoiceStatus = 'draft';
        } else {
            $invoiceAmount = $eventData['amount'];
            $invoiceStatus = 'paid';
        }

        $this->createInvoice($order, $subId, $invoiceAmount, $invoiceStatus, $periodStart, $periodEnd);

        AseEventDispatcher::dispatch('subscription.created', $subscription);
    }

    private function handleRenewal(array $eventData): void
    {
        $subscription = $this->subRepo->findByExternalId($eventData['external_subscription_id']);
        if (!$subscription) {
            throw new \Exception("Subscription not found for renewal.");
        }

        $order = $this->orderRepo->findById($subscription->orderId());

        // Load the PlanPrice
        $planPrice = $this->planPriceRepo->findById($subscription->planPriceId());
        if (!$planPrice) {
            throw new \Exception("PlanPrice not found for subscription {$subscription->id()->uuid()}.");
        }

        // Guard: one_time plans should never have renewal events
        if ($planPrice->type() === 'one_time') {
            error_log("Renewal event received for one_time plan {$planPrice->id()->uuid()}; ignoring.");
            return;
        }

        // If subscription was in 'trialing' status, transition to 'active' on first renewal
        $newStatus = $subscription->status() === 'trialing' ? 'active' : 'active';

        // Calculate new period
        $newStart = new \DateTimeImmutable();
        $newEnd = BillingPeriodCalculator::calculate($planPrice, $newStart);

        $updatedSub = new Subscription(
            $subscription->id(),
            $subscription->orderId(),
            $subscription->externalClientId(),
            $subscription->planPriceId(),
            $subscription->gatewayId(),
            $newStatus,
            $newStart,
            $newEnd,
            null,
            $subscription->externalSubscriptionId()
        );
        $this->subRepo->save($updatedSub);

        // Create a NEW Invoice for this renewal (maintains billing history)
        $this->createInvoice($order, $subscription->id(), $eventData['amount'], 'paid', $newStart, $newEnd);

        AseEventDispatcher::dispatch('subscription.renewed', $updatedSub);
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

        AseEventDispatcher::dispatch('payment.failed', $updatedSub);
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

        AseEventDispatcher::dispatch('subscription.canceled', $updatedSub);
    }

    private function handleSubscriptionUpdated(array $eventData): void
    {
        $subscription = $this->subRepo->findByExternalId(
            $eventData['external_subscription_id']
        );

        if (!$subscription) {
            // The subscription may not exist if the event arrives before INITIAL_PAYMENT
            // or comes from a subscription created outside of lemur-ase.
            // Gracefully ignore and return.
            return;
        }

        // 1. Map the new status from Stripe to the domain internal status
        $newStatus = $this->mapStripeStatus($eventData['new_status'] ?? 'active');

        // 2. Convert Unix timestamps to DateTimeImmutable
        $periodStart = isset($eventData['current_period_start'])
            ? (new \DateTimeImmutable())->setTimestamp((int) $eventData['current_period_start'])
            : $subscription->currentPeriodStart();

        $periodEnd = isset($eventData['current_period_end'])
            ? (new \DateTimeImmutable())->setTimestamp((int) $eventData['current_period_end'])
            : $subscription->currentPeriodEnd();

        // 3. Determine canceledAt: if status is 'canceled', mark as canceled now
        $canceledAt = $subscription->canceledAt();
        if ($newStatus === 'canceled' && $canceledAt === null) {
            $canceledAt = new \DateTimeImmutable();
        }

        // 4. Construct and persist the updated subscription
        $updated = new Subscription(
            $subscription->id(),
            $subscription->orderId(),
            $subscription->externalClientId(),
            $subscription->planPriceId(),
            $subscription->gatewayId(),
            $newStatus,
            $periodStart,
            $periodEnd,
            $canceledAt,
            $subscription->externalSubscriptionId()
        );

        $this->subRepo->save($updated);

        AseEventDispatcher::dispatch('subscription.updated', $updated);
    }

    /**
     * Map Stripe subscription status to the internal domain status.
     * 
     * Stripe statuses: 'incomplete'|'incomplete_expired'|'trialing'|'active'|'past_due'|'canceled'|'unpaid'|'paused'
     */
    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active'                          => 'active',
            'trialing'                        => 'active',   // trial counts as active access
            'past_due'                        => 'past_due',
            'canceled', 'incomplete_expired'  => 'canceled',
            'unpaid'                          => 'unpaid',
            default                           => 'active',   // safe fallback
        };
    }

    /**
     * Handle refund webhook events (e.g., charge.refunded from Stripe).
     *
     * This delegates to the ProcessRefund use case logic.
     */
    private function handleRefund(array $eventData): void
    {
        // Create and execute ProcessRefund use case
        $processRefund = new ProcessRefund(
            $this->orderRepo,
            $this->subRepo,
            null // payment gateway not needed for webhook-initiated refunds
        );

        $processRefund->execute($eventData);

        AseEventDispatcher::dispatch('refund.processed', $eventData);
    }

    private function createInvoice(
        $order,
        $subId,
        float $amount,
        string $status,
        \DateTimeImmutable $periodStart,
        ?\DateTimeImmutable $periodEnd
    ): void {
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
            $periodStart,
            $periodEnd,
            $status === 'paid' ? new \DateTimeImmutable() : null, // issued_at only if paid
            null, // due_at
            $status === 'paid' ? new \DateTimeImmutable() : null  // paid_at only if paid
        );
        $this->invoiceRepo->save($invoice);

        if ($status === 'paid') {
            AseEventDispatcher::dispatch('invoice.paid', $invoice);
        } else {
            AseEventDispatcher::dispatch('invoice.generated', $invoice);
        }
    }

    private function logTransaction(string $action, array $eventData): void
    {
        $status = $action === 'PAYMENT_FAILED' ? 'failed' : 'success';
        
        // Determine transaction type
        $type = match ($action) {
            'REFUND_PROCESSED'  => 'refund',
            'SUBSCRIPTION_UPDATED' => 'subscription_event',
            default             => 'payment',
        };
        
        $this->logRepo->log([
            'id' => EntityId::generate()->uuid(),
            'short_id' => EntityId::generate()->short(),
            'external_client_id' => $eventData['external_client_id'] ?? 'unknown',
            'gateway_id' => $eventData['gateway_id'] ?? 'unknown', // Need a valid UUID here in real logic
            'external_transaction_id' => $eventData['external_transaction_id'],
            'type' => $type,
            'amount' => $eventData['amount'],
            'status' => $status,
            'security_hash' => 'auto-generated',
            'raw_payload' => json_encode($eventData['raw_payload'])
        ]);
    }
}

