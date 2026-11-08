<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\Paystack;

use LemurAse\Infrastructure\Http\RequestHeaders;

/**
 * Paystack signs every webhook with x-paystack-signature: HMAC-SHA512 of the exact raw
 * request body, keyed with the account's secret key. The body must not be re-encoded.
 */
final class PaystackSignature
{
    private function __construct() {}

    /** @throws \InvalidArgumentException when the webhook must be rejected. */
    public static function verify(string $rawBody, array $headers, string $secretKey): void
    {
        if (trim($secretKey) === '') {
            throw new \InvalidArgumentException('Paystack: secret key is not configured');
        }
        $signature = strtolower(RequestHeaders::get($headers, 'x-paystack-signature'));
        if (!preg_match('/^[a-f0-9]{128}$/D', $signature)) {
            throw new \InvalidArgumentException('Paystack: missing or malformed x-paystack-signature header');
        }
        if ($rawBody === '' || !hash_equals(hash_hmac('sha512', $rawBody, $secretKey), $signature)) {
            throw new \InvalidArgumentException('Paystack webhook signature verification failed');
        }
    }

    /** References are interpolated into API paths: allow Paystack's reference alphabet only. */
    public static function isSafeReference(string $reference): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9.=_-]{1,100}$/D', $reference);
    }
}
