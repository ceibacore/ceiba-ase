<?php

namespace LemurAse\Shared\Infrastructure;

class EnvironmentGuard
{
    private static array $requiredVars = [
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'GATEWAY_SERVICE_SECRET'
    ];

    public static function check(): void
    {
        $missing = [];
        foreach (self::$requiredVars as $var) {
            if (!isset($_ENV[$var]) && !getenv($var)) {
                $missing[] = $var;
            }
        }

        if (!empty($missing)) {
            self::loadFromEnvFile();
            
            $missing = [];
            foreach (self::$requiredVars as $var) {
                if (!isset($_ENV[$var]) && !getenv($var)) {
                    $missing[] = $var;
                }
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                "Agnostic Subscription Engine (ASE) is missing required environment variables: " . 
                implode(', ', $missing) . "\n" .
                "Set the required environment variables before running migrations."
            );
        }
    }

    private static function loadFromEnvFile(): void
    {
        $envFile = self::findEnvFile();
        
        if (!$envFile || !file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (empty($key)) {
                continue;
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    private static function findEnvFile(): ?string
    {
        $projectRoot = dirname(__DIR__, 2);
        $currentDir = $projectRoot;
        
        for ($i = 0; $i < 3; $i++) {
            $envPath = $currentDir . DIRECTORY_SEPARATOR . '.env';
            
            if (file_exists($envPath) && is_readable($envPath)) {
                return $envPath;
            }

            $parentDir = dirname($currentDir);
            
            if ($parentDir === $currentDir) {
                break;
            }

            $currentDir = $parentDir;
        }

        return null;
    }

    public static function get(string $key, $default = null)
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
