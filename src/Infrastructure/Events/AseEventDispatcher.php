<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Events;

/**
 * Domain Event Dispatcher for the Agnostic Subscription Engine.
 * 
 * Allows host applications (like Laravel, Symfony) to listen to domain events
 * (e.g. 'subscription.created', 'invoice.paid') without needing to poll the database.
 */
final class AseEventDispatcher
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    private function __construct() {}

    /**
     * Register a listener for a specific event.
     * 
     * @param string $event The event name (e.g., 'invoice.generated')
     * @param callable $listener The callback to execute when the event fires
     */
    public static function listen(string $event, callable $listener): void
    {
        if (!isset(self::$listeners[$event])) {
            self::$listeners[$event] = [];
        }

        self::$listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners.
     * 
     * @param string $event The event name
     * @param mixed $payload The data passed to the listeners
     */
    public static function dispatch(string $event, mixed $payload = null): void
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }

        foreach (self::$listeners[$event] as $listener) {
            try {
                // Execute the listener
                $listener($payload);
            } catch (\Throwable $e) {
                // We log exceptions but DO NOT bubble them up to prevent
                // host application errors from breaking the ASE transaction.
                error_log(sprintf(
                    "ASE Event Dispatcher Error in listener for '%s': %s",
                    $event,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Remove all listeners for a specific event or all events if none provided.
     * (Useful for testing).
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
