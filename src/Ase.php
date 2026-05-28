<?php

namespace LemurAse;

/**
 * Public entry point for the Agnostic Subscription Engine.
 *
 * Allows host applications to configure ASE programmatically
 * without relying on framework-specific bootstrapping.
 *
 * Usage:
 *   \LemurAse\Ase::boot([
 *       'DB_HOST'   => '127.0.0.1',
 *       'DB_PORT'   => '3306',
 *       'DB_DATABASE'   => 'my_database',
 *       'DB_USERNAME'   => 'db_user',
 *       'DB_PASSWORD'   => 'secret',
 *       'DB_PREFIX' => 'ase_',
 *       'GATEWAY_SERVICE_SECRET'=> 'signing_secret',
 *   ]);
 */
final class Ase
{
    private function __construct() {}

    /**
     * Configure ASE from a key-value array instead of relying solely on environment variables.
     * Writes values to both $_ENV and putenv() for maximum compatibility.
     *
     * @param array<string, string|int> $config
     */
    public static function boot(array $config = []): void
    {
        foreach ($config as $key => $value) {
            $value = (string) $value;
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    /**
     * Get the AseManager singleton.
     */
    public static function manager(): AseManager
    {
        return AseManager::getInstance();
    }

    /**
     * Reset the AseManager singleton instance.
     */
    public static function reset(): void
    {
        AseManager::reset();
    }
}
