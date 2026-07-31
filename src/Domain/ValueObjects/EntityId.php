<?php

namespace LemurAse\Domain\ValueObjects;

use Ramsey\Uuid\Uuid;

final class EntityId
{
    private function __construct(
        private readonly string $uuid,
        private readonly string $shortId,
    ) {}

    public static function generate(): self
    {
        $uuid   = Uuid::uuid4()->toString();
        $parts  = explode('-', $uuid);
        $shortId = end($parts);

        return new self($uuid, $shortId);
    }

    public static function fromString(string $uuid): self
    {
        $parts   = explode('-', $uuid);
        $shortId = end($parts);

        return new self($uuid, $shortId);
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function short(): string
    {
        return $this->shortId;
    }

    public function __toString(): string
    {
        return $this->uuid;
    }
}
