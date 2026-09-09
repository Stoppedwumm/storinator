<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;
use PDO;

class AuthService
{
    private RateLimiter $rateLimiter;

    public function __construct(?RateLimiter $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
    }

    public function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false) {
            // Fallback to bcrypt if Argon2id is unexpectedly unavailable
            $hash = password_hash($password, PASSWORD_DEFAULT);
        }
        return $hash;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function registerCustomer(string $username, string $email, string $password): array
    {
        return $this->createUser($username, $email, $password, ['CUSTOMER']);
    }

    public function createUser(
        string $username,
        string $email,
        string $password,
        array $roles = ['CUSTOMER'],
        string $status = 'ACTIVE'
    ): array {
        $username = trim($username);
        $email = strtolower(trim($email));

        if (strlen($username) < 3 || strlen($username) > 32) {
            throw new Exception('Username must be between 3 and 32 characters.', 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
            throw new Exception('Username may only contain letters, numbers, underscores, and dashes.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address format.', 422);
        }
        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long.', 422);
        }

        $pdo = Database::getConnection();

        // Check uniqueness
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e)');
        $stmt->execute([':u' => $username, ':e' => $email]);
        if ($stmt->fetch()) {
            throw new Exception('A user with this username or email already exists.', 409);
        }

        $userId = 'usr_' . bin2hex(random_bytes(12));
        $walletId = 'wal_' . bin2hex(random_bytes(12));
        $passwordHash = $this->hashPassword($password);

        $pdo->beginTransaction();
        try {
            // Insert user
            $stmt = $pdo->prepare('
                INSERT INTO users (id, username, email, password_hash, status, created_at, updated_at)
                VALUES (:id, :username, :email, :hash, :status, datetime("now"), datetime("now"))
            ');
            $stmt->execute([
                ':id' => $userId,
                ':username' => $username,
                ':email' => $email,
                ':hash' => $passwordHash,
                ':status' => $status,
            ]);

            // Assign roles
            $roleStmt = $pdo->prepare('
                INSERT OR IGNORE INTO user_roles (user_id, role_id, assigned_at)
                VALUES (:user_id, :role_id, datetime("now"))
            ');
            foreach ($roles as $role) {
                $roleStmt->execute([
                    ':user_id' => $userId,
                    ':role_id' => strtoupper(trim($role)),
                ]);
            }

            // Create customer wallet with 0 integer cents
            $walletStmt = $pdo->prepare('
                INSERT INTO wallets (id, user_id, balance_cents, currency, created_at, updated_at)
                VALUES (:id, :user_id, 0, "EUR", datetime("now"), datetime("now"))
            ');
            $walletStmt->execute([
                ':id' => $walletId,
                ':user_id' => $userId,
            ]);

            // If user has PARTNER role, create partner profile record if missing
            if (in_array('PARTNER', $roles, true)) {
                $partnerStmt = $pdo->prepare('
                    INSERT OR IGNORE INTO partners (id, user_id, company_name, current_debt_cents, created_at, updated_at)
                    VALUES (:id, :user_id, :company, 0, datetime("now"), datetime("now"))
                ');
                $partnerStmt->execute([
                    ':id' => 'prt_' . bin2hex(random_bytes(12)),
                    ':user_id' => $userId,
                    ':company' => $username . ' Services',
                ]);
            }

            // Audit log
            $auditStmt = $pdo->prepare('
                INSERT INTO audit_logs (id, user_id, action, entity_type, entity_id, metadata, created_at)
                VALUES (:id, :user_id, "USER_REGISTERED", "users", :entity_id, :meta, datetime("now"))
            ');
            $auditStmt->execute([
                ':id' => 'aud_' . bin2hex(random_bytes(12)),
                ':user_id' => $userId,
                ':entity_id' => $userId,
                ':meta' => json_encode(['roles' => $roles]),
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->getUserById($userId);
    }

    public function login(string $identifier, string $password, string $ip, string $userAgent): array
    {
        $identifier = trim($identifier);

        if ($this->rateLimiter->isRateLimited($identifier, $ip)) {
            throw new Exception('Too many failed login attempts. Please wait 5 minutes before trying again.', 429);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT * FROM users
            WHERE LOWER(username) = LOWER(:id) OR LOWER(email) = LOWER(:id)
            LIMIT 1
        ');
        $stmt->execute([':id' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$this->verifyPassword($password, $user['password_hash'])) {
            $this->rateLimiter->recordFailedAttempt($identifier, $ip);
            throw new Exception('Invalid username/email or password.', 401);
        }

        if ($user['status'] !== 'ACTIVE') {
            throw new Exception('Your account is currently ' . strtolower($user['status']) . '. Please contact support.', 403);
        }

        $this->rateLimiter->clearAttempts($identifier, $ip);

        // Create session
        $sessionToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $sessionToken);
        $sessionId = 'ses_' . bin2hex(random_bytes(12));
        $expiresAt = date('Y-m-d H:i:s', time() + (7 * 86400)); // 7 days

        $sessionStmt = $pdo->prepare('
            INSERT INTO sessions (id, user_id, token_hash, ip_address, user_agent, expires_at, created_at)
            VALUES (:id, :user_id, :token_hash, :ip, :ua, :expires_at, datetime("now"))
        ');
        $sessionStmt->execute([
            ':id' => $sessionId,
            ':user_id' => $user['id'],
            ':token_hash' => $tokenHash,
            ':ip' => $ip,
            ':ua' => substr($userAgent, 0, 500),
            ':expires_at' => $expiresAt,
        ]);

        $fullUser = $this->getUserById($user['id']);

        return [
            'token' => $sessionToken,
            'expires_at' => $expiresAt,
            'user' => $fullUser,
        ];
    }

    public function validateSession(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('
            SELECT s.id AS session_id, s.expires_at, u.*
            FROM sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token_hash = :hash
              AND s.expires_at > datetime("now")
            LIMIT 1
        ');
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($row['status'] !== 'ACTIVE') {
            return null;
        }

        $user = $this->getUserById($row['id']);
        if ($user) {
            $user['session_id'] = $row['session_id'];
        }
        return $user;
    }

    public function logout(string $token): void
    {
        if (empty($token)) {
            return;
        }

        $tokenHash = hash('sha256', $token);
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE token_hash = :hash');
        $stmt->execute([':hash' => $tokenHash]);
    }

    public function getUserById(string $userId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT id, username, email, status, created_at, updated_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        // Get roles
        $rolesStmt = $pdo->prepare('
            SELECT role_id FROM user_roles
            WHERE user_id = :user_id
            ORDER BY role_id ASC
        ');
        $rolesStmt->execute([':user_id' => $userId]);
        $user['roles'] = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);

        // Get wallet summary
        $walStmt = $pdo->prepare('
            SELECT balance_cents, currency FROM wallets
            WHERE user_id = :user_id
            LIMIT 1
        ');
        $walStmt->execute([':user_id' => $userId]);
        $wallet = $walStmt->fetch(PDO::FETCH_ASSOC);
        $user['wallet'] = $wallet ?: ['balance_cents' => 0, 'currency' => 'EUR'];

        return $user;
    }

    public function changePassword(string $userId, string $oldPassword, string $newPassword): bool
    {
        if (strlen($newPassword) < 8) {
            throw new Exception('New password must be at least 8 characters long.', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $currentHash = $stmt->fetchColumn();

        if (!$currentHash || !$this->verifyPassword($oldPassword, (string) $currentHash)) {
            throw new Exception('Current password verification failed.', 401);
        }

        $newHash = $this->hashPassword($newPassword);
        $updateStmt = $pdo->prepare('
            UPDATE users
            SET password_hash = :hash, updated_at = datetime("now")
            WHERE id = :id
        ');
        $updateStmt->execute([':hash' => $newHash, ':id' => $userId]);

        return true;
    }
}
