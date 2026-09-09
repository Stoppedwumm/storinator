<?php

declare(strict_types=1);

namespace App\BigStore;

use App\Core\Config;
use RuntimeException;

class BigStoreClient
{
    private string $baseUrl;
    private string $secretToken;

    public function __construct(?string $baseUrl = null, ?string $secretToken = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? Config::get('BIGSTORE_URL', 'http://bigstore:8080'), '/');
        $this->secretToken = $secretToken ?? Config::get('INTERNAL_BIGSTORE_SECRET', '');
    }

    public function checkHealth(): array
    {
        $url = "{$this->baseUrl}/internal/health";
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                "X-Internal-Service-Token: {$this->secretToken}",
            ],
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $latencyMs = round((microtime(true) - $startTime) * 1000, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'status' => 'unreachable',
                'error' => $curlError ?: 'Connection failed',
                'latency_ms' => $latencyMs,
                'target_url' => $url,
            ];
        }

        $decoded = json_decode($response, true);
        if ($httpCode === 200 && is_array($decoded) && ($decoded['success'] ?? false) === true) {
            return array_merge([
                'status' => 'healthy',
                'http_code' => $httpCode,
                'latency_ms' => $latencyMs,
            ], $decoded['data'] ?? []);
        }

        return [
            'status' => 'degraded',
            'http_code' => $httpCode,
            'response' => $decoded ?? $response,
            'latency_ms' => $latencyMs,
        ];
    }
}
