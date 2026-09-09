<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json(array $payload, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
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
