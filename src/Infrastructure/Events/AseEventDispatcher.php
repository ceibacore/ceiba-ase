<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Events;

use LemurAse\Infrastructure\Persistence\TableNames;
use LemurAse\Shared\Infrastructure\CeibaInstance;
use Ramsey\Uuid\Uuid;

/**
 * Domain Event Dispatcher & Outbox Writer for the Agnostic Subscription Engine.
 * 
 * Persists domain events into the transactional Outbox (`ase_outbox_events`)
 * and bridges them to host application listeners in memory.
 */
final class AseEventDispatcher
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    private function __construct() {}

    /**
     * Register a listener for a specific event.
     */
    public static function listen(string $event, callable $listener): void
    {
        if (!isset(self::$listeners[$event])) {
            self::$listeners[$event] = [];
        }

        self::$listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event to both the persistent Outbox and in-memory listeners.
     */
    public static function dispatch(string $event, mixed $payload = null, ?string $aggregateType = null, ?string $aggregateId = null): void
    {
        // 1. Persist to outbox table for at-least-once transactional guarantee
        try {
            self::recordOutbox($event, $payload, $aggregateType, $aggregateId);
        } catch (\Throwable $e) {
            error_log(sprintf(
                "ASE Event Dispatcher Outbox Error for '%s': %s",
                $event,
                $e->getMessage()
            ));
        }

        // 2. In-memory execution for registered listeners
        if (!isset(self::$listeners[$event])) {
            return;
        }

        foreach (self::$listeners[$event] as $listener) {
            try {
                $listener($payload);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    "ASE Event Dispatcher Error in listener for '%s': %s",
                    $event,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Persist an event to the ase_outbox_events table.
     */
    public static function recordOutbox(string $event, mixed $payload, ?string $aggregateType = null, ?string $aggregateId = null): string
    {
        $aggType = $aggregateType ?? (str_starts_with($event, 'subscription.') ? 'subscription' : 'order');
        
        $resolvedAggId = $aggregateId;
        if (!$resolvedAggId && is_array($payload)) {
            $resolvedAggId = $payload['subscription_id'] ?? ($payload['order_id'] ?? null);
        }
        if (!$resolvedAggId) {
            $resolvedAggId = (string) Uuid::uuid7();
        }

        $eventId = (string) Uuid::uuid7();

        $db = CeibaInstance::get();
        $db->query(TableNames::OUTBOX_EVENTS)->insert([
            'id'             => $eventId,
            'event_name'     => $event,
            'aggregate_type' => $aggType,
            'aggregate_id'   => (string) $resolvedAggId,
            'payload'        => is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'         => 'pending',
            'attempts'       => 0,
            'error_message'  => null,
            'published_at'   => null,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $eventId;
    }

    /**
     * Remove all listeners for a specific event or all events if none provided.
     */
    public static function forget(?string $event = null): void
    {
        if ($event === null) {
            self::$listeners = [];
            return;
        }

        unset(self::$listeners[$event]);
    }
}
