<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;
use LemurAse\Infrastructure\Payments\Alipay\AlipaySignature;
use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Infrastructure\Http\WebhookUrls;
use LemurAse\WebhookManagement\Domain\WebhookSignatureException;

/**
 * Alipay Global (Antom) webhook adapter.
 *
 * Validates RSA256 digital signature from the Signature header
 * and normalizes notification payload into a WebhookEvent.
 */
final class AlipayWebhookAdapter implements WebhookAdapterInterface
{
    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON payload');
        }

        // ── Verify RSA signature and time window ────────────────
        try {
            AlipaySignature::verify(
                $payload,
                $headers,
                (string) ($config['alipay_public_key'] ?? ''),
                (string) ($config['client_id'] ?? ''),
                self::notifyPath($config),
                (int) ($config['signature_tolerance'] ?? AlipaySignature::DEFAULT_TOLERANCE)
            );
        } catch (\InvalidArgumentException $e) {
            throw new WebhookSignatureException($e->getMessage(), 0, $e);
        }

        // ── Map action ───────────────────────────────────────────
        $result = $decoded['result'] ?? [];
        $resultStatus = $result['resultStatus'] ?? '';
        $notifyType = $decoded['notifyType'] ?? 'notifyPayment';

        $action = match ($resultStatus) {
            'S'     => ($notifyType === 'notifyRefund') ? 'REFUND_PROCESSED' : 'INITIAL_PAYMENT',
            'F'     => 'PAYMENT_FAILED',
            default => 'UNKNOWN',
        };

        $paymentAmount = $decoded['paymentAmount']['value'] ?? $decoded['refundAmount']['value'] ?? 0;
        $currency = strtoupper((string) ($decoded['paymentAmount']['currency'] ?? $decoded['refundAmount']['currency'] ?? 'USD'));

        $normalizedData = [
            'external_subscription_id' => (string) ($decoded['paymentId'] ?? ''),
            'external_transaction_id'  => (string) ($decoded['paymentId'] ?? ''),
            // Antom amounts are in the currency's minor unit.
            'amount'                   => MinorUnits::fromMinor($paymentAmount, $currency),
            'currency'                 => $currency,
            'internal_order_id'        => $decoded['paymentRequestId'] ?? null,
            'external_client_id'       => $decoded['paymentRequestId'] ?? null,
            'raw_payload'              => $decoded,
        ];

        return new WebhookEvent(
            action:     $action,
            data:       $normalizedData,
            // Payment and refund notifications share paymentId: key per notification type.
            externalId: sprintf('alipay:%s:%s', $notifyType, (string) ($decoded['refundId'] ?? $decoded['paymentId'] ?? 'unknown')),
            provider:   'alipay',
            rawPayload: $decoded,
        );
    }

    /**
     * Antom signs "POST {path}" of the URL it was told to notify. That URL comes from
     * WebhookUrls (the same one sent at checkout); an explicit notify_path credential wins.
     */
    private static function notifyPath(array $config): string
    {
        $configured = trim((string) ($config['notify_path'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        return WebhookUrls::pathFor('alipay') ?? AlipaySignature::DEFAULT_NOTIFY_PATH;
    }
}
