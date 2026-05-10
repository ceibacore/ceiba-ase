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
            try {
                self::loadFromEnvFile();
            } catch (\RuntimeException $e) {
                fwrite(STDERR, "[ASE-DEBUG] Error: " . $e->getMessage() . "\n");
            }
            
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
                "Check your .env file or set them manually before running migrations."
            );
        }
    }

    private static function loadFromEnvFile(): void
    {
        $startDir = dirname(__DIR__, 3); 
        $envFile = self::findEnvFileRecursive($startDir, 0);
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            if (strpos($line, '=') === false) continue;

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    private static function findEnvFileRecursive(string $currentDir, int $depth): string
    {
        $envPath = $currentDir . DIRECTORY_SEPARATOR . '.env';
        $exists = file_exists($envPath);
        $readable = is_readable($envPath);
        $user = get_current_user();
        $uid = getmyuid();

        fwrite(STDERR, "[ASE-DEBUG] Checking: $envPath (Exists: " . ($exists ? 'Y' : 'N') . ", Readable: " . ($readable ? 'Y' : 'N') . ", User: $user, UID: $uid)\n");

        if ($exists && $readable) {
            return $envPath;
        }

        if ($depth >= 4) {
            throw new \RuntimeException("Exceeded search depth (4 levels).");
        }

        $parentDir = dirname($currentDir);
        if ($parentDir === $currentDir) {
            throw new \RuntimeException("Reached root.");
        }

        return self::findEnvFileRecursive($parentDir, $depth + 1);
    }

    public static function get(string $key, $default = null)
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
