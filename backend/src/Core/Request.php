<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $headers;
    private array $queryParams;
    private array $body;
    private string $rawBody;
    private array $cookies;
    private ?array $user = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = parse_url($this->uri, PHP_URL_PATH) ?: '/';
        $this->headers = $this->extractHeaders();
        $this->queryParams = $_GET;
        $this->cookies = $_COOKIE;
        $this->rawBody = file_get_contents('php://input') ?: '';
        $this->body = $this->parseBody();
    }

    private function extractHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$headerName] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headerName = str_replace('_', '-', strtolower($key));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    private function parseBody(): array
    {
        if ($this->rawBody === '') {
            return $_POST ?: [];
        }

        $contentType = $this->getHeader('content-type') ?? '';
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($this->rawBody, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST ?: [];
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getQuery(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }
        return $this->queryParams[$key] ?? $default;
    }

    public function getBody(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function getCookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getBearerToken(): ?string
    {
        $authHeader = $this->getHeader('authorization');
        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function getAuthToken(): ?string
    {
        return $this->getBearerToken() ?? $this->getCookie('platform_session');
    }

    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getClientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}
