<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;
use PDO;

class WalletService
{
    public const DEFAULT_CURRENCY = 'EUR';
    public const MAX_TOPUP_CENTS = 1000000; // 10,000.00 EUR max per transaction
    public const MIN_TOPUP_CENTS = 100;     // 1.00 EUR min per transaction

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Format minor integer cents to EUR currency string
     */
    public static function formatCents(int $cents, string $currency = 'EUR'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        $euros = intdiv($abs, 100);
        $remainder = $abs % 100;
        return sprintf('%s%d.%02d €', $sign, $euros, $remainder);
    }

    /**
     * Get or lazily create a wallet for a user
     */
    public function getOrCreateWallet(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wallets WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            $walletId = 'wal_' . bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');
            $insert = $this->pdo->prepare('
                INSERT INTO wallets (id, user_id, balance_cents, currency, created_at, updated_at)
                VALUES (:id, :uid, 0, :currency, :created_at, :updated_at)
            ');
            $insert->execute([
                ':id' => $walletId,
                ':uid' => $userId,
                ':currency' => self::DEFAULT_CURRENCY,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            return [
                'id' => $walletId,
                'user_id' => $userId,
                'balance_cents' => 0,
                'currency' => self::DEFAULT_CURRENCY,
                'formatted_balance' => self::formatCents(0, self::DEFAULT_CURRENCY),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $wallet['balance_cents'] = (int)$wallet['balance_cents'];
        $wallet['formatted_balance'] = self::formatCents($wallet['balance_cents'], $wallet['currency'] ?? self::DEFAULT_CURRENCY);
        return $wallet;
    }

    /**
     * Get wallet details and recent ledger transactions for a user
     */
    public function getWalletSummary(string $userId, int $recentLimit = 5): array
    {
        $wallet = $this->getOrCreateWallet($userId);
        $history = $this->getTransactions($wallet['id'], $recentLimit, 0);

        return [
            'wallet' => $wallet,
            'recent_transactions' => $history['transactions'],
            'total_transactions' => $history['pagination']['total'],
        ];
    }

    /**
     * Get paginated ledger transactions for a wallet
     */
    public function getTransactions(string $walletId, int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM wallet_transactions WHERE wallet_id = :wid');
        $countStmt->execute([':wid' => $walletId]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare('
            SELECT * FROM wallet_transactions
            WHERE wallet_id = :wid
            ORDER BY created_at DESC, id DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':wid', $walletId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $transactions = array_map(function ($row) {
            $amountCents = (int)$row['amount_cents'];
            $balanceAfter = (int)$row['balance_after_cents'];
            return [
                'id' => $row['id'],
                'wallet_id' => $row['wallet_id'],
                'amount_cents' => $amountCents,
                'formatted_amount' => ($amountCents > 0 ? '+' : '') . self::formatCents($amountCents),
                'balance_after_cents' => $balanceAfter,
                'formatted_balance_after' => self::formatCents($balanceAfter),
                'transaction_type' => $row['transaction_type'],
                'reference_type' => $row['reference_type'],
                'reference_id' => $row['reference_id'],
                'description' => $row['description'],
                'idempotency_key' => $row['idempotency_key'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return [
            'transactions' => $transactions,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + count($transactions)) < $total,
            ],
        ];
    }

    /**
     * Customer self-service or direct funds top-up
     */
    public function topupCustomer(
        string $userId,
        int $amountCents,
        ?string $idempotencyKey = null,
        ?string $description = null
    ): array {
        if ($amountCents < self::MIN_TOPUP_CENTS) {
            throw new Exception(
                sprintf('Top-up amount must be at least %s.', self::formatCents(self::MIN_TOPUP_CENTS)),
                422
            );
        }

        if ($amountCents > self::MAX_TOPUP_CENTS) {
            throw new Exception(
                sprintf('Top-up amount cannot exceed %s.', self::formatCents(self::MAX_TOPUP_CENTS)),
                422
            );
        }

        $wallet = $this->getOrCreateWallet($userId);
        $walletId = $wallet['id'];

        // Strict idempotency check
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->findTransactionByIdempotency($walletId, trim($idempotencyKey));
            if ($existing) {
                return [
                    'idempotent_replay' => true,
                    'wallet' => $this->getOrCreateWallet($userId),
                    'transaction' => $existing,
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            // Lock and retrieve current wallet balance
            $stmt = $this->pdo->prepare('SELECT balance_cents FROM wallets WHERE id = :wid');
            $stmt->execute([':wid' => $walletId]);
            $currentBalance = (int)$stmt->fetchColumn();

            $newBalance = $currentBalance + $amountCents;

            // Update wallet balance
            $update = $this->pdo->prepare('
                UPDATE wallets
                SET balance_cents = :new_balance, updated_at = datetime("now")
                WHERE id = :wid
            ');
            $update->execute([
                ':new_balance' => $newBalance,
                ':wid' => $walletId,
            ]);

            // Append-only ledger mutation (Rule 7)
            $txId = 'wtx_' . bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');
            $txDesc = $description ?? ('Customer direct funds top-up of ' . self::formatCents($amountCents));

            $insertTx = $this->pdo->prepare('
                INSERT INTO wallet_transactions (
                    id, wallet_id, amount_cents, balance_after_cents,
                    transaction_type, reference_type, reference_id,
                    description, idempotency_key, created_at
                ) VALUES (
                    :id, :wid, :amount, :bal_after,
                    "CUSTOMER_TOPUP", "customer_deposit", :ref_id,
                    :desc, :idempotency, :created_at
                )
            ');
            $insertTx->execute([
                ':id' => $txId,
                ':wid' => $walletId,
                ':amount' => $amountCents,
                ':bal_after' => $newBalance,
                ':ref_id' => $txId,
                ':desc' => $txDesc,
                ':idempotency' => $idempotencyKey ? trim($idempotencyKey) : null,
                ':created_at' => $now,
            ]);

            // Audit log
            $this->recordAudit($userId, 'WALLET_TOPUP', 'wallets', $walletId, [
                'transaction_id' => $txId,
                'amount_cents' => $amountCents,
                'balance_before_cents' => $currentBalance,
                'balance_after_cents' => $newBalance,
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $formattedTx = [
            'id' => $txId,
            'wallet_id' => $walletId,
            'amount_cents' => $amountCents,
            'formatted_amount' => '+' . self::formatCents($amountCents),
            'balance_after_cents' => $newBalance,
            'formatted_balance_after' => self::formatCents($newBalance),
            'transaction_type' => 'CUSTOMER_TOPUP',
            'reference_type' => 'customer_deposit',
            'reference_id' => $txId,
            'description' => $txDesc,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $now,
        ];

        return [
            'idempotent_replay' => false,
            'wallet' => [
                'id' => $walletId,
                'user_id' => $userId,
                'balance_cents' => $newBalance,
                'formatted_balance' => self::formatCents($newBalance),
                'currency' => self::DEFAULT_CURRENCY,
            ],
            'transaction' => $formattedTx,
        ];
    }

    /**
     * Partner credits customer wallet.
     * Atomically adds money to customer wallet and increases partner debt (Rule 8).
     */
    public function partnerCreditCustomer(
        string $partnerUserId,
        string $customerId,
        int $amountCents,
        ?string $idempotencyKey = null,
        ?string $notes = null
    ): array {
        if ($amountCents < self::MIN_TOPUP_CENTS) {
            throw new Exception(
                sprintf('Credit amount must be at least %s.', self::formatCents(self::MIN_TOPUP_CENTS)),
                422
            );
        }

        if ($amountCents > self::MAX_TOPUP_CENTS) {
            throw new Exception(
                sprintf('Credit amount cannot exceed %s.', self::formatCents(self::MAX_TOPUP_CENTS)),
                422
            );
        }

        // Verify partner profile
        $partnerStmt = $this->pdo->prepare('SELECT * FROM partners WHERE user_id = :uid LIMIT 1');
        $partnerStmt->execute([':uid' => $partnerUserId]);
        $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$partner) {
            // Check if user is an ADMIN operating on behalf of platform
            $userRoleStmt = $this->pdo->prepare('
                SELECT ur.role_id FROM user_roles ur
                WHERE ur.user_id = :uid AND ur.role_id IN ("PARTNER", "ADMIN")
            ');
            $userRoleStmt->execute([':uid' => $partnerUserId]);
            $hasRole = $userRoleStmt->fetchColumn();

            if (!$hasRole) {
                throw new Exception('Forbidden: Only authorized partners or administrators may credit customer accounts.', 403);
            }

            // Create or fallback to default partner account for admin
            $partner = [
                'id' => 'par_platform_admin',
                'company_name' => 'Platform Operator',
                'debt_cents' => 0,
            ];
        }

        // Verify customer exists
        $custStmt = $this->pdo->prepare('SELECT id, username, email FROM users WHERE id = :cid LIMIT 1');
        $custStmt->execute([':cid' => $customerId]);
        $customer = $custStmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            throw new Exception('Customer not found.', 404);
        }

        $customerWallet = $this->getOrCreateWallet($customerId);
        $customerWalletId = $customerWallet['id'];

        // Strict idempotency check
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->findTransactionByIdempotency($customerWalletId, trim($idempotencyKey));
            if ($existing) {
                return [
                    'idempotent_replay' => true,
                    'customer' => $customer,
                    'customer_wallet' => $this->getOrCreateWallet($customerId),
                    'transaction' => $existing,
                    'partner_debt_cents' => (int)($partner['debt_cents'] ?? 0),
                    'formatted_partner_debt' => self::formatCents((int)($partner['debt_cents'] ?? 0)),
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Lock customer wallet and get current balance
            $wStmt = $this->pdo->prepare('SELECT balance_cents FROM wallets WHERE id = :wid');
            $wStmt->execute([':wid' => $customerWalletId]);
            $currentBalance = (int)$wStmt->fetchColumn();

            $newBalance = $currentBalance + $amountCents;

            // 2. Update customer wallet balance
            $wUpdate = $this->pdo->prepare('
                UPDATE wallets
                SET balance_cents = :new_balance, updated_at = datetime("now")
                WHERE id = :wid
            ');
            $wUpdate->execute([
                ':new_balance' => $newBalance,
                ':wid' => $customerWalletId,
            ]);

            // 3. Append customer wallet ledger transaction (Rule 7)
            $txId = 'wtx_' . bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');
            $desc = $notes ? trim($notes) : sprintf('Credit from %s', $partner['company_name'] ?? 'Partner');

            $txInsert = $this->pdo->prepare('
                INSERT INTO wallet_transactions (
                    id, wallet_id, amount_cents, balance_after_cents,
                    transaction_type, reference_type, reference_id,
                    description, idempotency_key, created_at
                ) VALUES (
                    :id, :wid, :amount, :bal_after,
                    "PARTNER_TOPUP", "partner", :ref_id,
                    :desc, :idempotency, :created_at
                )
            ');
            $txInsert->execute([
                ':id' => $txId,
                ':wid' => $customerWalletId,
                ':amount' => $amountCents,
                ':bal_after' => $newBalance,
                ':ref_id' => $partner['id'],
                ':desc' => $desc,
                ':idempotency' => $idempotencyKey ? trim($idempotencyKey) : null,
                ':created_at' => $now,
            ]);

            // 4. Increase partner debt (debt_cents + amount_cents) and record partner billing entry (Rule 8)
            $partnerDebtAfter = 0;
            if ($partner['id'] !== 'par_platform_admin') {
                $pUpdate = $this->pdo->prepare('
                    UPDATE partners
                    SET debt_cents = debt_cents + :amount, updated_at = datetime("now")
                    WHERE id = :pid
                ');
                $pUpdate->execute([
                    ':amount' => $amountCents,
                    ':pid' => $partner['id'],
                ]);

                $pDebtStmt = $this->pdo->prepare('SELECT debt_cents FROM partners WHERE id = :pid');
                $pDebtStmt->execute([':pid' => $partner['id']]);
                $partnerDebtAfter = (int)$pDebtStmt->fetchColumn();

                $pbeId = 'pbe_' . bin2hex(random_bytes(12));
                $pbeInsert = $this->pdo->prepare('
                    INSERT INTO partner_billing_entries (
                        id, partner_id, amount_cents, operation_type,
                        reference_id, description, created_at
                    ) VALUES (
                        :id, :pid, :amount, "CUSTOMER_TOPUP",
                        :ref_id, :desc, :created_at
                    )
                ');
                $pbeInsert->execute([
                    ':id' => $pbeId,
                    ':pid' => $partner['id'],
                    ':amount' => $amountCents,
                    ':ref_id' => $txId,
                    ':desc' => sprintf('Account credit for customer %s (%s)', $customer['username'], self::formatCents($amountCents)),
                    ':created_at' => $now,
                ]);
            }

            // 5. Audit log
            $this->recordAudit($partnerUserId, 'PARTNER_CUSTOMER_CREDIT', 'wallets', $customerWalletId, [
                'partner_id' => $partner['id'],
                'customer_id' => $customerId,
                'amount_cents' => $amountCents,
                'customer_balance_after' => $newBalance,
                'partner_debt_after' => $partnerDebtAfter,
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $formattedTx = [
            'id' => $txId,
            'wallet_id' => $customerWalletId,
            'amount_cents' => $amountCents,
            'formatted_amount' => '+' . self::formatCents($amountCents),
            'balance_after_cents' => $newBalance,
            'formatted_balance_after' => self::formatCents($newBalance),
            'transaction_type' => 'PARTNER_TOPUP',
            'reference_type' => 'partner',
            'reference_id' => $partner['id'],
            'description' => $desc,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $now,
        ];

        return [
            'idempotent_replay' => false,
            'customer' => $customer,
            'customer_wallet' => [
                'id' => $customerWalletId,
                'user_id' => $customerId,
                'balance_cents' => $newBalance,
                'formatted_balance' => self::formatCents($newBalance),
                'currency' => self::DEFAULT_CURRENCY,
            ],
            'transaction' => $formattedTx,
            'partner_debt_cents' => $partnerDebtAfter,
            'formatted_partner_debt' => self::formatCents($partnerDebtAfter),
        ];
    }

    /**
     * Deduct funds from user wallet (e.g. store purchase, order payment)
     */
    public function deductFunds(
        string $userId,
        int $amountCents,
        string $transactionType = 'STORE_PURCHASE',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
        ?string $idempotencyKey = null
    ): array {
        if ($amountCents <= 0) {
            throw new Exception('Deduction amount must be greater than zero.', 422);
        }

        $wallet = $this->getOrCreateWallet($userId);
        $walletId = $wallet['id'];

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->findTransactionByIdempotency($walletId, trim($idempotencyKey));
            if ($existing) {
                return [
                    'idempotent_replay' => true,
                    'wallet' => $this->getOrCreateWallet($userId),
                    'transaction' => $existing,
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT balance_cents FROM wallets WHERE id = :wid');
            $stmt->execute([':wid' => $walletId]);
            $currentBalance = (int)$stmt->fetchColumn();

            if ($currentBalance < $amountCents) {
                throw new Exception(
                    sprintf(
                        'Insufficient funds. Current balance: %s, required: %s.',
                        self::formatCents($currentBalance),
                        self::formatCents($amountCents)
                    ),
                    402 // Payment Required
                );
            }

            $newBalance = $currentBalance - $amountCents;

            $update = $this->pdo->prepare('
                UPDATE wallets
                SET balance_cents = :new_balance, updated_at = datetime("now")
                WHERE id = :wid
            ');
            $update->execute([
                ':new_balance' => $newBalance,
                ':wid' => $walletId,
            ]);

            $txId = 'wtx_' . bin2hex(random_bytes(12));
            $now = date('Y-m-d H:i:s');
            $negativeAmount = -$amountCents;

            $insertTx = $this->pdo->prepare('
                INSERT INTO wallet_transactions (
                    id, wallet_id, amount_cents, balance_after_cents,
                    transaction_type, reference_type, reference_id,
                    description, idempotency_key, created_at
                ) VALUES (
                    :id, :wid, :amount, :bal_after,
                    :type, :ref_type, :ref_id,
                    :desc, :idempotency, :created_at
                )
            ');
            $insertTx->execute([
                ':id' => $txId,
                ':wid' => $walletId,
                ':amount' => $negativeAmount,
                ':bal_after' => $newBalance,
                ':type' => $transactionType,
                ':ref_type' => $referenceType,
                ':ref_id' => $referenceId,
                ':desc' => $description ?? ('Payment deduction of ' . self::formatCents($amountCents)),
                ':idempotency' => $idempotencyKey ? trim($idempotencyKey) : null,
                ':created_at' => $now,
            ]);

            $this->recordAudit($userId, 'WALLET_DEDUCT', 'wallets', $walletId, [
                'transaction_id' => $txId,
                'amount_cents' => $amountCents,
                'balance_before_cents' => $currentBalance,
                'balance_after_cents' => $newBalance,
                'transaction_type' => $transactionType,
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'idempotent_replay' => false,
            'wallet' => [
                'id' => $walletId,
                'user_id' => $userId,
                'balance_cents' => $newBalance,
                'formatted_balance' => self::formatCents($newBalance),
                'currency' => self::DEFAULT_CURRENCY,
            ],
            'transaction' => [
                'id' => $txId,
                'wallet_id' => $walletId,
                'amount_cents' => $negativeAmount,
                'formatted_amount' => self::formatCents($negativeAmount),
                'balance_after_cents' => $newBalance,
                'formatted_balance_after' => self::formatCents($newBalance),
                'transaction_type' => $transactionType,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
                'created_at' => $now,
            ],
        ];
    }

    /**
     * Find existing transaction by idempotency key
     */
    private function findTransactionByIdempotency(string $walletId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM wallet_transactions
            WHERE wallet_id = :wid AND idempotency_key = :key
            LIMIT 1
        ');
        $stmt->execute([
            ':wid' => $walletId,
            ':key' => $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $amountCents = (int)$row['amount_cents'];
        $balanceAfter = (int)$row['balance_after_cents'];

        return [
            'id' => $row['id'],
            'wallet_id' => $row['wallet_id'],
            'amount_cents' => $amountCents,
            'formatted_amount' => ($amountCents > 0 ? '+' : '') . self::formatCents($amountCents),
            'balance_after_cents' => $balanceAfter,
            'formatted_balance_after' => self::formatCents($balanceAfter),
            'transaction_type' => $row['transaction_type'],
            'reference_type' => $row['reference_type'],
            'reference_id' => $row['reference_id'],
            'description' => $row['description'],
            'idempotency_key' => $row['idempotency_key'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * List customers for partner wallet crediting
     */
    public function listCustomersForPartner(string $partnerUserId, ?string $search = null): array
    {
        $query = '
            SELECT u.id, u.username, u.email, u.status, u.created_at,
                   COALESCE(w.balance_cents, 0) as balance_cents,
                   COALESCE(w.currency, "EUR") as currency
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id AND ur.role_id = "CUSTOMER"
            LEFT JOIN wallets w ON u.id = w.user_id
        ';
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $query .= ' WHERE (u.username LIKE :s OR u.email LIKE :s)';
            $params[':s'] = '%' . trim($search) . '%';
        }

        $query .= ' ORDER BY u.created_at DESC LIMIT 50';

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $cents = (int)$row['balance_cents'];
            return [
                'id' => $row['id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'status' => $row['status'],
                'balance_cents' => $cents,
                'formatted_balance' => self::formatCents($cents, $row['currency']),
                'currency' => $row['currency'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * Find customer by user ID, username, or email
     */
    public function findCustomerByIdOrUsername(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT u.id, u.username, u.email, u.status
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id AND ur.role_id = "CUSTOMER"
            WHERE u.id = :id OR LOWER(u.username) = LOWER(:u) OR LOWER(u.email) = LOWER(:e)
            LIMIT 1
        ');
        $stmt->execute([
            ':id' => $identifier,
            ':u' => $identifier,
            ':e' => $identifier,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Record system audit log
     */
    private function recordAudit(string $actorId, string $action, string $targetType, string $targetId, array $metadata): void
    {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO audit_logs (actor_id, action, target_type, target_id, metadata, created_at)
                VALUES (:actor, :act, :type, :tid, :meta, datetime("now"))
            ');
            $stmt->execute([
                ':actor' => $actorId,
                ':act' => $action,
                ':type' => $targetType,
                ':tid' => $targetId,
                ':meta' => json_encode($metadata),
            ]);
        } catch (Exception $e) {
            // Non-blocking audit failure
        }
    }
}
