<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\Paystack;

use LemurAse\Domain\ValueObjects\MinorUnits;

/**
 * Maps verified Paystack events to ceiba-ase actions.
 *
 * - charge.success  → INITIAL_PAYMENT (amount in the currency's subunit, order from metadata)
 * - refund.processed → REFUND_PROCESSED (full when the refunds cover the transaction)
 * - anything else   → UNKNOWN (acknowledged, ignored)
 */
final class PaystackEvents
{
    private function __construct() {}

    /** @return array{action: string, external_id: string, data: array} */
    public static function normalize(array $event, PaystackClient $api): array
    {
        $type = (string) ($event['event'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        return match ($type) {
            'charge.success'   => self::charge($data, $event),
            'refund.processed' => self::refund($data, $event, $api),
            default            => ['action' => 'UNKNOWN', 'external_id' => self::eventId($type, $data), 'data' => ['raw_payload' => $event]],
        };
    }

    /** Normalized INITIAL_PAYMENT data from a Paystack transaction object (webhook or verify). */
    public static function paymentData(array $transaction, array $raw): array
    {
        $currency = strtoupper((string) ($transaction['currency'] ?? ''));
        $metadata = self::metadata($transaction['metadata'] ?? null);
        $reference = (string) ($transaction['reference'] ?? '');

        return [
            'external_subscription_id' => $reference,
            'external_transaction_id'  => $reference,
            'amount'                   => $currency !== '' ? MinorUnits::fromMinor($transaction['amount'] ?? 0, $currency) : null,
            'currency'                 => $currency,
            'internal_order_id'        => $metadata['order_id'] ?? null,
            'external_client_id'       => $metadata['external_client_id'] ?? null,
            'raw_payload'              => $raw,
        ];
    }

    private static function charge(array $data, array $event): array
    {
        if (($data['status'] ?? '') !== 'success') {
            return ['action' => 'UNKNOWN', 'external_id' => self::eventId('charge.success', $data), 'data' => ['raw_payload' => $event]];
        }
        return [
            'action'      => 'INITIAL_PAYMENT',
            'external_id' => self::eventId('charge.success', $data),
            'data'        => self::paymentData($data, $event),
        ];
    }

    private static function refund(array $data, array $event, PaystackClient $api): array
    {
        $reference = (string) ($data['transaction_reference'] ?? ($data['transaction']['reference'] ?? ''));
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($reference === '' || $currency === '') {
            throw new \UnexpectedValueException('Paystack refund event without transaction reference or currency.');
        }
        // The refund event does not say whether it covers the whole charge: compare with it.
        $transaction = $api->verify($reference);
        $charged = (int) ($transaction['amount'] ?? 0);
        $refunded = (int) ($data['amount'] ?? 0);
        $metadata = self::metadata($transaction['metadata'] ?? null);
        $refundId = (string) ($data['id'] ?? ($data['refund_reference'] ?? ''));

        return [
            'action'      => 'REFUND_PROCESSED',
            'external_id' => self::eventId('refund.processed', $data),
            'data'        => [
                'external_transaction_id' => $reference,
                'external_refund_id'      => $refundId !== '' ? "paystack:refund:{$refundId}" : "paystack:refund:{$reference}:{$refunded}",
                'internal_order_id'       => $metadata['order_id'] ?? null,
                'external_client_id'      => $metadata['external_client_id'] ?? null,
                'amount'                  => MinorUnits::fromMinor($refunded, $currency),
                'currency'                => $currency,
                'full_refund'             => $charged > 0 && $refunded >= $charged,
                'raw_payload'             => $event,
            ],
        ];
    }

    /** Paystack events carry no event id: the event type plus the object id is unique. */
    private static function eventId(string $type, array $data): string
    {
        $id = (string) ($data['id'] ?? ($data['reference'] ?? ($data['transaction_reference'] ?? '')));
        if ($id === '') {
            $id = hash('sha256', json_encode($data) ?: '');
        }
        return sprintf('paystack:%s:%s', $type !== '' ? $type : 'unknown', $id);
    }

    /** Paystack returns metadata as an object, or as the JSON string it was sent as. */
    private static function metadata(mixed $metadata): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        return is_array($metadata) ? $metadata : [];
    }
}
