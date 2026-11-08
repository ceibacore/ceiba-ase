<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Http;

/**
 * Reads a request header from the array a host passes to ceiba-ase, whatever its shape:
 * any letter case, and a string or a list of strings (Laravel/Symfony use lists).
 */
final class RequestHeaders
{
    private function __construct() {}

    public static function get(array $headers, string $name): string
    {
        $wanted = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $wanted) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            return is_scalar($value) ? trim((string) $value) : '';
        }
        return '';
    }
}
