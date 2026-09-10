<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;
use PDO;

class PartnerBillingService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    public static function formatCents(int $cents, string $currency = 'EUR'): string
    {
        $symbol = $currency === 'EUR' ? '€' : $currency;
        return number_format($cents / 100, 2, '.', '') . ' ' . $symbol;
    }

    public function getPartnerByUserId(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, u.username, u.email
            FROM partners p
            JOIN users u ON u.id = p.user_id
            WHERE p.user_id = :uid
            LIMIT 1
        ');
        $stmt->execute([':uid' => $userId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$partner) {
            return null;
        }

        $partner['debt_cents'] = (int)$partner['debt_cents'];
        $partner['formatted_debt'] = self::formatCents($partner['debt_cents']);
        $partner['invoice_retention_enabled'] = (bool)$partner['invoice_retention_enabled'];
        return $partner;
    }

    public function getPartnerById(string $partnerId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT p.*, u.username, u.email
            FROM partners p
            JOIN users u ON u.id = p.user_id
            WHERE p.id = :pid
            LIMIT 1
        ');
        $stmt->execute([':pid' => $partnerId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$partner) {
            return null;
        }

        $partner['debt_cents'] = (int)$partner['debt_cents'];
        $partner['formatted_debt'] = self::formatCents($partner['debt_cents']);
        $partner['invoice_retention_enabled'] = (bool)$partner['invoice_retention_enabled'];
        return $partner;
    }

    public function getBillingSummary(string $partnerId): array
    {
        $partner = $this->getPartnerById($partnerId);
        if (!$partner) {
            throw new Exception('Partner record not found.', 404);
        }

        // 1. Calculate total charges from immutable ledger
        $stmtCharges = $this->pdo->prepare('
            SELECT COALESCE(SUM(amount_cents), 0)
            FROM partner_billing_entries
            WHERE partner_id = :pid
        ');
        $stmtCharges->execute([':pid' => $partnerId]);
        $totalChargesCents = (int)$stmtCharges->fetchColumn();

        // 2. Calculate total payments received
        $stmtPayments = $this->pdo->prepare('
            SELECT COALESCE(SUM(amount_cents), 0)
            FROM partner_payments
            WHERE partner_id = :pid
        ');
        $stmtPayments->execute([':pid' => $partnerId]);
        $totalPaymentsCents = (int)$stmtPayments->fetchColumn();

        // 3. Calculated debt from ledger
        $calculatedDebtCents = $totalChargesCents - $totalPaymentsCents;

        // 4. Fetch recent billing entries
        $stmtRecentEntries = $this->pdo->prepare('
            SELECT id, amount_cents, operation_type, reference_id, description, created_at
            FROM partner_billing_entries
            WHERE partner_id = :pid
            ORDER BY created_at DESC
            LIMIT 5
        ');
        $stmtRecentEntries->execute([':pid' => $partnerId]);
        $rawEntries = $stmtRecentEntries->fetchAll(PDO::FETCH_ASSOC);
        $recentEntries = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'amount_cents' => (int)$row['amount_cents'],
                'formatted_amount' => '+' . self::formatCents((int)$row['amount_cents']),
                'operation_type' => $row['operation_type'],
                'reference_id' => $row['reference_id'],
                'description' => $row['description'],
                'created_at' => $row['created_at'],
            ];
        }, $rawEntries);

        // 5. Fetch recent payments
        $stmtRecentPayments = $this->pdo->prepare('
            SELECT p.id, p.amount_cents, p.admin_id, u.username AS admin_username,
                   p.payment_method, p.reference_number, p.debt_after_cents, p.notes, p.created_at
            FROM partner_payments p
            LEFT JOIN users u ON u.id = p.admin_id
            WHERE p.partner_id = :pid
            ORDER BY p.created_at DESC
            LIMIT 5
        ');
        $stmtRecentPayments->execute([':pid' => $partnerId]);
        $rawPayments = $stmtRecentPayments->fetchAll(PDO::FETCH_ASSOC);
        $recentPayments = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'amount_cents' => (int)$row['amount_cents'],
                'formatted_amount' => '-' . self::formatCents((int)$row['amount_cents']),
                'admin_id' => $row['admin_id'],
                'admin_username' => $row['admin_username'] ?? 'System Admin',
                'payment_method' => $row['payment_method'] ?? 'MANUAL',
                'reference_number' => $row['reference_number'],
                'debt_after_cents' => (int)($row['debt_after_cents'] ?? 0),
                'formatted_debt_after' => self::formatCents((int)($row['debt_after_cents'] ?? 0)),
                'notes' => $row['notes'],
                'created_at' => $row['created_at'],
            ];
        }, $rawPayments);

        return [
            'partner' => $partner,
            'total_charges_cents' => $totalChargesCents,
            'formatted_total_charges' => self::formatCents($totalChargesCents),
            'total_payments_cents' => $totalPaymentsCents,
            'formatted_total_payments' => self::formatCents($totalPaymentsCents),
            'calculated_debt_cents' => $calculatedDebtCents,
            'formatted_calculated_debt' => self::formatCents($calculatedDebtCents),
            'recent_entries' => $recentEntries,
            'recent_payments' => $recentPayments,
        ];
    }

    public function getBillingEntries(
        string $partnerId,
        int $limit = 20,
        int $offset = 0,
        ?string $operationType = null
    ): array {
        $partner = $this->getPartnerById($partnerId);
        if (!$partner) {
            throw new Exception('Partner record not found.', 404);
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $whereClause = 'WHERE partner_id = :pid';
        $params = [':pid' => $partnerId];

        if ($operationType !== null && trim($operationType) !== '') {
            $whereClause .= ' AND operation_type = :op';
            $params[':op'] = trim($operationType);
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM partner_billing_entries {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT id, partner_id, amount_cents, operation_type, reference_id, description, created_at
            FROM partner_billing_entries
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $entries = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'partner_id' => $row['partner_id'],
                'amount_cents' => (int)$row['amount_cents'],
                'formatted_amount' => '+' . self::formatCents((int)$row['amount_cents']),
                'operation_type' => $row['operation_type'],
                'reference_id' => $row['reference_id'],
                'description' => $row['description'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return [
            'entries' => $entries,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($entries)) < $total,
            ],
        ];
    }

    public function getPayments(string $partnerId, int $limit = 20, int $offset = 0): array
    {
        $partner = $this->getPartnerById($partnerId);
        if (!$partner) {
            throw new Exception('Partner record not found.', 404);
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM partner_payments WHERE partner_id = :pid');
        $countStmt->execute([':pid' => $partnerId]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare('
            SELECT p.id, p.partner_id, p.amount_cents, p.admin_id, u.username AS admin_username,
                   p.payment_method, p.reference_number, p.debt_after_cents, p.notes, p.created_at
            FROM partner_payments p
            LEFT JOIN users u ON u.id = p.admin_id
            WHERE p.partner_id = :pid
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':pid', $partnerId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $payments = array_map(function ($row) {
            return [
                'id' => $row['id'],
                'partner_id' => $row['partner_id'],
                'amount_cents' => (int)$row['amount_cents'],
                'formatted_amount' => '-' . self::formatCents((int)$row['amount_cents']),
                'admin_id' => $row['admin_id'],
                'admin_username' => $row['admin_username'] ?? 'System Admin',
                'payment_method' => $row['payment_method'] ?? 'MANUAL',
                'reference_number' => $row['reference_number'],
                'debt_after_cents' => (int)($row['debt_after_cents'] ?? 0),
                'formatted_debt_after' => self::formatCents((int)($row['debt_after_cents'] ?? 0)),
                'notes' => $row['notes'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return [
            'payments' => $payments,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($payments)) < $total,
            ],
        ];
    }

    public function settlePayment(
        string $adminUserId,
        string $partnerId,
        int $amountCents,
        string $paymentMethod = 'MANUAL',
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): array {
        if ($amountCents <= 0) {
            throw new Exception('Payment amount must be greater than zero.', 422);
        }

        $partner = $this->getPartnerById($partnerId);
        if (!$partner) {
            throw new Exception('Partner not found.', 404);
        }

        // Idempotency check if idempotency key provided
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $idempotencyKey = trim($idempotencyKey);
            $checkStmt = $this->pdo->prepare('
                SELECT p.*, u.username AS admin_username
                FROM partner_payments p
                LEFT JOIN users u ON u.id = p.admin_id
                WHERE p.idempotency_key = :key
                LIMIT 1
            ');
            $checkStmt->execute([':key' => $idempotencyKey]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                return [
                    'idempotent_replay' => true,
                    'partner' => $this->getPartnerById($partnerId),
                    'payment' => [
                        'id' => $existing['id'],
                        'partner_id' => $existing['partner_id'],
                        'amount_cents' => (int)$existing['amount_cents'],
                        'formatted_amount' => '-' . self::formatCents((int)$existing['amount_cents']),
                        'admin_id' => $existing['admin_id'],
                        'admin_username' => $existing['admin_username'] ?? 'System Admin',
                        'payment_method' => $existing['payment_method'] ?? 'MANUAL',
                        'reference_number' => $existing['reference_number'],
                        'debt_after_cents' => (int)($existing['debt_after_cents'] ?? 0),
                        'formatted_debt_after' => self::formatCents((int)($existing['debt_after_cents'] ?? 0)),
                        'notes' => $existing['notes'],
                        'idempotency_key' => $existing['idempotency_key'],
                        'created_at' => $existing['created_at'],
                    ],
                ];
            }
        }

        // Concurrency-hardened atomic debt settlement using BEGIN IMMEDIATE & RETURNING (Rule 8)
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            // Deduct from partner debt without allowing overdraft/negative debt
            $update = $this->pdo->prepare('
                UPDATE partners
                SET debt_cents = debt_cents - :amount, updated_at = datetime("now")
                WHERE id = :pid AND debt_cents >= :amount
                RETURNING debt_cents
            ');
            $update->execute([
                ':amount' => $amountCents,
                ':pid' => $partnerId,
            ]);
            $res = $update->fetchColumn();

            if ($res === false) {
                $update->closeCursor();
                // Check current debt to give clear error
                $currStmt = $this->pdo->prepare('SELECT debt_cents FROM partners WHERE id = :pid');
                $currStmt->execute([':pid' => $partnerId]);
                $currDebt = (int)$currStmt->fetchColumn();
                $currStmt->closeCursor();

                throw new Exception(
                    sprintf(
                        'Payment amount (%s) exceeds current outstanding partner debt (%s). Overpayment not allowed.',
                        self::formatCents($amountCents),
                        self::formatCents($currDebt)
                    ),
                    422
                );
            }

            $newDebt = (int)$res;
            $update->closeCursor();

            // Insert immutable payment record (Rule 8: partner debt changes require billing or payment entry)
            $payId = 'pay_' . bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');

            $insertStmt = $this->pdo->prepare('
                INSERT INTO partner_payments (
                    id, partner_id, amount_cents, admin_id,
                    notes, payment_method, reference_number,
                    debt_after_cents, idempotency_key, created_at
                ) VALUES (
                    :id, :pid, :amount, :admin_id,
                    :notes, :method, :ref,
                    :debt_after, :idempotency, :created_at
                )
            ');
            $insertStmt->execute([
                ':id' => $payId,
                ':pid' => $partnerId,
                ':amount' => $amountCents,
                ':admin_id' => $adminUserId,
                ':notes' => $notes ? trim($notes) : null,
                ':method' => trim($paymentMethod) ?: 'MANUAL',
                ':ref' => $referenceNumber ? trim($referenceNumber) : null,
                ':debt_after' => $newDebt,
                ':idempotency' => $idempotencyKey ? trim($idempotencyKey) : null,
                ':created_at' => $now,
            ]);

            $this->pdo->commit();

            $updatedPartner = $this->getPartnerById($partnerId);

            // Fetch admin username
            $adminStmt = $this->pdo->prepare('SELECT username FROM users WHERE id = :aid');
            $adminStmt->execute([':aid' => $adminUserId]);
            $adminUser = $adminStmt->fetchColumn();

            return [
                'idempotent_replay' => false,
                'partner' => $updatedPartner,
                'payment' => [
                    'id' => $payId,
                    'partner_id' => $partnerId,
                    'amount_cents' => $amountCents,
                    'formatted_amount' => '-' . self::formatCents($amountCents),
                    'admin_id' => $adminUserId,
                    'admin_username' => $adminUser ?: 'Admin',
                    'payment_method' => trim($paymentMethod) ?: 'MANUAL',
                    'reference_number' => $referenceNumber ? trim($referenceNumber) : null,
                    'debt_after_cents' => $newDebt,
                    'formatted_debt_after' => self::formatCents($newDebt),
                    'notes' => $notes ? trim($notes) : null,
                    'idempotency_key' => $idempotencyKey ? trim($idempotencyKey) : null,
                    'created_at' => $now,
                ],
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function updateInvoiceRetention(string $partnerId, bool $enabled): array
    {
        $partner = $this->getPartnerById($partnerId);
        if (!$partner) {
            throw new Exception('Partner not found.', 404);
        }

        $stmt = $this->pdo->prepare('
            UPDATE partners
            SET invoice_retention_enabled = :val, updated_at = datetime("now")
            WHERE id = :pid
        ');
        $stmt->execute([
            ':val' => $enabled ? 1 : 0,
            ':pid' => $partnerId,
        ]);

        return $this->getPartnerById($partnerId);
    }

    public function generateStatement(string $partnerId, ?string $period = null, ?string $notes = null): array
    {
        $partner = $this->getPartnerById($partnerId);
        if (!$partner) {
            throw new Exception('Partner not found.', 404);
        }

        $period = $period ? trim($period) : date('Y-m');

        // Check if statement already generated for this exact period
        $checkStmt = $this->pdo->prepare('
            SELECT * FROM partner_statements
            WHERE partner_id = :pid AND statement_period = :period
            ORDER BY generated_at DESC
            LIMIT 1
        ');
        $checkStmt->execute([
            ':pid' => $partnerId,
            ':period' => $period,
        ]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return [
                'id' => $existing['id'],
                'partner_id' => $existing['partner_id'],
                'statement_period' => $existing['statement_period'],
                'opening_debt_cents' => (int)$existing['opening_debt_cents'],
                'formatted_opening_debt' => self::formatCents((int)$existing['opening_debt_cents']),
                'total_charges_cents' => (int)$existing['total_charges_cents'],
                'formatted_total_charges' => self::formatCents((int)$existing['total_charges_cents']),
                'total_payments_cents' => (int)$existing['total_payments_cents'],
                'formatted_total_payments' => self::formatCents((int)$existing['total_payments_cents']),
                'closing_debt_cents' => (int)$existing['closing_debt_cents'],
                'formatted_closing_debt' => self::formatCents((int)$existing['closing_debt_cents']),
                'status' => $existing['status'],
                'notes' => $existing['notes'],
                'generated_at' => $existing['generated_at'],
                'already_existed' => true,
            ];
        }

        // Sum charges for the period
        $chargesStmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(amount_cents), 0)
            FROM partner_billing_entries
            WHERE partner_id = :pid AND strftime("%Y-%m", created_at) = :period
        ');
        $chargesStmt->execute([
            ':pid' => $partnerId,
            ':period' => $period,
        ]);
        $periodChargesCents = (int)$chargesStmt->fetchColumn();

        // Sum payments for the period
        $paymentsStmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(amount_cents), 0)
            FROM partner_payments
            WHERE partner_id = :pid AND strftime("%Y-%m", created_at) = :period
        ');
        $paymentsStmt->execute([
            ':pid' => $partnerId,
            ':period' => $period,
        ]);
        $periodPaymentsCents = (int)$paymentsStmt->fetchColumn();

        $closingDebtCents = $partner['debt_cents'];
        $openingDebtCents = max(0, $closingDebtCents - $periodChargesCents + $periodPaymentsCents);

        $stmId = 'stm_' . bin2hex(random_bytes(12));
        $now = date('Y-m-d H:i:s');

        $insert = $this->pdo->prepare('
            INSERT INTO partner_statements (
                id, partner_id, statement_period, opening_debt_cents,
                total_charges_cents, total_payments_cents, closing_debt_cents,
                status, notes, generated_at
            ) VALUES (
                :id, :pid, :period, :open,
                :charges, :payments, :close,
                "GENERATED", :notes, :created_at
            )
        ');
        $insert->execute([
            ':id' => $stmId,
            ':pid' => $partnerId,
            ':period' => $period,
            ':open' => $openingDebtCents,
            ':charges' => $periodChargesCents,
            ':payments' => $periodPaymentsCents,
            ':close' => $closingDebtCents,
            ':notes' => $notes ? trim($notes) : null,
            ':created_at' => $now,
        ]);

        return [
            'id' => $stmId,
            'partner_id' => $partnerId,
            'statement_period' => $period,
            'opening_debt_cents' => $openingDebtCents,
            'formatted_opening_debt' => self::formatCents($openingDebtCents),
            'total_charges_cents' => $periodChargesCents,
            'formatted_total_charges' => self::formatCents($periodChargesCents),
            'total_payments_cents' => $periodPaymentsCents,
            'formatted_total_payments' => self::formatCents($periodPaymentsCents),
            'closing_debt_cents' => $closingDebtCents,
            'formatted_closing_debt' => self::formatCents($closingDebtCents),
            'status' => 'GENERATED',
            'notes' => $notes ? trim($notes) : null,
            'generated_at' => $now,
            'already_existed' => false,
        ];
    }

    public function getStatements(string $partnerId): array
    {
        $partner = $this->getPartnerById($partnerId);
        if (!$partner) {
            throw new Exception('Partner not found.', 404);
        }

        $stmt = $this->pdo->prepare('
            SELECT * FROM partner_statements
            WHERE partner_id = :pid
            ORDER BY statement_period DESC, generated_at DESC
        ');
        $stmt->execute([':pid' => $partnerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'id' => $row['id'],
                'partner_id' => $row['partner_id'],
                'statement_period' => $row['statement_period'],
                'opening_debt_cents' => (int)$row['opening_debt_cents'],
                'formatted_opening_debt' => self::formatCents((int)$row['opening_debt_cents']),
                'total_charges_cents' => (int)$row['total_charges_cents'],
                'formatted_total_charges' => self::formatCents((int)$row['total_charges_cents']),
                'total_payments_cents' => (int)$row['total_payments_cents'],
                'formatted_total_payments' => self::formatCents((int)$row['total_payments_cents']),
                'closing_debt_cents' => (int)$row['closing_debt_cents'],
                'formatted_closing_debt' => self::formatCents((int)$row['closing_debt_cents']),
                'status' => $row['status'],
                'notes' => $row['notes'],
                'generated_at' => $row['generated_at'],
            ];
        }, $rows);
    }

    public function listAllPartners(int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $whereClause = 'WHERE p.id != "par_platform_admin"';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $whereClause .= ' AND (p.company_name LIKE :search OR u.username LIKE :search OR u.email LIKE :search)';
            $params[':search'] = '%' . trim($search) . '%';
        }

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM partners p
            JOIN users u ON u.id = p.user_id
            {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT p.id, p.user_id, p.company_name, p.debt_cents, p.invoice_retention_enabled,
                   p.created_at, u.username, u.email,
                   COALESCE((SELECT SUM(amount_cents) FROM partner_billing_entries WHERE partner_id = p.id), 0) AS total_charges_cents,
                   COALESCE((SELECT SUM(amount_cents) FROM partner_payments WHERE partner_id = p.id), 0) AS total_payments_cents
            FROM partners p
            JOIN users u ON u.id = p.user_id
            {$whereClause}
            ORDER BY p.debt_cents DESC, p.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $partners = array_map(function ($row) {
            $debt = (int)$row['debt_cents'];
            $charges = (int)$row['total_charges_cents'];
            $payments = (int)$row['total_payments_cents'];
            return [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'company_name' => $row['company_name'],
                'username' => $row['username'],
                'email' => $row['email'],
                'debt_cents' => $debt,
                'formatted_debt' => self::formatCents($debt),
                'total_charges_cents' => $charges,
                'formatted_total_charges' => self::formatCents($charges),
                'total_payments_cents' => $payments,
                'formatted_total_payments' => self::formatCents($payments),
                'invoice_retention_enabled' => (bool)$row['invoice_retention_enabled'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return [
            'partners' => $partners,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($partners)) < $total,
            ],
        ];
    }
}
