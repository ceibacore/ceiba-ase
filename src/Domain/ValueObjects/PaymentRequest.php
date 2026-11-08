<?php

declare(strict_types=1);

namespace LemurAse\Domain\ValueObjects;

/** What a host application asks ceiba-ase to charge (AseManager::createPayment). */
final class PaymentRequest
{
    public function __construct(
        public readonly string $context,
        public readonly string $reference,
        public readonly string $externalClientId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $provider,
        public readonly string $description,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly ?string $accountRef = null,
        public readonly array $metadata = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,49}$/D', $context) || $context === 'subscription') {
            throw new \InvalidArgumentException("Invalid payment context '{$context}' ('subscription' is reserved for plan orders).");
        }
        if ($reference === '' || strlen($reference) > 100) {
            throw new \InvalidArgumentException('Payment reference must have 1 to 100 characters.');
        }
        if ($externalClientId === '') {
            throw new \InvalidArgumentException('Payment external client id is required.');
        }
        if (!is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }
        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw new \InvalidArgumentException("Invalid currency '{$currency}'.");
        }
        AccountRef::normalize($accountRef);
    }
}
