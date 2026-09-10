<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\BigStore\BigStoreClient;
use Exception;
use PDO;

class SubscriptionService
{
    public const DEFAULT_QUOTA_BYTES = 53687091200; // 50 GiB
    public const MONTHLY_FEE_CENTS = 300; // 3.00 EUR in minor cents

    private PDO $pdo;
    private ?BigStoreClient $bigStoreClient;

    public function __construct(?BigStoreClient $bigStoreClient = null)
    {
        $this->pdo = Database::getConnection();
        $this->bigStoreClient = $bigStoreClient;
    }

    /**
     * Get active or latest subscription for user
     */
    public function getSubscriptionForUser(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.*, p.company_name as partner_company
            FROM subscriptions s
            LEFT JOIN partners p ON s.partner_id = p.id
            WHERE s.user_id = :uid
            LIMIT 1
        ');
        $stmt->execute([':uid' => $userId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            return null;
        }

        // Check if subscription has expired
        if ($sub['status'] === 'ACTIVE' && strtotime($sub['expires_at']) <= time()) {
            $update = $this->pdo->prepare('
                UPDATE subscriptions
                SET status = "EXPIRED", updated_at = datetime("now")
                WHERE id = :id
            ');
            $update->execute([':id' => $sub['id']]);
            $sub['status'] = 'EXPIRED';

            // Record expiry event
            $this->recordEvent($sub['id'], $userId, $sub['partner_id'], 'EXPIRED', 'system', 0, 'Subscription reached expiration date');
        }

        return $sub;
    }

    /**
     * Check if user currently has an active subscription
     */
    public function hasActiveSubscription(string $userId): bool
    {
        $sub = $this->getSubscriptionForUser($userId);
        return $sub !== null && $sub['status'] === 'ACTIVE' && strtotime($sub['expires_at']) > time();
    }

    /**
     * Get latest request for user
     */
    public function getLatestRequestForUser(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT r.*, p.company_name as partner_company
            FROM subscription_requests r
            LEFT JOIN partners p ON r.partner_id = p.id
            WHERE r.user_id = :uid
            ORDER BY r.created_at DESC
            LIMIT 1
        ');
        $stmt->execute([':uid' => $userId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        return $req ?: null;
    }

    /**
     * Customer requests a subscription
     */
    public function requestSubscription(string $userId, ?string $partnerId = null, ?string $notes = null): array
    {
        if ($this->hasActiveSubscription($userId)) {
            throw new Exception('User already has an active subscription.', 409);
        }

        // Check for existing pending request
        $stmt = $this->pdo->prepare('
            SELECT id FROM subscription_requests
            WHERE user_id = :uid AND status = "REQUESTED"
            LIMIT 1
        ');
        $stmt->execute([':uid' => $userId]);
        if ($stmt->fetch()) {
            throw new Exception('A pending subscription request is already awaiting review.', 409);
        }

        // Resolve partner if not explicitly passed
        if (!$partnerId) {
            $partnerStmt = $this->pdo->query('SELECT id FROM partners ORDER BY created_at ASC LIMIT 1');
            $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$partner) {
                throw new Exception('No available merchant partner found to service subscription.', 404);
            }
            $partnerId = $partner['id'];
        } else {
            $partnerCheck = $this->pdo->prepare('SELECT id FROM partners WHERE id = :pid');
            $partnerCheck->execute([':pid' => $partnerId]);
            if (!$partnerCheck->fetch()) {
                throw new Exception('Specified partner not found.', 404);
            }
        }

        $requestId = 'srq_' . bin2hex(random_bytes(12));

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('
                INSERT INTO subscription_requests (id, user_id, partner_id, status, notes, created_at)
                VALUES (:id, :uid, :pid, "REQUESTED", :notes, datetime("now"))
            ');
            $insert->execute([
                ':id' => $requestId,
                ':uid' => $userId,
                ':pid' => $partnerId,
                ':notes' => $notes,
            ]);

            $this->recordEvent(null, $userId, $partnerId, 'REQUESTED', $userId, 0, $notes);

            $this->recordAudit($userId, 'SUBSCRIPTION_REQUESTED', 'subscription_requests', $requestId, [
                'partner_id' => $partnerId,
                'notes' => $notes,
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getRequestById($requestId);
    }

    /**
     * List subscription requests
     */
    public function listRequests(?string $partnerId = null, ?string $status = null): array
    {
        $query = '
            SELECT r.*, u.username, u.email, p.company_name as partner_company
            FROM subscription_requests r
            JOIN users u ON r.user_id = u.id
            JOIN partners p ON r.partner_id = p.id
            WHERE 1=1
        ';
        $params = [];

        if ($partnerId !== null) {
            $query .= ' AND r.partner_id = :pid';
            $params[':pid'] = $partnerId;
        }

        if ($status !== null && $status !== 'ALL') {
            $query .= ' AND r.status = :status';
            $params[':status'] = strtoupper(trim($status));
        }

        $query .= ' ORDER BY r.created_at DESC';

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Approve subscription request
     */
    public function approveRequest(string $requestId, string $actorId, ?string $actorPartnerId = null, bool $isAdmin = false): array
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new Exception('Subscription request not found.', 404);
        }

        if ($request['status'] !== 'REQUESTED') {
            throw new Exception("Cannot approve request in status: {$request['status']}.", 400);
        }

        if (!$isAdmin && $actorPartnerId !== $request['partner_id']) {
            throw new Exception('You are not authorized to approve requests for another partner.', 403);
        }

        $this->pdo->beginTransaction();
        try {
            // Update request
            $updateReq = $this->pdo->prepare('
                UPDATE subscription_requests
                SET status = "APPROVED", resolved_at = datetime("now"), resolved_by = :actor
                WHERE id = :id
            ');
            $updateReq->execute([
                ':actor' => $actorId,
                ':id' => $requestId,
            ]);

            // Upsert subscription
            $subStmt = $this->pdo->prepare('SELECT id FROM subscriptions WHERE user_id = :uid');
            $subStmt->execute([':uid' => $request['user_id']]);
            $existingSub = $subStmt->fetch(PDO::FETCH_ASSOC);

            $startsAt = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

            if ($existingSub) {
                $subId = $existingSub['id'];
                $updateSub = $this->pdo->prepare('
                    UPDATE subscriptions
                    SET partner_id = :pid,
                        status = "ACTIVE",
                        quota_bytes = :quota,
                        starts_at = :starts,
                        expires_at = :expires,
                        updated_at = datetime("now")
                    WHERE id = :id
                ');
                $updateSub->execute([
                    ':pid' => $request['partner_id'],
                    ':quota' => self::DEFAULT_QUOTA_BYTES,
                    ':starts' => $startsAt,
                    ':expires' => $expiresAt,
                    ':id' => $subId,
                ]);
            } else {
                $subId = 'sub_' . bin2hex(random_bytes(12));
                $insertSub = $this->pdo->prepare('
                    INSERT INTO subscriptions (id, user_id, partner_id, status, quota_bytes, starts_at, expires_at, created_at, updated_at)
                    VALUES (:id, :uid, :pid, "ACTIVE", :quota, :starts, :expires, datetime("now"), datetime("now"))
                ');
                $insertSub->execute([
                    ':id' => $subId,
                    ':uid' => $request['user_id'],
                    ':pid' => $request['partner_id'],
                    ':quota' => self::DEFAULT_QUOTA_BYTES,
                    ':starts' => $startsAt,
                    ':expires' => $expiresAt,
                ]);
            }

            // Record Partner Debt Accumulation: 3.00 EUR (300 minor cents)
            $partnerUpdate = $this->pdo->prepare('
                UPDATE partners
                SET debt_cents = debt_cents + :fee, updated_at = datetime("now")
                WHERE id = :pid
            ');
            $partnerUpdate->execute([
                ':fee' => self::MONTHLY_FEE_CENTS,
                ':pid' => $request['partner_id'],
            ]);

            // Append-only Partner Billing Entry
            $pbeId = 'pbe_' . bin2hex(random_bytes(12));
            $pbeStmt = $this->pdo->prepare('
                INSERT INTO partner_billing_entries (id, partner_id, amount_cents, operation_type, reference_id, description, created_at)
                VALUES (:id, :pid, :amount, "SUBSCRIPTION_RENEWAL", :ref, :desc, datetime("now"))
            ');
            $pbeStmt->execute([
                ':id' => $pbeId,
                ':pid' => $request['partner_id'],
                ':amount' => self::MONTHLY_FEE_CENTS,
                ':ref' => $subId,
                ':desc' => "Customer subscription activation: 30 days (50 GiB)",
            ]);

            // Event log
            $this->recordEvent($subId, $request['user_id'], $request['partner_id'], 'APPROVED', $actorId, self::MONTHLY_FEE_CENTS, 'Request approved');

            // Audit log
            $this->recordAudit($actorId, 'SUBSCRIPTION_APPROVED', 'subscriptions', $subId, [
                'request_id' => $requestId,
                'user_id' => $request['user_id'],
                'partner_id' => $request['partner_id'],
                'fee_cents' => self::MONTHLY_FEE_CENTS,
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Verify/provision BigStore quota account
        try {
            if ($this->bigStoreClient) {
                $this->bigStoreClient->getStorageQuota('user', $request['user_id']);
            }
        } catch (Exception $e) {
            // Non-fatal, BigStore lazily creates on first quota call
        }

        return [
            'request' => $this->getRequestById($requestId),
            'subscription' => $this->getSubscriptionForUser($request['user_id']),
            'fee_charged_cents' => self::MONTHLY_FEE_CENTS,
        ];
    }

    /**
     * Reject subscription request
     */
    public function rejectRequest(string $requestId, string $actorId, ?string $actorPartnerId = null, bool $isAdmin = false, ?string $reason = null): array
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new Exception('Subscription request not found.', 404);
        }

        if ($request['status'] !== 'REQUESTED') {
            throw new Exception("Cannot reject request in status: {$request['status']}.", 400);
        }

        if (!$isAdmin && $actorPartnerId !== $request['partner_id']) {
            throw new Exception('You are not authorized to reject requests for another partner.', 403);
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('
                UPDATE subscription_requests
                SET status = "REJECTED", resolved_at = datetime("now"), resolved_by = :actor, rejection_reason = :reason
                WHERE id = :id
            ');
            $update->execute([
                ':actor' => $actorId,
                ':reason' => $reason,
                ':id' => $requestId,
            ]);

            $this->recordEvent(null, $request['user_id'], $request['partner_id'], 'REJECTED', $actorId, 0, $reason);

            $this->recordAudit($actorId, 'SUBSCRIPTION_REJECTED', 'subscription_requests', $requestId, [
                'reason' => $reason,
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getRequestById($requestId);
    }

    /**
     * Renew subscription
     */
    public function renewSubscription(string $subscriptionId, string $actorId, ?string $actorPartnerId = null, bool $isAdmin = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscriptions WHERE id = :id');
        $stmt->execute([':id' => $subscriptionId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            throw new Exception('Subscription not found.', 404);
        }

        if (!$isAdmin && $actorPartnerId !== $sub['partner_id']) {
            throw new Exception('You are not authorized to renew subscriptions for another partner.', 403);
        }

        $now = time();
        $currentExpiry = strtotime($sub['expires_at']);

        // Extend 30 days from current expiry or from now if already expired
        if ($currentExpiry > $now) {
            $newExpiry = date('Y-m-d H:i:s', strtotime('+30 days', $currentExpiry));
        } else {
            $newExpiry = date('Y-m-d H:i:s', strtotime('+30 days', $now));
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('
                UPDATE subscriptions
                SET status = "ACTIVE", expires_at = :expires, updated_at = datetime("now")
                WHERE id = :id
            ');
            $update->execute([
                ':expires' => $newExpiry,
                ':id' => $subscriptionId,
            ]);

            // Accrue 3.00 EUR (300 cents) against partner debt
            $partnerUpdate = $this->pdo->prepare('
                UPDATE partners
                SET debt_cents = debt_cents + :fee, updated_at = datetime("now")
                WHERE id = :pid
            ');
            $partnerUpdate->execute([
                ':fee' => self::MONTHLY_FEE_CENTS,
                ':pid' => $sub['partner_id'],
            ]);

            $pbeId = 'pbe_' . bin2hex(random_bytes(12));
            $pbeStmt = $this->pdo->prepare('
                INSERT INTO partner_billing_entries (id, partner_id, amount_cents, operation_type, reference_id, description, created_at)
                VALUES (:id, :pid, :amount, "SUBSCRIPTION_RENEWAL", :ref, :desc, datetime("now"))
            ');
            $pbeStmt->execute([
                ':id' => $pbeId,
                ':pid' => $sub['partner_id'],
                ':amount' => self::MONTHLY_FEE_CENTS,
                ':ref' => $subscriptionId,
                ':desc' => "Customer subscription renewal: 30 days extended",
            ]);

            $this->recordEvent($subscriptionId, $sub['user_id'], $sub['partner_id'], 'RENEWED', $actorId, self::MONTHLY_FEE_CENTS, 'Subscription renewed 30 days');

            $this->recordAudit($actorId, 'SUBSCRIPTION_RENEWED', 'subscriptions', $subscriptionId, [
                'fee_cents' => self::MONTHLY_FEE_CENTS,
                'expires_at' => $newExpiry,
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getSubscriptionForUser($sub['user_id']);
    }

    /**
     * Cancel subscription
     */
    public function cancelSubscription(string $userId, string $actorId, bool $isAdmin = false): array
    {
        $sub = $this->getSubscriptionForUser($userId);
        if (!$sub) {
            throw new Exception('No subscription found to cancel.', 404);
        }

        if (!$isAdmin && $userId !== $actorId) {
            throw new Exception('Unauthorized to cancel this subscription.', 403);
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('
                UPDATE subscriptions
                SET status = "CANCELLED", updated_at = datetime("now")
                WHERE id = :id
            ');
            $update->execute([':id' => $sub['id']]);

            $this->recordEvent($sub['id'], $userId, $sub['partner_id'], 'CANCELLED', $actorId, 0, 'Subscription cancelled');

            $this->recordAudit($actorId, 'SUBSCRIPTION_CANCELLED', 'subscriptions', $sub['id']);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getSubscriptionForUser($userId);
    }

    /**
     * Get Partner ID associated with a user
     */
    public function getPartnerIdByUserId(string $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM partners WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['id'] : null;
    }

    private function getRequestById(string $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT r.*, u.username, u.email, p.company_name as partner_company
            FROM subscription_requests r
            JOIN users u ON r.user_id = u.id
            JOIN partners p ON r.partner_id = p.id
            WHERE r.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    private function recordEvent(?string $subId, string $userId, string $partnerId, string $eventType, string $actorId, int $feeCents = 0, ?string $notes = null): void
    {
        $eventId = 'sev_' . bin2hex(random_bytes(12));
        $stmt = $this->pdo->prepare('
            INSERT INTO subscription_events (id, subscription_id, user_id, partner_id, event_type, actor_id, fee_cents, notes, created_at)
            VALUES (:id, :sub, :uid, :pid, :type, :actor, :fee, :notes, datetime("now"))
        ');
        $stmt->execute([
            ':id' => $eventId,
            ':sub' => $subId,
            ':uid' => $userId,
            ':pid' => $partnerId,
            ':type' => $eventType,
            ':actor' => $actorId,
            ':fee' => $feeCents,
            ':notes' => $notes,
        ]);
    }

    private function recordAudit(string $actorId, string $action, string $targetType, string $targetId, array $meta = []): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO audit_logs (actor_id, action, target_type, target_id, metadata, created_at)
            VALUES (:actor, :action, :type, :target, :meta, datetime("now"))
        ');
        $stmt->execute([
            ':actor' => $actorId,
            ':action' => $action,
            ':type' => $targetType,
            ':target' => $targetId,
            ':meta' => json_encode($meta),
        ]);
    }
}
