<?php

namespace LemurAse\Domain\Services;

use LemurAse\Shared\Infrastructure\EnvironmentGuard;

final class SecurityService
{
    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = EnvironmentGuard::get('GATEWAY_SERVICE_SECRET');
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
