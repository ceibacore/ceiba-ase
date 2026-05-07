<?php

declare(strict_types=1);

namespace LemurAse\Shared\Domain\Events;

interface DomainEvent
{
    /**
     * Get the name of the event.
     */
    public function eventName(): string;

    /**
     * Get the data associated with the event.
     */
    public function payload(): array;

    /**
     * Get the timestamp when the event occurred.
     */
    public function occurredOn(): \DateTimeImmutable;
}
