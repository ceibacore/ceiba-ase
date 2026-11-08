<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\Alipay;

/**
 * Verifies Antom/Alipay notification signatures (RSA-SHA256 over
 * "POST {path}\n{client-id}.{request-time}.{body}") and rejects notifications
 * whose request-time is outside the allowed window (replay protection).
 */
final class AlipaySignature
{
    public const DEFAULT_TOLERANCE = 900;
    public const DEFAULT_NOTIFY_PATH = '/webhooks/alipay';

    private function __construct() {}

    /** @throws \InvalidArgumentException when the notification must be rejected. */
    public static function verify(
        string $rawBody,
        array $headers,
        string $publicKey,
        string $fallbackClientId,
        string $notifyPath = self::DEFAULT_NOTIFY_PATH,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        $signatureHeader = self::header($headers, ['signature', 'Signature', 'SIGNATURE']);
        if ($signatureHeader === '') {
            throw new \InvalidArgumentException('Missing Signature header');
        }
        $sigValue = '';
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2 && $kv[0] === 'signature') {
                $sigValue = urldecode($kv[1]);
                break;
            }
        }
        $decodedSig = $sigValue === '' ? false : base64_decode($sigValue, true);
        if ($decodedSig === false || $decodedSig === '') {
            throw new \InvalidArgumentException('Invalid Signature header format');
        }
        if (trim($publicKey) === '') {
            throw new \InvalidArgumentException('Missing alipay_public_key in config');
        }
        if (!str_starts_with($notifyPath, '/') || preg_match('/\s/', $notifyPath)) {
            throw new \InvalidArgumentException('Invalid Alipay notify path');
        }
        $clientId = self::header($headers, ['client-id', 'Client-Id', 'CLIENT-ID']) ?: $fallbackClientId;
        $requestTime = self::header($headers, ['request-time', 'Request-Time', 'REQUEST-TIME']);
        if ($requestTime === '') {
            throw new \InvalidArgumentException('Missing request-time header');
        }

        $key = str_contains($publicKey, '-----BEGIN')
            ? $publicKey
            : "-----BEGIN PUBLIC KEY-----\n" . wordwrap($publicKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        $content = sprintf("POST %s\n%s.%s.%s", $notifyPath, $clientId, $requestTime, $rawBody);
        if (openssl_verify($content, $decodedSig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \InvalidArgumentException('Alipay webhook RSA signature verification failed');
        }

        $sent = self::timestamp($requestTime);
        if ($sent === null || $toleranceSeconds < 1 || abs(($now ?? time()) - $sent) > $toleranceSeconds) {
            throw new \InvalidArgumentException('Alipay: notification outside the allowed time window');
        }
    }

    /** request-time is either epoch milliseconds or an ISO 8601 date. */
    private static function timestamp(string $value): ?int
    {
        if (ctype_digit($value)) {
            return strlen($value) > 11 ? intdiv((int) $value, 1000) : (int) $value;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
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
