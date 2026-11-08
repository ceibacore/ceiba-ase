<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * Creates Guzzle clients for payment providers with HttpDefaults applied.
 * Tests can swap the transport with useHandler() (e.g. a Guzzle MockHandler).
 */
final class HttpClientFactory
{
    private static ?HandlerStack $handler = null;

    private function __construct() {}

    public static function create(array $options = []): Client
    {
        $options = HttpDefaults::OPTIONS + $options;
        if (self::$handler !== null) {
            $options['handler'] = self::$handler;
        }
        return new Client($options);
    }

    /** @internal Test seam: route every new client through this handler (null restores the network). */
    public static function useHandler(?HandlerStack $handler): void
    {
        self::$handler = $handler;
    }
}
