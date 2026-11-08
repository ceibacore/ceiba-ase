<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Http;

/**
 * Public webhook URL of each provider, supplied by the host application:
 *
 *   WebhookUrls::resolveUsing(fn (string $provider) => route('webhooks.ase', $provider));
 *
 * Gateways that send the notification URL with each payment (Alipay) use it, and the
 * same URL gives the path they sign, so what is sent and what is verified always match.
 */
final class WebhookUrls
{
    /** @var (callable(string): ?string)|null */
    private static $resolver = null;

    private function __construct() {}

    /** @param (callable(string): ?string)|null $resolver */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Absolute http(s) URL without query or fragment, or null when the host gave none.
     *
     * @throws \InvalidArgumentException when the host returns an unusable URL.
     */
    public static function for(string $provider): ?string
    {
        if (self::$resolver === null) {
            return null;
        }
        $url = (self::$resolver)($provider);
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
    public static function pathFor(string $provider): ?string
    {
        $url = self::for($provider);
        return $url === null ? null : (string) parse_url($url, PHP_URL_PATH);
    }
}
