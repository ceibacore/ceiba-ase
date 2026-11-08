<?php

declare(strict_types=1);

namespace LemurAse\Domain\ValueObjects;

/**
 * Owner of gateway credentials and payments. null is the platform (instance owner);
 * otherwise '{type}:{id}', e.g. 'tenant:42' for an academy.
 */
final class AccountRef
{
    private const PATTERN = '/^[a-z][a-z0-9_]{1,30}:[A-Za-z0-9_-]{1,64}$/D';

    private function __construct() {}

    /** @throws \InvalidArgumentException */
    public static function normalize(?string $accountRef): ?string
    {
        if ($accountRef === null || $accountRef === '') {
            return null;
        }
        if (!preg_match(self::PATTERN, $accountRef)) {
            throw new \InvalidArgumentException("Invalid account reference '{$accountRef}': expected '{type}:{id}'.");
        }
        return $accountRef;
    }

    public static function tenant(int|string $tenantId): string
    {
        return (string) self::normalize('tenant:' . $tenantId);
    }
}
