<?php

namespace LemurAse\Domain\ValueObjects;

final class Currency
{
    private function __construct(private readonly string $code)
    {
        if (strlen($code) !== 3) {
            throw new \InvalidArgumentException("Currency code must be 3 characters.");
        }
    }

    public static function fromString(string $code): self
    {
        return new self(strtoupper($code));
    }

    public function toString(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
