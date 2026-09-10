<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;
use PDO;
use Throwable;

class EntryController
{
    private RateLimiter $rateLimiter;

    public function __construct(?RateLimiter $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
    }

    public function verify(Request $request): void
    {
        $ip = $request->getClientIp();

        // 1. Check brute force rate limiter for entry code / search attempts
        if ($this->rateLimiter->isEntryRateLimited($ip)) {
            Response::error(
                'TOO_MANY_ATTEMPTS',
                'Too many search queries. Please wait a few moments before trying again.',
                429
            );
        }

        $submittedCode = trim((string) ($request->getBody('code') ?? $request->getQuery('code', '')));

        if ($submittedCode === '') {
            Response::error('INVALID_INPUT', 'Search query cannot be empty.', 400);
        }

        $expectedCode = (string) Config::get('ENTRY_CODE', 'anticipation2026');
        $isMatch = false;

        // 2. Check environment configured entry code (constant-time comparison)
        if (hash_equals($expectedCode, $submittedCode)) {
            $isMatch = true;
        }

        // 3. Check active entry codes in database if not matched yet
        if (!$isMatch) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->query('SELECT id, code_hash FROM entry_codes WHERE is_active = 1');
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $hash = (string) ($row['code_hash'] ?? '');
                    if (
                        password_verify($submittedCode, $hash) ||
                        hash_equals($hash, hash('sha256', $submittedCode)) ||
                        hash_equals($hash, $submittedCode)
                    ) {
                        $isMatch = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                error_log('[EntryController DB Check Error] ' . $e->getMessage());
            }
        }

        // 4. Handle mismatch: record failed attempt and obscure response
        if (!$isMatch) {
            $this->rateLimiter->recordEntryAttempt($ip);
            Response::error(
                'NO_RESULTS',
                'No public platform documentation or resources matched your query.',
                404
            );
        }

        // 5. Successful match: reset rate limiter for this IP
        $this->rateLimiter->clearEntryAttempts($ip);

        // Audit log entry
        try {
            $pdo = Database::getConnection();
            $logStmt = $pdo->prepare('
                INSERT INTO audit_logs (actor_id, action, target_type, ip_address, metadata)
                VALUES (NULL, :action, "platform_entry", :ip, :metadata)
            ');
            $logStmt->execute([
                ':action' => 'ENTRY_CODE_VERIFIED',
                ':ip' => $ip,
                ':metadata' => json_encode([
                    'user_agent' => $request->getUserAgent(),
                    'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $e) {
            error_log('[EntryController Audit Log Error] ' . $e->getMessage());
        }

        // Issue short-lived entry cookie (Spec Section 32)
        $cookieToken = bin2hex(random_bytes(24));
        $cookieHeader = [
            'Set-Cookie' => "platform_entry={$cookieToken}; Path=/; Max-Age=3600; SameSite=Lax; HttpOnly"
        ];

        Response::success([
            'message' => 'Platform entry access authorized.',
            'entry_unlocked' => true,
            'redirect' => '#/login',
        ], 200, $cookieHeader);
    }

    public function status(Request $request): void
    {
        $entryCookie = $request->getCookie('platform_entry');
        $hasAuthUser = $request->getUser() !== null;

        Response::success([
            'unlocked' => (!empty($entryCookie) || $hasAuthUser),
        ]);
    }
}
