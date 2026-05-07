<?php

declare(strict_types=1);

namespace LemurAse\Shared\Domain\Events;

interface EventDispatcherInterface
{
    /**
     * Dispatch one or more domain events.
     */
    public function dispatch(DomainEvent ...$events): void;
}
