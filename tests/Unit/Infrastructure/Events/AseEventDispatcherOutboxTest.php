<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Infrastructure\Events;

use CeibaDB;
use LemurAse\Infrastructure\Events\AseEventDispatcher;
use PDO;
use PHPUnit\Framework\TestCase;

final class AseEventDispatcherOutboxTest extends TestCase
{
    private PDO $pdo;
    private array $previousEnv = [];

    protected function setUp(): void
    {
        $env = ['ASE_DB_HOST' => '127.0.0.1', 'ASE_DB_PORT' => '3306', 'ASE_DB_DATABASE' => 'ase_test', 'ASE_DB_PREFIX' => 'ase_',
            'ASE_DB_USERNAME' => 'test', 'ASE_DB_PASSWORD' => 'test', 'GATEWAY_SERVICE_SECRET' => 'test-secret'];
        foreach ($env as $key => $value) {
            $this->previousEnv[$key] = getenv($key);
            putenv("{$key}={$value}");
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ase_outbox_events (id TEXT PRIMARY KEY, event_name TEXT, aggregate_type TEXT, aggregate_id TEXT,
            payload TEXT, status TEXT, attempts INTEGER, error_message TEXT, published_at TEXT, created_at TEXT, updated_at TEXT)');
        CeibaDB::setInstance($this->pdo, ['prefix' => 'ase_']);
        AseEventDispatcher::forget();
    }

    protected function tearDown(): void
    {
        AseEventDispatcher::forget();
        CeibaDB::resetInstances();
        foreach ($this->previousEnv as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
    }

    public function testRecordsEventAndNotifiesListeners(): void
    {
        $received = [];
        AseEventDispatcher::listen('subscription.created', function ($payload) use (&$received): void {
            $received[] = $payload;
        });

        AseEventDispatcher::dispatch('subscription.created', ['order_id' => 'ord-1', 'amount' => 10.5], 'order', 'ord-1');

        $row = $this->pdo->query('SELECT * FROM ase_outbox_events')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('subscription.created', $row['event_name']);
        $this->assertSame('order', $row['aggregate_type']);
        $this->assertSame('ord-1', $row['aggregate_id']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(['order_id' => 'ord-1', 'amount' => 10.5], json_decode($row['payload'], true));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['id']);
        $this->assertSame([['order_id' => 'ord-1', 'amount' => 10.5]], $received);
    }

    public function testDerivesAggregateFromEventAndPayload(): void
    {
        AseEventDispatcher::dispatch('subscription.payment_failed', ['subscription_id' => 'sub-9']);
        AseEventDispatcher::dispatch('invoice.paid', 'raw-payload');

        $rows = $this->pdo->query('SELECT aggregate_type, aggregate_id, payload FROM ase_outbox_events ORDER BY event_name DESC')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['subscription', 'sub-9'], [$rows[0]['aggregate_type'], $rows[0]['aggregate_id']]);
        $this->assertSame('order', $rows[1]['aggregate_type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $rows[1]['aggregate_id'], 'Generated aggregate id');
        $this->assertSame('raw-payload', $rows[1]['payload']);
    }

    public function testOutboxFailureDoesNotBreakTheOperationOrListeners(): void
    {
        $this->pdo->exec('DROP TABLE ase_outbox_events');
        $called = false;
        AseEventDispatcher::listen('subscription.created', function () use (&$called): void {
            $called = true;
        });
        AseEventDispatcher::listen('subscription.created', function (): void {
            throw new \RuntimeException('listener bug');
        });

        $previousLog = ini_set('error_log', '/dev/null');
        try {
            AseEventDispatcher::dispatch('subscription.created', ['order_id' => 'ord-2']);
        } finally {
            ini_set('error_log', (string) $previousLog);
        }
        $this->assertTrue($called);
    }
}
