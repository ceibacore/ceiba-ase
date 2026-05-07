<?php

namespace LemurAse\Shared\Infrastructure;

use LemurDB;

require_once __DIR__ . '/../../../lemurdb/lemurdb.php';

class LemurInstance
{
    public static function get(): LemurDB
    {
        EnvironmentGuard::check();

        return LemurDB::getInstance([
            'driver'   => self::getEnv('ASE_DB_DRIVER', 'mysql'),
            'host'     => self::getEnv('DB_HOST'),
            'port'     => self::getEnv('DB_PORT', 3306),
            'db'       => self::getEnv('DB_DATABASE'),
            'username' => self::getEnv('DB_USERNAME'),
            'password' => self::getEnv('DB_PASSWORD'),
            'prefix'   => self::getEnv('DB_PREFIX', 'ase_'),
        ]);
    }

    private static function getEnv(string $key, $default = null)
    {
        return EnvironmentGuard::get($key, $default);
    }
}
