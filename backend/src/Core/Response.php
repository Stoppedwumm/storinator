<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    private static array $pendingCookies = [];

    public static function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = true,
        string $samesite = 'Lax'
    ): void {
        self::$pendingCookies[] = [
            'name' => $name,
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ];
    }

    public static function clearCookie(string $name, string $path = '/'): void
    {
        self::setCookie($name, '', time() - 3600, $path);
    }

    public static function json(array $payload, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        // Apply pending cookies
        foreach (self::$pendingCookies as $cookie) {
            setcookie(
                $cookie['name'],
                $cookie['value'],
                [
                    'expires' => $cookie['expires'],
                    'path' => $cookie['path'],
                    'domain' => $cookie['domain'],
                    'secure' => $cookie['secure'],
                    'httponly' => $cookie['httponly'],
                    'samesite' => $cookie['samesite'],
                ]
            );
        }
        self::$pendingCookies = [];

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(mixed $data = [], int $statusCode = 200, array $headers = []): void
    {
        self::json([
            'success' => true,
            'data' => $data,
        ], $statusCode, $headers);
    }

    public static function error(string $code, string $message, int $statusCode = 400, array $headers = []): void
    {
        self::json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $statusCode, $headers);
    }
}
