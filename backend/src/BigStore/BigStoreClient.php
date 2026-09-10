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

    public function request(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $url = "{$this->baseUrl}/" . ltrim($path, '/');
        $ch = curl_init($url);

        $defaultHeaders = [
            "X-Internal-Service-Token: {$this->secretToken}",
            'Accept: application/json',
        ];

        if (is_array($body) || (is_object($body) && !($body instanceof \CURLFile))) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
            $defaultHeaders[] = 'Content-Type: application/json';
        } else {
            $payload = $body;
        }

        $finalHeaders = array_merge($defaultHeaders, $headers);

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $finalHeaders,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];

        if ($payload !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $curlOptions);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException("BigStore communication error: {$curlError}", 502);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid response format from BigStore: {$rawResponse}", 502);
        }

        if ($httpCode >= 400 || !($decoded['success'] ?? false)) {
            $errorCode = $decoded['error']['code'] ?? 'BIGSTORE_ERROR';
            $errorMsg = $decoded['error']['message'] ?? 'BigStore request failed';
            $exception = new RuntimeException($errorMsg, $httpCode);
            // Attach error code and details if needed
            throw new BigStoreException($errorMsg, $httpCode, $errorCode, $decoded['error']['details'] ?? null);
        }

        return $decoded['data'] ?? [];
    }

    public function getStorageQuota(string $ownerType, string $ownerId): array
    {
        return $this->request('GET', "/internal/storage/{$ownerType}/{$ownerId}");
    }

    public function createDirectory(string $ownerType, string $ownerId, string $name, ?string $parentId = null): array
    {
        return $this->request('POST', '/internal/directories', [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'name' => $name,
            'parent_id' => $parentId,
        ]);
    }

    public function listDirectories(string $ownerType, string $ownerId, ?string $parentId = null): array
    {
        $query = http_build_query(array_filter([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'parent_id' => $parentId,
        ], fn($v) => $v !== null));

        return $this->request('GET', "/internal/directories?{$query}");
    }

    public function getDirectory(string $id, ?string $ownerType = null, ?string $ownerId = null): ?array
    {
        $query = '';
        if ($ownerType && $ownerId) {
            $query = '?' . http_build_query(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
        }

        try {
            return $this->request('GET', "/internal/directories/{$id}{$query}");
        } catch (BigStoreException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function deleteDirectory(string $id, string $ownerType, string $ownerId): array
    {
        return $this->request('DELETE', "/internal/directories/{$id}", [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ]);
    }

    public function initUpload(array $params): array
    {
        return $this->request('POST', '/internal/uploads/init', $params);
    }

    public function uploadChunk(string $uploadId, int $chunkIndex, string $rawBinaryData): array
    {
        return $this->request(
            'PUT',
            "/internal/uploads/{$uploadId}/chunk/{$chunkIndex}",
            $rawBinaryData,
            ['Content-Type: application/octet-stream']
        );
    }

    public function finalizeUpload(string $uploadId, ?string $expectedSha256 = null): array
    {
        return $this->request('POST', "/internal/uploads/{$uploadId}/finalize", [
            'expected_sha256' => $expectedSha256,
        ]);
    }

    public function getUploadStatus(string $uploadId): array
    {
        return $this->request('GET', "/internal/uploads/{$uploadId}/status");
    }

    public function abortUpload(string $uploadId): array
    {
        return $this->request('DELETE', "/internal/uploads/{$uploadId}");
    }

    public function listFiles(string $ownerType, string $ownerId, ?string $directoryId = null): array
    {
        $query = http_build_query(array_filter([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'directory_id' => $directoryId,
        ], fn($v) => $v !== null));

        return $this->request('GET', "/internal/files?{$query}");
    }

    public function getFile(string $id, ?string $ownerType = null, ?string $ownerId = null): ?array
    {
        $query = '';
        if ($ownerType && $ownerId) {
            $query = '?' . http_build_query(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
        }

        try {
            return $this->request('GET', "/internal/files/{$id}{$query}");
        } catch (BigStoreException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function deleteFile(string $id, string $ownerType, string $ownerId): array
    {
        return $this->request('DELETE', "/internal/files/{$id}", [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ]);
    }

    public function renameFile(string $id, string $newName, string $ownerType, string $ownerId): array
    {
        return $this->request('PATCH', "/internal/files/{$id}", [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'name' => $newName,
        ]);
    }

    public function moveFile(string $id, ?string $newDirectoryId, string $ownerType, string $ownerId): array
    {
        return $this->request('PATCH', "/internal/files/{$id}", [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'directory_id' => $newDirectoryId,
        ]);
    }

    /**
     * Streams file download or Range streaming directly from BigStore to client output
     * Respects Spec Section 14 (raw path hiding) and Spec Section 830 (Range streaming)
     */
    public function streamFile(
        string $fileId,
        string $ownerType,
        string $ownerId,
        bool $asAttachment = false,
        ?string $rangeHeader = null
    ): void {
        $endpoint = $asAttachment ? 'download' : 'stream';
        $query = http_build_query(['owner_type' => $ownerType, 'owner_id' => $ownerId]);
        $url = "{$this->baseUrl}/internal/files/{$fileId}/{$endpoint}?{$query}";

        $ch = curl_init($url);

        $headers = [
            "X-Internal-Service-Token: {$this->secretToken}",
        ];

        if ($rangeHeader !== null) {
            $headers[] = "Range: {$rangeHeader}";
        }

        $headerSent = false;

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$headerSent) {
                $trimmed = trim($headerLine);
                if (empty($trimmed)) {
                    return strlen($headerLine);
                }

                // Forward essential streaming and content headers
                $allowedHeaders = [
                    'content-type',
                    'content-length',
                    'content-range',
                    'accept-ranges',
                    'content-disposition',
                    'etag',
                    'last-modified',
                ];

                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $key = strtolower(trim($parts[0]));
                    if (in_array($key, $allowedHeaders, true)) {
                        header($headerLine, false);
                    }
                }

                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$headerSent, $ch) {
                if (!$headerSent) {
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    http_response_code($httpCode);
                    $headerSent = true;
                }
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                return strlen($chunk);
            },
            CURLOPT_TIMEOUT => 300,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$headerSent && $httpCode >= 400) {
            http_response_code($httpCode);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'STREAM_ERROR',
                    'message' => "BigStore stream failed with status {$httpCode}",
                ],
            ]);
        }
    }
}
