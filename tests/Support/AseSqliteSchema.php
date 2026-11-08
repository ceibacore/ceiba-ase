<?php

declare(strict_types=1);

namespace LemurAse\Tests\Support;

use CeibaDB;
use LemurAse\Migration\AseMigrationRunner;
use LemurAse\Migration\Dialect\SqliteDialect;

/**
 * In-memory SQLite database with the real ceiba-ase schema (every migration applied),
 * bound as the CeibaDB connection. Call reset() in tearDown().
 */
final class AseSqliteSchema
{
    private static array $previousEnv = [];

    public static function create(): \PDO
    {
        $env = ['ASE_DB_HOST' => '127.0.0.1', 'ASE_DB_PORT' => '3306', 'ASE_DB_DATABASE' => 'ase_test', 'ASE_DB_PREFIX' => 'ase_',
            'ASE_DB_USERNAME' => 'test', 'ASE_DB_PASSWORD' => 'test', 'GATEWAY_SERVICE_SECRET' => 'test-secret', 'APP_ENV' => 'testing'];
        foreach ($env as $key => $value) {
            self::$previousEnv[$key] ??= getenv($key);
            putenv("{$key}={$value}");
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        CeibaDB::setInstance($pdo, ['prefix' => 'ase_']);

        $result = (new AseMigrationRunner($pdo, dirname(__DIR__, 2) . '/Migrations/php', 'ase_', false, new SqliteDialect()))->migrate();
        if (!$result->success) {
            throw new \RuntimeException('ASE migrations failed on SQLite: ' . json_encode($result->errors));
        }

        return $pdo;
    }

    public static function reset(): void
    {
        CeibaDB::resetInstances();
        \LemurAse\AseManager::reset();
        foreach (self::$previousEnv as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
        self::$previousEnv = [];
    }
}
