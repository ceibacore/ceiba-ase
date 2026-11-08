<?php

declare(strict_types=1);

namespace LemurAse\Domain\Gateways;

/**
 * Gateways that can check their credentials with a harmless call to the provider
 * (balance, account or token), before an administrator enables them.
 */
interface CredentialProbeInterface
{
    /**
     * @return array{ok: bool, livemode: ?bool, message: string}
     */
    public function probeCredentials(): array;
}
