<?php

declare(strict_types=1);

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\Payment;
use LemurAse\Domain\Repositories\PaymentRepositoryInterface;
use LemurAse\Domain\Repositories\TransactionLogRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Infrastructure\Events\AseEventDispatcher;
use LemurAse\Shared\Infrastructure\CeibaInstance;
use LemurAse\WebhookManagement\Domain\PaymentVerificationException;
use LemurAse\WebhookManagement\Domain\WebhookEvent;

/**
 * Applies a verified provider event to a one-time payment and records the outcome in
 * the outbox (payment.succeeded | payment.failed | payment.refunded), in one transaction.
 *
 * - The event must come through the payment's own account (a webhook of academy A can
 *   never settle a payment of academy B or of the platform).
 * - A payment is settled only when the provider charged at least its amount, in its
 *   currency (compared in minor units). Settling twice is a no-op.
 * - Each provider event (WebhookEvent::externalId) and each refund id is applied once.
 */
final class ProcessPaymentEvent
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly TransactionLogRepositoryInterface $log,
    ) {}

    public function execute(Payment $payment, WebhookEvent $event, ?string $accountRef): bool
    {
        if ($payment->accountRef() !== $accountRef) {
            throw new PaymentVerificationException("Payment {$payment->id()->uuid()} does not belong to the account that sent this event.");
        }
        if (!in_array($event->action, ['INITIAL_PAYMENT', 'PAYMENT_FAILED', 'REFUND_PROCESSED'], true)) {
            return true;
        }
        if ($this->log->exists($event->externalId)) {
            return true;
        }

        return CeibaInstance::get()->transaction(function () use ($payment, $event): bool {
            match ($event->action) {
                'INITIAL_PAYMENT'  => $this->settle($payment, $event->data),
                'PAYMENT_FAILED'   => $this->fail($payment),
                'REFUND_PROCESSED' => $this->refund($payment, $event->data),
            };
            $this->record($payment, $event);

            return true;
        });
    }

    /** Applies a refund confirmed by the provider API (AseManager::refundPayment). */
    public function applyRefund(Payment $payment, string $refundId, ?float $amount): void
    {
        CeibaInstance::get()->transaction(function () use ($payment, $refundId, $amount): void {
            $this->refund($payment, [
                'external_refund_id' => $refundId,
                'full_refund' => $amount === null,
                'amount' => $amount,
            ]);
        });
    }

    private function settle(Payment $payment, array $data): void
    {
        if ($payment->status() === 'paid') {
            return;
        }
        if (!in_array($payment->status(), ['pending', 'failed'], true)) {
            throw new PaymentVerificationException("Payment {$payment->id()->uuid()} is {$payment->status()}; payment not applied.");
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($currency !== $payment->currency() || !isset($data['amount']) || !is_numeric($data['amount'])) {
            throw new PaymentVerificationException("Payment {$payment->id()->uuid()}: provider reported no amount or a currency other than {$payment->currency()}.");
        }
        if (MinorUnits::toMinor($data['amount'], $currency) < $payment->amountMinor()) {
            throw new PaymentVerificationException("Payment {$payment->id()->uuid()}: provider charged less than the payment amount.");
        }

        $payment->markPaid((string) ($data['refund_reference'] ?? $data['external_transaction_id'] ?? ''), new \DateTimeImmutable());
        $this->payments->save($payment);
        $this->emit('payment.succeeded', $payment);
    }

    private function fail(Payment $payment): void
    {
        if ($payment->status() !== 'pending') {
            return;
        }
        $payment->markFailed();
        $this->payments->save($payment);
        $this->emit('payment.failed', $payment);
    }

    private function refund(Payment $payment, array $data): void
    {
        if (!in_array($payment->status(), ['paid', 'partially_refunded'], true)) {
            return; // nothing was settled: there is nothing to refund
        }
        // The API response gives the provider's refund id ('555', 'rfnd_x'); webhook adapters
        // may namespace it ('paystack:refund:555'). Compare the provider's own id.
        $refundId = (string) preg_replace('/^[a-z]+:refund:/', '', (string) ($data['external_refund_id'] ?? ''));
        $metadata = $payment->metadata();
        $known = $metadata['refund_ids'] ?? [];
        if ($refundId !== '' && in_array($refundId, $known, true)) {
            return; // already applied (API response and webhook report the same refund)
        }

        $currency = $payment->currency();
        $before = $payment->refundedMinor();
        $full = (bool) ($data['full_refund'] ?? false);
        if (!$full && isset($data['refunded_total']) && is_numeric($data['refunded_total'])) {
            $total = MinorUnits::toMinor($data['refunded_total'], $currency);
            $full = $payment->addRefund($total - $before, false);
        } else {
            $amount = isset($data['amount']) && is_numeric($data['amount']) ? MinorUnits::toMinor($data['amount'], $currency) : 0;
            $full = $payment->addRefund($amount, $full);
        }
        if ($refundId !== '') {
            $metadata['refund_ids'] = array_values(array_unique([...$known, $refundId]));
            $payment = $this->withMetadata($payment, $metadata);
        }
        $this->payments->save($payment);
        $this->emit('payment.refunded', $payment, [
            'refund_id' => $refundId !== '' ? $refundId : null,
            'refund_minor' => $payment->refundedMinor() - $before,
            'refunded_minor' => $payment->refundedMinor(),
            'full' => $full,
        ]);
    }

    private function emit(string $name, Payment $payment, array $extra = []): void
    {
        AseEventDispatcher::dispatchReliably($name, [
            'payment_id' => $payment->id()->uuid(),
            'context' => $payment->context(),
            'reference' => $payment->reference(),
            'account_ref' => $payment->accountRef(),
            'external_client_id' => $payment->externalClientId(),
            'provider' => $payment->provider(),
            'status' => $payment->status(),
            'amount_minor' => $payment->amountMinor(),
            'currency' => $payment->currency(),
            'external_payment_id' => $payment->externalPaymentId(),
            'paid_at' => $payment->paidAt()?->format(DATE_ATOM),
        ] + $extra, 'payment', $payment->id()->uuid());
    }

    private function record(Payment $payment, WebhookEvent $event): void
    {
        $amount = $event->data['amount'] ?? null;
        $this->log->log([
            'id' => EntityId::generate()->uuid(),
            'short_id' => EntityId::generate()->short(),
            'order_id' => $payment->id()->uuid(),
            'external_client_id' => $payment->externalClientId(),
            'gateway_id' => $payment->gatewayId()->uuid(),
            'external_transaction_id' => $event->externalId,
            'type' => $event->action === 'REFUND_PROCESSED' ? 'refund' : 'payment',
            'amount' => is_numeric($amount) ? (float) $amount : 0,
            'status' => $event->action === 'PAYMENT_FAILED' ? 'failed' : 'success',
            'security_hash' => hash('sha256', $event->externalId . '|' . $payment->id()->uuid()),
            'raw_payload' => json_encode(['context' => $payment->context(), 'action' => $event->action], JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function withMetadata(Payment $p, array $metadata): Payment
    {
        return new Payment($p->id(), $p->context(), $p->reference(), $p->accountRef(), $p->externalClientId(), $p->gatewayId(),
            $p->provider(), $p->amount(), $p->description(), $p->status(), $p->externalId(), $p->externalPaymentId(),
            $p->refundedMinor(), $metadata, $p->paidAt(), $p->createdAt());
    }
}
