<?php

declare(strict_types=1);

namespace LemurAse\EventBus\Infrastructure;

use LemurAse\Shared\Domain\Events\DomainEvent;
use LemurAse\Shared\Domain\Events\EventDispatcherInterface;

/**
 * SyncEventDispatcher — Pure PHP, zero-dependency dispatcher.
 *
 * Executes all registered listeners synchronously in the same process.
 * This is the default dispatcher bundled with lemur-ase and works with
 * any PHP application (Laravel, Symfony, plain PHP, etc.).
 *
 * ┌────────────────────────────────────────────────────────────────────┐
 * │  HOW TO USE IT                                                      │
 * │                                                                     │
 * │  // 1. Instantiate (typically in a service provider or bootstrap)   │
 * │  $dispatcher = new SyncEventDispatcher();                           │
 * │                                                                     │
 * │  // 2. Register listeners                                           │
 * │  $dispatcher->addListener(                                          │
 * │      GatewayFormConfiguredEvent::class,                             │
 * │      fn($event) => AseManager::upsertGateway(                       │
 * │          $event->provider(), $event->credentials(), $event->isActive() │
 * │      )                                                              │
 * │  );                                                                 │
 * │                                                                     │
 * │  // 3. Inject into use cases that need it                           │
 * │  $useCase = new ProcessGatewayFormSubmission($dispatcher);          │
 * │                                                                     │
 * │  FRAMEWORK USERS: replace this with your own adapter that wraps     │
 * │  your framework's event bus — see LaravelEventDispatcher example    │
 * │  in your host application.                                          │
 * └────────────────────────────────────────────────────────────────────┘
 */
final class SyncEventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, callable[]> eventClass => listeners[] */
    private array $listeners = [];

    /**
     * Register a listener for a specific domain event class.
     *
     * @param string   $eventClass  Fully-qualified class name of the event
     * @param callable $listener    Any callable: closure, invokable, [obj, method]
     */
    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * Dispatch one or more domain events synchronously.
     * All listeners for each event are called in registration order.
     */
    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $eventClass = $event::class;

            foreach ($this->listeners[$eventClass] ?? [] as $listener) {
                $listener($event);
            }
        }
    }
}
