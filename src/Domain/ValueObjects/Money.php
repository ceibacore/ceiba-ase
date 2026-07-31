<?php

namespace LemurAse\Domain\ValueObjects;

final class Money
{
    private function __construct(
        private readonly float $amount,
        private readonly Currency $currency
    ) {}

    public static function create(float $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency->equals($other->currency);
    }
}
