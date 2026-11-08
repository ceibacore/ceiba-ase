<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Http;

/**
 * Defaults for outbound HTTP calls to payment providers. Guzzle waits forever
 * by default; a degraded provider must not hang checkouts or webhook workers.
 */
final class HttpDefaults
{
    /** Seconds. Merge with `HttpDefaults::OPTIONS + [...]` so explicit options can still be added. */
    public const OPTIONS = [
        'connect_timeout' => 5,
        'timeout'         => 20,
    ];

    private function __construct() {}
}
