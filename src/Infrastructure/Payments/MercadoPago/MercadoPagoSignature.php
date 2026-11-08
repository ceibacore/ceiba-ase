<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\MercadoPago;

/**
 * Verifies Mercado Pago webhook signatures (x-signature: ts=...,v1=HMAC-SHA256 of
 * "id:{data.id};request-id:{x-request-id};ts:{ts};") and rejects notifications
 * outside the time window, which limits replay of captured requests.
 */
final class MercadoPagoSignature
{
    public const DEFAULT_TOLERANCE = 900;

    private function __construct() {}

    /** @throws \InvalidArgumentException when the notification must be rejected. */
    public static function verify(array $headers, string $dataId, string $secret, int $toleranceSeconds = self::DEFAULT_TOLERANCE, ?int $now = null): void
    {
        if (trim($secret) === '') {
            throw new \InvalidArgumentException('MercadoPago: webhook secret is not configured');
        }
        $xSignature = self::header($headers, ['x-signature', 'X-Signature', 'X-SIGNATURE']);
        $xRequestId = self::header($headers, ['x-request-id', 'X-Request-Id', 'X-REQUEST-ID']);
        if ($xSignature === '' || $xRequestId === '') {
            throw new \InvalidArgumentException('MercadoPago: missing x-signature or x-request-id headers');
        }
        if (!self::isSafeId($dataId)) {
            throw new \InvalidArgumentException('MercadoPago: invalid data.id');
        }

        $ts = '';
        $v1 = '';
        foreach (explode(',', $xSignature) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                if ($kv[0] === 'ts') {
                    $ts = $kv[1];
                } elseif ($kv[0] === 'v1') {
                    $v1 = $kv[1];
                }
            }
        }
        if (!ctype_digit($ts) || !preg_match('/^[a-f0-9]{64}$/D', $v1)) {
            throw new \InvalidArgumentException('MercadoPago: x-signature missing ts or v1 components');
        }

        $computed = hash_hmac('sha256', sprintf('id:%s;request-id:%s;ts:%s;', $dataId, $xRequestId, $ts), $secret);
        if (!hash_equals($computed, $v1)) {
            throw new \InvalidArgumentException('MercadoPago webhook signature verification failed');
        }

        // ts may be expressed in seconds or milliseconds.
        $seconds = strlen($ts) > 11 ? intdiv((int) $ts, 1000) : (int) $ts;
        if ($toleranceSeconds < 1 || abs(($now ?? time()) - $seconds) > $toleranceSeconds) {
            throw new \InvalidArgumentException('MercadoPago: notification outside the allowed time window');
        }
    }

    /** Payment ids are interpolated into API paths: allow only plain identifiers. */
    public static function isSafeId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id);
    }

    private static function header(array $headers, array $names): string
    {
        foreach ($names as $name) {
            $value = $headers[$name] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }
}
