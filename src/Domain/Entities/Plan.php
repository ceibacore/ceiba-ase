<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;

final class Plan extends AggregateRoot
{
    public function __construct(
        EntityId $id,
        private string $slug,
        private string $name,
        private ?string $description = null,
        private bool $isActive = true
    ) {
        parent::__construct($id);
    }

    public function slug(): string { return $this->slug; }
    public function name(): string { return $this->name; }
    public function description(): ?string { return $this->description; }
    public function isActive(): bool { return $this->isActive; }
}
