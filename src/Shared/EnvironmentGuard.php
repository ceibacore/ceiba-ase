<?php

namespace LemurAse\Shared;

class EnvironmentGuard
{
    private static array $requiredVars = [
        'ASE_DB_HOST',
        'ASE_DB_PORT',
        'ASE_DB_NAME',
        'ASE_DB_USER',
        'ASE_DB_PASS',
        'ASE_SECRET_KEY'
    ];

    public static function check(): void
    {
        $missing = [];
        foreach (self::$requiredVars as $var) {
            if (!isset($_ENV[$var]) && !getenv($var)) {
                $missing[] = $var;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                "Agnostic Subscription Engine (ASE) is missing required environment variables: " . 
                implode(', ', $missing)
            );
        }
    }

    public static function get(string $key, $default = null)
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
