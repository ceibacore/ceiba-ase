<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\Razorpay;

use LemurAse\Infrastructure\Http\RequestHeaders;

/**
 * Razorpay signs webhooks with X-Razorpay-Signature: HMAC-SHA256 of the exact raw body,
 * keyed with the webhook secret set in the dashboard (not the API key secret).
 */
final class RazorpaySignature
{
    private function __construct() {}

    /** @throws \InvalidArgumentException when the webhook must be rejected. */
    public static function verify(string $rawBody, array $headers, string $webhookSecret): void
    {
        if (trim($webhookSecret) === '') {
            throw new \InvalidArgumentException('Razorpay: webhook secret is not configured');
        }
        $signature = strtolower(RequestHeaders::get($headers, 'x-razorpay-signature'));
        if (!preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            throw new \InvalidArgumentException('Razorpay: missing or malformed X-Razorpay-Signature header');
        }
        if ($rawBody === '' || !hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), $signature)) {
            throw new \InvalidArgumentException('Razorpay webhook signature verification failed');
        }
    }

    /** Razorpay ids (plink_, pay_, rfnd_) are interpolated into API paths. */
    public static function isSafeId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/D', $id);
    }
}
