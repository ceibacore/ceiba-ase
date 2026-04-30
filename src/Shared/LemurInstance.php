<?php

namespace LemurAse\Shared;

use LemurDB;

require_once __DIR__ . '/../../lemurdb/lemurdb.php';

class LemurInstance
{
    public static function get(): LemurDB
    {
        EnvironmentGuard::check();

        return LemurDB::getInstance([
            'driver'   => self::getEnv('ASE_DB_DRIVER', 'mysql'),
            'host'     => self::getEnv('ASE_DB_HOST'),
            'port'     => self::getEnv('ASE_DB_PORT', 3306),
            'db'       => self::getEnv('ASE_DB_NAME'),
            'username' => self::getEnv('ASE_DB_USER'),
            'password' => self::getEnv('ASE_DB_PASS'),
            'prefix'   => '', // Tables are already prefixed with ase_
        ]);
    }

    private static function getEnv(string $key, $default = null)
    {
        return EnvironmentGuard::get($key, $default);
    }
}
