<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\PayPal;

/**
 * Local checks on PayPal webhook transmission headers, run before any network
 * call. PayPal still verifies the signature remotely; this rejects forged
 * certificate URLs, unexpected algorithms and stale or future-dated (replayed)
 * transmissions without spending an API round trip.
 */
final class PayPalWebhookGuard
{
    /** Seconds a transmission may differ from the server clock. */
    public const DEFAULT_TOLERANCE = 900;

    private const ALGORITHMS = ['SHA256withRSA'];

    private const CERT_HOSTS = [
        'live'    => ['api.paypal.com', 'api-m.paypal.com'],
        'sandbox' => ['api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'],
    ];

    private function __construct() {}

    /** @throws \InvalidArgumentException when a header is implausible. */
    public static function assertPlausible(
        string $transmissionId,
        string $transmissionTime,
        string $certUrl,
        string $authAlgo,
        bool $sandbox,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/D', $transmissionId)) {
            throw new \InvalidArgumentException('PayPal: invalid transmission id');
        }
        if (!in_array($authAlgo, self::ALGORITHMS, true)) {
            throw new \InvalidArgumentException('PayPal: unsupported auth algorithm');
        }

        $parts = parse_url($certUrl);
        $hosts = self::CERT_HOSTS[$sandbox ? 'sandbox' : 'live'];
        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), $hosts, true)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || !str_starts_with($parts['path'] ?? '', '/v1/notifications/certs/')) {
            throw new \InvalidArgumentException('PayPal: certificate URL is not a PayPal certificate endpoint');
        }

        try {
            $sent = new \DateTimeImmutable($transmissionTime);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('PayPal: invalid transmission time');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $transmissionTime)) {
            throw new \InvalidArgumentException('PayPal: invalid transmission time');
        }
        $drift = abs(($now ?? time()) - $sent->getTimestamp());
        if ($toleranceSeconds < 1 || $drift > $toleranceSeconds) {
            throw new \InvalidArgumentException('PayPal: transmission time outside the allowed window');
        }
    }
}
