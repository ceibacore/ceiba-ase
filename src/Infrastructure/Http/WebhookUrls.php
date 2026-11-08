<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Http;

/**
 * Public webhook URL of each provider, supplied by the host application:
 *
 *   WebhookUrls::resolveUsing(fn (string $provider, string $context, ?string $accountRef) => …);
 *
 * $context is 'subscription' for plan orders or the payment context (e.g. 'marketplace');
 * $accountRef is null for the platform's gateways or the academy owning the credentials.
 *
 * Gateways that send the notification URL with each payment (Alipay) use it, and the
 * same URL gives the path they sign, so what is sent and what is verified always match.
 */
final class WebhookUrls
{
    /** @var (callable(string, string, ?string): ?string)|null */
    private static $resolver = null;

    private function __construct() {}

    /** @param (callable(string, string, ?string): ?string)|null $resolver */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Absolute http(s) URL without query or fragment, or null when the host gave none.
     *
     * @throws \InvalidArgumentException when the host returns an unusable URL.
     */
    public static function for(string $provider, string $context = 'subscription', ?string $accountRef = null): ?string
    {
        if (self::$resolver === null) {
            return null;
        }
        $url = (self::$resolver)($provider, $context, $accountRef);
        if ($url === null || $url === '') {
            return null;
        }
        $parts = parse_url((string) $url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || !str_starts_with($parts['path'] ?? '', '/')) {
            throw new \InvalidArgumentException("Invalid webhook URL for {$provider}: absolute http(s) URL without query expected.");
        }
        return (string) $url;
    }

    /** Path part of the provider's webhook URL (what Alipay signs), or null. */
    public static function pathFor(string $provider, string $context = 'subscription', ?string $accountRef = null): ?string
    {
        $url = self::for($provider, $context, $accountRef);
        return $url === null ? null : (string) parse_url($url, PHP_URL_PATH);
    }
}
