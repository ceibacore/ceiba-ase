<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\Razorpay;

use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Infrastructure\Http\RequestHeaders;

/**
 * Maps verified Razorpay events to ceiba-ase actions. Checkout uses Payment Links.
 *
 * - payment_link.paid → INITIAL_PAYMENT (order from reference_id)
 * - refund.processed  → REFUND_PROCESSED (order from the refund or payment notes)
 * - anything else     → UNKNOWN (acknowledged, ignored)
 */
final class RazorpayEvents
{
    private function __construct() {}

    /** @return array{action: string, external_id: string, data: array} */
    public static function normalize(array $event, array $headers = []): array
    {
        $type = (string) ($event['event'] ?? '');
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $eventId = RequestHeaders::get($headers, 'x-razorpay-event-id');

        return match ($type) {
            'payment_link.paid' => self::paid($payload, $event, $eventId),
            'refund.processed'  => self::refund($payload, $event, $eventId),
            default             => ['action' => 'UNKNOWN', 'external_id' => self::eventId($eventId, $type, $payload), 'data' => ['raw_payload' => $event]],
        };
    }

    /** Normalized INITIAL_PAYMENT data from a paid payment link. */
    public static function paymentData(array $link, array $payment, array $raw): array
    {
        $currency = strtoupper((string) ($payment['currency'] ?? ($link['currency'] ?? '')));
        $minor = $payment['amount'] ?? ($link['amount_paid'] ?? 0);
        $notes = is_array($link['notes'] ?? null) ? $link['notes'] : [];

        return [
            'external_subscription_id' => (string) ($link['id'] ?? ''),
            'external_transaction_id'  => (string) ($payment['id'] ?? ($link['id'] ?? '')),
            'amount'                   => $currency !== '' ? MinorUnits::fromMinor($minor, $currency) : null,
            'currency'                 => $currency,
            'internal_order_id'        => ($link['reference_id'] ?? '') !== '' ? $link['reference_id'] : ($notes['order_id'] ?? null),
            'external_client_id'       => $notes['external_client_id'] ?? null,
            // RazorpayAdapter::refund() takes the payment link id.
            'refund_reference'         => (string) ($link['id'] ?? ''),
            'raw_payload'              => $raw,
        ];
    }

    private static function paid(array $payload, array $event, string $eventId): array
    {
        $link = $payload['payment_link']['entity'] ?? [];
        $payment = $payload['payment']['entity'] ?? [];
        $id = self::eventId($eventId, 'payment_link.paid', $payload);
        if (($link['status'] ?? '') !== 'paid' || (isset($payment['status']) && $payment['status'] !== 'captured')) {
            return ['action' => 'UNKNOWN', 'external_id' => $id, 'data' => ['raw_payload' => $event]];
        }
        return ['action' => 'INITIAL_PAYMENT', 'external_id' => $id, 'data' => self::paymentData($link, $payment, $event)];
    }

    private static function refund(array $payload, array $event, string $eventId): array
    {
        $refund = $payload['refund']['entity'] ?? [];
        $payment = $payload['payment']['entity'] ?? [];
        $paymentId = (string) ($refund['payment_id'] ?? ($payment['id'] ?? ''));
        $currency = strtoupper((string) ($refund['currency'] ?? ($payment['currency'] ?? '')));
        if ($paymentId === '' || $currency === '') {
            throw new \UnexpectedValueException('Razorpay refund event without payment id or currency.');
        }
        $notes = array_merge(
            is_array($payment['notes'] ?? null) ? $payment['notes'] : [],
            is_array($refund['notes'] ?? null) ? $refund['notes'] : [],
        );
        $charged = (int) ($payment['amount'] ?? 0);
        $refunded = (int) ($payment['amount_refunded'] ?? ($refund['amount'] ?? 0));

        return [
            'action'      => 'REFUND_PROCESSED',
            'external_id' => self::eventId($eventId, 'refund.processed', $payload),
            'data'        => [
                'external_transaction_id' => $paymentId,
                'external_refund_id'      => 'razorpay:refund:' . (string) ($refund['id'] ?? $paymentId),
                'internal_order_id'       => $notes['order_id'] ?? null,
                'external_client_id'      => $notes['external_client_id'] ?? null,
                'amount'                  => MinorUnits::fromMinor($refund['amount'] ?? 0, $currency),
                'currency'                => $currency,
                'full_refund'             => $charged > 0 && $refunded >= $charged,
                'refunded_total'          => $charged > 0 ? MinorUnits::fromMinor($refunded, $currency) : null,
                'raw_payload'             => $event,
            ],
        ];
    }

    /** X-Razorpay-Event-Id identifies a delivery uniquely; retries reuse it. */
    private static function eventId(string $headerId, string $type, array $payload): string
    {
        if ($headerId !== '' && RazorpaySignature::isSafeId($headerId)) {
            return "razorpay:{$headerId}";
        }
        $entityId = '';
        foreach (['refund', 'payment', 'payment_link'] as $entity) {
            if (!empty($payload[$entity]['entity']['id'])) {
                $entityId = (string) $payload[$entity]['entity']['id'];
                break;
            }
        }
        return sprintf('razorpay:%s:%s', $type !== '' ? $type : 'unknown', $entityId !== '' ? $entityId : hash('sha256', json_encode($payload) ?: ''));
    }
}
