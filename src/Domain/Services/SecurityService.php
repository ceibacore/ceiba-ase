<?php

namespace LemurAse\Domain\Services;

use LemurAse\Shared\EnvironmentGuard;

final class SecurityService
{
    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = EnvironmentGuard::get('ASE_SECRET_KEY');
    }

    public function generateHash(array $data): string
    {
        ksort($data);
        $payload = json_encode($data);
        return hash_hmac('sha256', $payload, $this->secretKey);
    }

    public function validateHash(array $data, string $hash): bool
    {
        return hash_equals($this->generateHash($data), $hash);
    }
}
