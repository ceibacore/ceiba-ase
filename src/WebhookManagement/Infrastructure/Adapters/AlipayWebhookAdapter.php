<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;

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

        // ── Verify RSA signature ─────────────────────────────────
        $signatureHeader = $headers['signature']
            ?? $headers['Signature']
            ?? $headers['SIGNATURE']
            ?? '';

        if (empty($signatureHeader)) {
            throw new \Exception('Missing Signature header');
        }

        $sigValue = '';
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2 && $kv[0] === 'signature') {
                $sigValue = $kv[1];
                break;
            }
        }

        if (empty($sigValue)) {
            throw new \Exception('Invalid Signature header format');
        }

        $alipayPublicKey = $config['alipay_public_key']
            ?? throw new \Exception('Missing alipay_public_key in config');

        if (!str_contains($alipayPublicKey, '-----BEGIN')) {
            $alipayPublicKey = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($alipayPublicKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        }

        $clientId = $headers['client-id'] ?? $headers['Client-Id'] ?? $config['client_id'] ?? '';
        $requestTime = $headers['request-time'] ?? $headers['Request-Time'] ?? '';

        $content = sprintf("POST /webhooks/alipay\n%s.%s.%s", $clientId, $requestTime, $payload);
        $decodedSig = base64_decode($sigValue, true);

        if ($decodedSig === false || openssl_verify($content, $decodedSig, $alipayPublicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \Exception('Alipay webhook RSA signature verification failed');
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

        $normalizedData = [
            'external_subscription_id' => (string) ($decoded['paymentId'] ?? ''),
            'external_transaction_id'  => (string) ($decoded['paymentId'] ?? ''),
            'amount'                   => (float) $paymentAmount / 100,
            'currency'                 => strtoupper($decoded['paymentAmount']['currency'] ?? 'USD'),
            'internal_order_id'        => $decoded['paymentRequestId'] ?? null,
            'external_client_id'       => $decoded['paymentRequestId'] ?? null,
            'raw_payload'              => $decoded,
        ];

        return new WebhookEvent(
            action:     $action,
            data:       $normalizedData,
            externalId: (string) ($decoded['paymentId'] ?? 'alipay_unknown'),
            provider:   'alipay',
            rawPayload: $decoded,
        );
    }
}
