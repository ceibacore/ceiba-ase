<?php

namespace LemurAse\WebhookManagement\Application;

use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\Entities\Invoice;
use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Domain\Repositories\SubscriptionRepositoryInterface;
use LemurAse\Domain\Repositories\InvoiceRepositoryInterface;
use LemurAse\Domain\Repositories\TransactionLogRepositoryInterface;
use LemurAse\Domain\Repositories\PlanPriceRepositoryInterface;
use LemurAse\Domain\Repositories\PlanRepositoryInterface;
use LemurAse\Domain\Repositories\GatewayRepositoryInterface;
use LemurAse\Domain\Services\BillingPeriodCalculator;
use LemurAse\Shared\Infrastructure\LemurInstance;
use LemurAse\WebhookManagement\Domain\WebhookEvent;
use LemurAse\Application\UseCases\ProcessRefund;
use LemurAse\Infrastructure\Events\AseEventDispatcher;

final class ProcessWebhookUseCase
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly SubscriptionRepositoryInterface $subRepo,
        private readonly InvoiceRepositoryInterface $invoiceRepo,
        private readonly TransactionLogRepositoryInterface $logRepo,
        private readonly PlanPriceRepositoryInterface $planPriceRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly GatewayRepositoryInterface $gatewayRepo
    ) {}

    public function execute(WebhookEvent $event): bool
    {
        $action = $event->action;
        $data   = $event->data;
        $extId  = $event->externalId;

        // 1. Idempotency Check
        if ($this->logRepo->exists($extId)) {
            return true; 
        }

        $db = LemurInstance::get();

        // 2. Atomic Transaction
        return $db->transaction(function() use ($action, $data, $event) {
            match($action) {
                'INITIAL_PAYMENT'       => $this->handleInitialPayment($data),
                'RENEWAL_PAYMENT'       => $this->handleRenewal($data),
                'PAYMENT_FAILED'        => $this->handlePaymentFailed($data),
                'SUBSCRIPTION_CANCELED' => $this->handleCancellation($data),
                'SUBSCRIPTION_UPDATED'  => $this->handleSubscriptionUpdated($data),
                'REFUND_PROCESSED'      => $this->handleRefund($data),
                'UNKNOWN'               => true, // Gracefully ignore unhandled events
                default => throw new \InvalidArgumentException("Unhandled webhook action: {$action}")
            };

            // Log Transaction
            if (in_array($action, ['INITIAL_PAYMENT', 'RENEWAL_PAYMENT', 'PAYMENT_FAILED', 'SUBSCRIPTION_UPDATED', 'REFUND_PROCESSED'])) {
                $this->logTransaction($action, $data, $event);
            }

            return true;
        });
    }

    private function handleInitialPayment(array $data): void
    {
        $orderId = EntityId::fromString($data['internal_order_id']);
        $order = $this->orderRepo->findById($orderId);
        if (!$order) throw new \Exception("Order not found.");

        $planPrice = $this->planPriceRepo->findById($order->planPriceId());
        if (!$planPrice) throw new \Exception("PlanPrice not found.");

        $plan = $this->planRepo->findById($planPrice->planId());
        if (!$plan) throw new \Exception("Plan not found.");
        $planSlug = $plan->slug();

        $order->markAsPaid();
        $this->orderRepo->save($order);

        $now = new \DateTimeImmutable();
        $trialDays = $planPrice->trialDays();
        
        if ($trialDays > 0 && $planPrice->type() === 'recurring') {
            $periodStart = $now;
            $periodEnd = $now->modify("+{$trialDays} day");
            $status = 'trialing';
        } else {
            $periodStart = $now;
            $periodEnd = BillingPeriodCalculator::calculate($planPrice, $periodStart);
            $status = 'active';
        }

        $subId = EntityId::generate();
        $subscription = new Subscription(
            $subId, $order->id(), $order->externalClientId(),
            $order->planPriceId(), $order->gatewayId(), $status,
            $periodStart, $periodEnd, null, $data['external_subscription_id']
        );
        $this->subRepo->save($subscription);

        $this->createInvoice($order, $subId, $data['amount'], ($status === 'trialing' ? 'draft' : 'paid'), $periodStart, $periodEnd);
        
        // Dispatch event for host application
        AseEventDispatcher::dispatch('subscription.activated', [
            'external_client_id' => $order->externalClientId(),
            'subscription_id'    => $subId->uuid(),
            'plan_slug'          => $planSlug,
            'expires_at'         => $periodEnd,
        ]);
    }

    private function handleRenewal(array $data): void
    {
        $subscription = $this->subRepo->findByExternalId($data['external_subscription_id']);
        if (!$subscription) throw new \Exception("Subscription not found for renewal.");

        $planPrice = $this->planPriceRepo->findById($subscription->planPriceId());
        $newStart = new \DateTimeImmutable();
        $newEnd = BillingPeriodCalculator::calculate($planPrice, $newStart);

        $updatedSub = new Subscription(
            $subscription->id(), $subscription->orderId(), $subscription->externalClientId(),
            $subscription->planPriceId(), $subscription->gatewayId(), 'active',
            $newStart, $newEnd, null, $subscription->externalSubscriptionId()
        );
        $this->subRepo->save($updatedSub);

        $order = $this->orderRepo->findById($subscription->orderId());
        $this->createInvoice($order, $subscription->id(), $data['amount'], 'paid', $newStart, $newEnd);

        AseEventDispatcher::dispatch('subscription.renewed', [
            'subscription_id' => $subscription->id()->uuid(),
            'expires_at'      => $newEnd,
        ]);
    }

    private function handlePaymentFailed(array $data): void
    {
        $subscription = $this->subRepo->findByExternalId($data['external_subscription_id']);
        if (!$subscription) return;

        $updatedSub = new Subscription(
            $subscription->id(), $subscription->orderId(), $subscription->externalClientId(),
            $subscription->planPriceId(), $subscription->gatewayId(), 'past_due',
            $subscription->currentPeriodStart(), $subscription->currentPeriodEnd(),
            null, $subscription->externalSubscriptionId()
        );
        $this->subRepo->save($updatedSub);
    }

    private function handleCancellation(array $data): void
    {
        $subscription = $this->subRepo->findByExternalId($data['external_subscription_id']);
        if (!$subscription) return;

        $updatedSub = new Subscription(
            $subscription->id(), $subscription->orderId(), $subscription->externalClientId(),
            $subscription->planPriceId(), $subscription->gatewayId(), 'canceled',
            $subscription->currentPeriodStart(), $subscription->currentPeriodEnd(),
            new \DateTimeImmutable(), $subscription->externalSubscriptionId()
        );
        $this->subRepo->save($updatedSub);

        AseEventDispatcher::dispatch('subscription.canceled', [
            'subscription_id' => $subscription->id()->uuid(),
            'immediate'       => true,
        ]);
    }

    private function handleSubscriptionUpdated(array $data): void
    {
        $subscription = $this->subRepo->findByExternalId($data['external_subscription_id']);
        if (!$subscription) return;

        $newStatus = $this->mapProviderStatus($data['new_status'] ?? 'active');

        $periodStart = isset($data['current_period_start'])
            ? (new \DateTimeImmutable())->setTimestamp((int) $data['current_period_start'])
            : $subscription->currentPeriodStart();

        $periodEnd = isset($data['current_period_end'])
            ? (new \DateTimeImmutable())->setTimestamp((int) $data['current_period_end'])
            : $subscription->currentPeriodEnd();

        $canceledAt = ($newStatus === 'canceled') ? new \DateTimeImmutable() : $subscription->canceledAt();

        $updated = new Subscription(
            $subscription->id(), $subscription->orderId(), $subscription->externalClientId(),
            $subscription->planPriceId(), $subscription->gatewayId(), $newStatus,
            $periodStart, $periodEnd, $canceledAt, $subscription->externalSubscriptionId()
        );

        $this->subRepo->save($updated);

        if ($newStatus === 'canceled') {
            AseEventDispatcher::dispatch('subscription.canceled', [
                'subscription_id' => $subscription->id()->uuid(),
                'immediate'       => false,
            ]);
        }
    }

    private function handleRefund(array $data): void
    {
        $processRefund = new ProcessRefund($this->orderRepo, $this->subRepo, null);
        $processRefund->execute($data);
    }

    private function mapProviderStatus(string $status): string
    {
        return match ($status) {
            'active', 'trialing'             => 'active',
            'past_due'                       => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            'unpaid'                         => 'unpaid',
            default                          => 'active',
        };
    }

    private function createInvoice($order, $subId, float $amount, string $status, $start, $end): void
    {
        $planSnapshot = null;
        try {
            $planPrice = $this->planPriceRepo->findById($order->planPriceId());
            if ($planPrice) {
                $plan = $this->planRepo->findById($planPrice->planId());
                $gateway = $this->gatewayRepo->findById($order->gatewayId());
                
                if ($plan) {
                    $planSnapshot = [
                        'plan' => [
                            'id' => $plan->id()->uuid(),
                            'name' => $plan->name(),
                            'slug' => $plan->slug(),
                            'description' => $plan->description(),
                            'metadata' => $plan->metadata(),
                        ],
                        'price' => [
                            'id' => $planPrice->id()->uuid(),
                            'amount' => $planPrice->price()->amount(),
                            'currency' => $planPrice->price()->currency()->toString(),
                            'type' => $planPrice->type(),
                            'interval' => $planPrice->interval(),
                            'interval_count' => $planPrice->intervalCount(),
                            'trial_days' => $planPrice->trialDays(),
                        ],
                        'gateway' => [
                            'id' => $order->gatewayId()->uuid(),
                            'provider' => $gateway ? $gateway->provider() : 'unknown',
                        ]
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fallback if snapshot fails to build
        }

        $invoiceNumber = $this->invoiceRepo->getNextInvoiceNumber($order->externalClientId());
        $invoice = new Invoice(
            EntityId::generate(), $order->id(), $order->externalClientId(),
            $invoiceNumber, $order->amount(), $status, $subId, $amount, 0,
            $start, $end, ($status === 'paid' ? new \DateTimeImmutable() : null),
            null, ($status === 'paid' ? new \DateTimeImmutable() : null),
            $planSnapshot
        );
        $this->invoiceRepo->save($invoice);
    }

    private function logTransaction(string $action, array $data, WebhookEvent $event): void
    {
        $status = $action === 'PAYMENT_FAILED' ? 'failed' : 'success';
        $type = match ($action) {
            'REFUND_PROCESSED'     => 'refund',
            'SUBSCRIPTION_UPDATED' => 'subscription_event',
            default                => 'payment',
        };
        
        $this->logRepo->log([
            'id' => EntityId::generate()->uuid(),
            'short_id' => EntityId::generate()->short(),
            'external_client_id' => $data['external_client_id'] ?? 'unknown',
            'gateway_id' => $data['gateway_id'] ?? 'unknown',
            'external_transaction_id' => $event->externalId,
            'type' => $type,
            'amount' => $data['amount'],
            'status' => $status,
            'security_hash' => 'auto-generated',
            'raw_payload' => json_encode($event->rawPayload)
        ]);
    }
}
