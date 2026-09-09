<?php

declare(strict_types=1);

namespace App\Core;

class Config
{
    private static array $env = [];

    public static function load(?string $envFile = null): void
    {
        if ($envFile === null) {
            $envFile = dirname(__DIR__, 2) . '/.env';
        }

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $val] = explode('=', $line, 2);
                    $key = trim($key);
                    $val = trim($val);
                    // Remove surrounding quotes if present
                    if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                        (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                        $val = substr($val, 1, -1);
                    }
                    self::$env[$key] = $val;
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $val;
                    }
                }
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: (self::$env[$key] ?? $default);
    }
}
