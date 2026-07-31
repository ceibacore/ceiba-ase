<?php

namespace LemurAse\Shared\Infrastructure;

use LemurDB;

require_once __DIR__ . '/../../../lemurdb/lemurdb.php';

class LemurInstance
{
    public static function get(): LemurDB
    {
        EnvironmentGuard::check();

        // Leer primero variables con prefijo ASE_* (escritas por AseBridge).
        // Si no existen, caer en las genéricas DB_* como fallback.
        // Esto evita heredar DB_PREFIX=cms_ que CmsBridge escribe en $_ENV.
        $db = LemurDB::getInstance([
            'driver'   => self::getEnv('ASE_DB_DRIVER',   self::getEnv('DB_CONNECTION', 'mysql')),
            'host'     => self::getEnv('ASE_DB_HOST',     self::getEnv('DB_HOST')),
            'port'     => self::getEnv('ASE_DB_PORT',     self::getEnv('DB_PORT', 3306)),
            'db'       => self::getEnv('ASE_DB_DATABASE', self::getEnv('DB_DATABASE')),
            'username' => self::getEnv('ASE_DB_USERNAME', self::getEnv('DB_USERNAME')),
            'password' => self::getEnv('ASE_DB_PASSWORD', self::getEnv('DB_PASSWORD')),
            'prefix'   => self::getEnv('ASE_DB_PREFIX',   self::getEnv('DB_PREFIX', 'ase_')),
        ]);

        // Guard: catch prefix contamination from CMS env at runtime.
        $resolvedPrefix = $db->getPrefix();
        if ($resolvedPrefix !== 'ase_') {
            throw new \RuntimeException(
                "[LemurInstance] Prefix collision detected: expected 'ase_', got '{$resolvedPrefix}'. " .
                "Ensure ASE_DB_PREFIX is set before calling LemurInstance::get()."
            );
        }

        return $db;
    }

    private static function getEnv(string $key, $default = null)
    {
        return EnvironmentGuard::get($key, $default);
    }
}
