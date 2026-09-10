<?php

declare(strict_types=1);

namespace App\Services;

use App\BigStore\BigStoreClient;
use App\BigStore\BigStoreException;
use App\Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class ShareService
{
    private PDO $pdo;
    private BigStoreClient $bigStore;

    public function __construct(?PDO $pdo = null, ?BigStoreClient $bigStore = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->bigStore = $bigStore ?? new BigStoreClient();
    }

    /**
     * Generate secure alphanumeric token for public share links
     */
    public static function generateToken(int $length = 10): string
    {
        $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $bytes = random_bytes($length);
        $token = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $token .= $chars[ord($bytes[$i]) % ($max + 1)];
        }
        return $token;
    }

    /**
     * Create a new file or directory share link
     */
    public function createShare(string $userId, array $params): array
    {
        $fileId = !empty($params['file_id']) ? trim((string) $params['file_id']) : null;
        $directoryId = !empty($params['directory_id']) ? trim((string) $params['directory_id']) : null;

        if (!$fileId && !$directoryId) {
            throw new InvalidArgumentException('Either file_id or directory_id is required to create a share.');
        }

        $resourceType = $fileId ? 'FILE' : 'DIRECTORY';
        $resourceName = '';

        if ($fileId) {
            $file = $this->bigStore->getFile($fileId, 'user', $userId);
            if (!$file) {
                throw new InvalidArgumentException('Target file does not exist or access is denied.');
            }
            $resourceName = $file['original_name'] ?? 'Shared File';
        } else {
            $dir = $this->bigStore->getDirectory($directoryId, 'user', $userId);
            if (!$dir) {
                throw new InvalidArgumentException('Target directory does not exist or access is denied.');
            }
            $resourceName = $dir['name'] ?? 'Shared Directory';
        }

        // Token generation
        $token = self::generateToken(10);
        $attempts = 0;
        while ($this->tokenExists($token)) {
            $token = self::generateToken(10);
            $attempts++;
            if ($attempts > 10) {
                throw new RuntimeException('Failed to generate a unique share token.');
            }
        }

        // Password protection
        $passwordHash = null;
        if (!empty($params['password'])) {
            $rawPass = (string) $params['password'];
            if (strlen($rawPass) < 4) {
                throw new InvalidArgumentException('Share password must be at least 4 characters long.');
            }
            $passwordHash = password_hash($rawPass, PASSWORD_ARGON2ID);
        }

        // Expiry timestamp
        $expiresAt = null;
        if (!empty($params['expires_at'])) {
            $ts = strtotime((string) $params['expires_at']);
            if ($ts === false || $ts <= time()) {
                throw new InvalidArgumentException('Expiry date must be a valid future timestamp.');
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        // Download permissions
        $downloadEnabled = isset($params['download_enabled']) ? (bool) $params['download_enabled'] : true;

        // Max downloads quota
        $maxDownloads = null;
        if (isset($params['max_downloads']) && $params['max_downloads'] !== '' && $params['max_downloads'] !== null) {
            $maxVal = (int) $params['max_downloads'];
            if ($maxVal < 1) {
                throw new InvalidArgumentException('Maximum downloads must be at least 1.');
            }
            $maxDownloads = $maxVal;
        }

        $shareId = 'shr_' . bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('
            INSERT INTO file_shares (
                id, user_id, file_id, directory_id, resource_type, resource_name,
                token, password_hash, expires_at, download_enabled, max_downloads,
                download_count, view_count, is_revoked, created_at, updated_at
            ) VALUES (
                :id, :user_id, :file_id, :directory_id, :resource_type, :resource_name,
                :token, :password_hash, :expires_at, :download_enabled, :max_downloads,
                0, 0, 0, :created_at, :updated_at
            )
        ');

        $stmt->execute([
            ':id' => $shareId,
            ':user_id' => $userId,
            ':file_id' => $fileId,
            ':directory_id' => $directoryId,
            ':resource_type' => $resourceType,
            ':resource_name' => $resourceName,
            ':token' => $token,
            ':password_hash' => $passwordHash,
            ':expires_at' => $expiresAt,
            ':download_enabled' => $downloadEnabled ? 1 : 0,
            ':max_downloads' => $maxDownloads,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->formatShareRecord($this->findShareById($shareId));
    }

    /**
     * List shares owned by a user
     */
    public function listUserShares(string $userId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM file_shares WHERE user_id = :uid AND is_revoked = 0');
        $countStmt->execute([':uid' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare('
            SELECT * FROM file_shares
            WHERE user_id = :uid AND is_revoked = 0
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':uid', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $shares = array_map([$this, 'formatShareRecord'], $rows);

        return [
            'shares' => $shares,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($shares)) < $total,
            ],
        ];
    }

    /**
     * Get share details by ID
     */
    public function getShare(string $shareId, string $userId, bool $isAdmin = false): ?array
    {
        $share = $this->findShareById($shareId);
        if (!$share) {
            return null;
        }

        if (!$isAdmin && $share['user_id'] !== $userId) {
            return null;
        }

        return $this->formatShareRecord($share);
    }

    /**
     * Revoke a share link
     */
    public function revokeShare(string $shareId, string $userId, bool $isAdmin = false): bool
    {
        $share = $this->findShareById($shareId);
        if (!$share) {
            return false;
        }

        if (!$isAdmin && $share['user_id'] !== $userId) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            UPDATE file_shares
            SET is_revoked = 1, updated_at = :now
            WHERE id = :id
        ');
        return $stmt->execute([
            ':id' => $shareId,
            ':now' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update share configuration
     */
    public function updateShare(string $shareId, string $userId, array $params, bool $isAdmin = false): array
    {
        $share = $this->findShareById($shareId);
        if (!$share) {
            throw new InvalidArgumentException('Share not found.');
        }

        if (!$isAdmin && $share['user_id'] !== $userId) {
            throw new InvalidArgumentException('Permission denied.');
        }

        $fields = [];
        $bindings = [':id' => $shareId, ':now' => date('Y-m-d H:i:s')];

        if (array_key_exists('download_enabled', $params)) {
            $fields[] = 'download_enabled = :download_enabled';
            $bindings[':download_enabled'] = $params['download_enabled'] ? 1 : 0;
        }

        if (array_key_exists('max_downloads', $params)) {
            $fields[] = 'max_downloads = :max_downloads';
            $bindings[':max_downloads'] = ($params['max_downloads'] !== null && $params['max_downloads'] !== '') 
                ? (int) $params['max_downloads'] 
                : null;
        }

        if (array_key_exists('expires_at', $params)) {
            $fields[] = 'expires_at = :expires_at';
            if ($params['expires_at']) {
                $ts = strtotime((string) $params['expires_at']);
                $bindings[':expires_at'] = $ts ? date('Y-m-d H:i:s', $ts) : null;
            } else {
                $bindings[':expires_at'] = null;
            }
        }

        if (array_key_exists('password', $params)) {
            $fields[] = 'password_hash = :password_hash';
            if (!empty($params['password'])) {
                $bindings[':password_hash'] = password_hash((string) $params['password'], PASSWORD_ARGON2ID);
            } else {
                $bindings[':password_hash'] = null;
            }
        }

        if (!empty($fields)) {
            $sql = 'UPDATE file_shares SET ' . implode(', ', $fields) . ', updated_at = :now WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
        }

        return $this->formatShareRecord($this->findShareById($shareId));
    }

    /**
     * Resolve public share by token (view counter incremented)
     */
    public function resolvePublicShare(string $token): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM file_shares WHERE token = :tok LIMIT 1');
        $stmt->execute([':tok' => $token]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$share) {
            return ['status' => 'NOT_FOUND', 'share' => null];
        }

        if ((int) $share['is_revoked'] === 1) {
            return ['status' => 'REVOKED', 'share' => null];
        }

        if (!empty($share['expires_at']) && strtotime($share['expires_at']) <= time()) {
            return ['status' => 'EXPIRED', 'share' => null];
        }

        if (!empty($share['max_downloads']) && (int) $share['download_count'] >= (int) $share['max_downloads']) {
            return ['status' => 'LIMIT_EXCEEDED', 'share' => null];
        }

        // Increment view count
        $inc = $this->pdo->prepare('UPDATE file_shares SET view_count = view_count + 1 WHERE id = :id');
        $inc->execute([':id' => $share['id']]);
        $inc->closeCursor();

        $share['view_count']++;

        $requiresPassword = !empty($share['password_hash']);
        $metadata = [
            'token' => $share['token'],
            'resource_type' => $share['resource_type'],
            'resource_name' => $share['resource_name'],
            'download_enabled' => (bool) $share['download_enabled'],
            'requires_password' => $requiresPassword,
            'expires_at' => $share['expires_at'],
            'view_count' => (int) $share['view_count'],
            'download_count' => (int) $share['download_count'],
            'max_downloads' => $share['max_downloads'] !== null ? (int) $share['max_downloads'] : null,
        ];

        // If not password protected, fetch live BigStore metadata
        if (!$requiresPassword && $share['file_id']) {
            try {
                $file = $this->bigStore->getFile($share['file_id'], 'user', $share['user_id']);
                if ($file) {
                    $metadata['size_bytes'] = (int) ($file['size_bytes'] ?? 0);
                    $metadata['formatted_size'] = self::formatBytes($metadata['size_bytes']);
                    $metadata['mime_type'] = $file['mime_type'] ?? 'application/octet-stream';
                    $metadata['checksum_sha256'] = $file['checksum_sha256'] ?? null;
                }
            } catch (\Exception $e) {
                // If BigStore metadata query fails, keep baseline metadata
            }
        }

        return ['status' => 'ACTIVE', 'metadata' => $metadata, 'raw' => $share];
    }

    /**
     * Verify share password and create unlock access token
     */
    public function unlockWithPassword(string $token, string $password): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM file_shares WHERE token = :tok LIMIT 1');
        $stmt->execute([':tok' => $token]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$share || (int) $share['is_revoked'] === 1) {
            return ['valid' => false, 'error' => 'SHARE_NOT_FOUND', 'message' => 'Share link not found or revoked.'];
        }

        if (!empty($share['expires_at']) && strtotime($share['expires_at']) <= time()) {
            return ['valid' => false, 'error' => 'SHARE_EXPIRED', 'message' => 'This share link has expired.'];
        }

        if (empty($share['password_hash'])) {
            return ['valid' => true, 'unlocked' => true, 'message' => 'Share is not password protected.'];
        }

        if (!password_verify($password, $share['password_hash'])) {
            return ['valid' => false, 'error' => 'INVALID_PASSWORD', 'message' => 'Incorrect password provided.'];
        }

        // Generate unlock token valid for 2 hours
        $rawUnlockToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawUnlockToken);
        $tokenId = 'sat_' . bin2hex(random_bytes(12));
        $expiresAt = date('Y-m-d H:i:s', time() + 7200);

        $ins = $this->pdo->prepare('
            INSERT INTO share_access_tokens (id, share_id, token_hash, expires_at)
            VALUES (:id, :share_id, :token_hash, :expires_at)
        ');
        $ins->execute([
            ':id' => $tokenId,
            ':share_id' => $share['id'],
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);
        $ins->closeCursor();

        // Get file details
        $fileInfo = [];
        if ($share['file_id']) {
            try {
                $file = $this->bigStore->getFile($share['file_id'], 'user', $share['user_id']);
                if ($file) {
                    $fileInfo = [
                        'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                        'formatted_size' => self::formatBytes((int) ($file['size_bytes'] ?? 0)),
                        'mime_type' => $file['mime_type'] ?? 'application/octet-stream',
                        'checksum_sha256' => $file['checksum_sha256'] ?? null,
                    ];
                }
            } catch (\Exception $e) {}
        }

        return [
            'valid' => true,
            'unlocked' => true,
            'unlock_token' => $rawUnlockToken,
            'expires_at' => $expiresAt,
            'resource_name' => $share['resource_name'],
            'download_enabled' => (bool) $share['download_enabled'],
            'file_info' => $fileInfo,
        ];
    }

    /**
     * Validate full access authorization for streaming or downloading
     */
    public function authorizeAccess(string $token, ?string $rawPassword = null, ?string $unlockToken = null, bool $isDownload = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM file_shares WHERE token = :tok LIMIT 1');
        $stmt->execute([':tok' => $token]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$share || (int) $share['is_revoked'] === 1) {
            return ['allowed' => false, 'code' => 'NOT_FOUND', 'status' => 404, 'message' => 'Share link does not exist or has been revoked.'];
        }

        if (!empty($share['expires_at']) && strtotime($share['expires_at']) <= time()) {
            return ['allowed' => false, 'code' => 'SHARE_EXPIRED', 'status' => 410, 'message' => 'This share link has expired.'];
        }

        if ($isDownload && !(bool) $share['download_enabled']) {
            return ['allowed' => false, 'code' => 'DOWNLOAD_DISABLED', 'status' => 403, 'message' => 'File downloads are disabled for this share link.'];
        }

        if (!empty($share['max_downloads']) && (int) $share['download_count'] >= (int) $share['max_downloads']) {
            return ['allowed' => false, 'code' => 'DOWNLOAD_LIMIT_EXCEEDED', 'status' => 410, 'message' => 'The maximum download limit for this link has been reached.'];
        }

        // Password verification check
        if (!empty($share['password_hash'])) {
            $authenticated = false;

            // Check unlock token
            if ($unlockToken) {
                $hash = hash('sha256', $unlockToken);
                $tokStmt = $this->pdo->prepare('
                    SELECT * FROM share_access_tokens
                    WHERE share_id = :sid AND token_hash = :hash AND expires_at > CURRENT_TIMESTAMP
                    LIMIT 1
                ');
                $tokStmt->execute([':sid' => $share['id'], ':hash' => $hash]);
                if ($tokStmt->fetch()) {
                    $authenticated = true;
                }
            }

            // Check direct password
            if (!$authenticated && $rawPassword !== null) {
                if (password_verify($rawPassword, $share['password_hash'])) {
                    $authenticated = true;
                }
            }

            if (!$authenticated) {
                return ['allowed' => false, 'code' => 'PASSWORD_REQUIRED', 'status' => 401, 'message' => 'A valid password is required to access this share.'];
            }
        }

        return ['allowed' => true, 'share' => $share];
    }

    /**
     * Atomically increment download count
     */
    public function recordDownload(string $shareId): void
    {
        $stmt = $this->pdo->prepare('UPDATE file_shares SET download_count = download_count + 1 WHERE id = :id');
        $stmt->execute([':id' => $shareId]);
        $stmt->closeCursor();
    }

    private function findShareById(string $shareId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM file_shares WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $shareId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function tokenExists(string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM file_shares WHERE token = :tok LIMIT 1');
        $stmt->execute([':tok' => $token]);
        return (bool) $stmt->fetchColumn();
    }

    private function formatShareRecord(array $share): array
    {
        $isExpired = !empty($share['expires_at']) && strtotime($share['expires_at']) <= time();
        $isLimitReached = !empty($share['max_downloads']) && (int) $share['download_count'] >= (int) $share['max_downloads'];

        return [
            'id' => $share['id'],
            'user_id' => $share['user_id'],
            'file_id' => $share['file_id'],
            'directory_id' => $share['directory_id'],
            'resource_type' => $share['resource_type'],
            'resource_name' => $share['resource_name'],
            'token' => $share['token'],
            'share_url' => '/s/' . $share['token'],
            'has_password' => !empty($share['password_hash']),
            'download_enabled' => (bool) $share['download_enabled'],
            'max_downloads' => $share['max_downloads'] !== null ? (int) $share['max_downloads'] : null,
            'download_count' => (int) $share['download_count'],
            'view_count' => (int) $share['view_count'],
            'expires_at' => $share['expires_at'],
            'is_revoked' => (bool) $share['is_revoked'],
            'is_expired' => $isExpired,
            'is_limit_reached' => $isLimitReached,
            'created_at' => $share['created_at'],
            'updated_at' => $share['updated_at'],
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $value = $bytes / pow(1024, $power);
        return sprintf('%.2f %s', $value, $units[(int) $power] ?? 'B');
    }
}
