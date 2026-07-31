<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;

abstract class AggregateRoot
{
    protected function __construct(protected readonly EntityId $id) {}

    public function id(): EntityId
    {
        return $this->id;
    }
}
