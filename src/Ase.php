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
 *       'ASE_DB_HOST'   => '127.0.0.1',
 *       'ASE_DB_PORT'   => '3306',
 *       'ASE_DB_NAME'   => 'my_database',
 *       'ASE_DB_USER'   => 'db_user',
 *       'ASE_DB_PASS'   => 'secret',
 *       'ASE_DB_PREFIX' => 'ase_',
 *       'ASE_SECRET_KEY'=> 'signing_secret',
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
}
