<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 5;

    public function isRateLimited(string $identifier, string $ip): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM login_attempts
            WHERE (identifier = :identifier OR ip_address = :ip)
              AND attempted_at >= datetime("now", "-' . self::WINDOW_MINUTES . ' minutes")
        ');
        $stmt->execute([
            ':identifier' => strtolower(trim($identifier)),
            ':ip' => $ip,
        ]);

        return ((int) $stmt->fetchColumn()) >= self::MAX_ATTEMPTS;
    }

    public function recordFailedAttempt(string $identifier, string $ip): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            INSERT INTO login_attempts (identifier, ip_address, attempted_at)
            VALUES (:identifier, :ip, datetime("now"))
        ');
        $stmt->execute([
            ':identifier' => strtolower(trim($identifier)),
            ':ip' => $ip,
        ]);

        // Prune old entries randomly (1 in 20 chance)
        if (random_int(1, 20) === 1) {
            $this->prune();
        }
    }

    public function clearAttempts(string $identifier, string $ip): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            DELETE FROM login_attempts
            WHERE identifier = :identifier OR ip_address = :ip
        ');
        $stmt->execute([
            ':identifier' => strtolower(trim($identifier)),
            ':ip' => $ip,
        ]);
    }

    public function prune(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < datetime("now", "-1 hour")');
    }
}
