<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\WalletService;
use Exception;

class WalletController
{
    private WalletService $walletService;

    public function __construct(?WalletService $walletService = null)
    {
        $this->walletService = $walletService ?? new WalletService();
    }

    /**
     * GET /api/v1/wallet
     * Retrieve authenticated user's wallet summary & recent ledger entries
     */
    public function getWallet(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        try {
            $summary = $this->walletService->getWalletSummary($user['id']);
            Response::success($summary);
        } catch (Exception $e) {
            Response::error('WALLET_FETCH_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/wallet/transactions
     * Paginated ledger transaction history
     */
    public function getTransactions(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        try {
            $wallet = $this->walletService->getOrCreateWallet($user['id']);
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            $history = $this->walletService->getTransactions($wallet['id'], $limit, $offset);
            Response::success($history);
        } catch (Exception $e) {
            Response::error('TRANSACTIONS_FETCH_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/wallet/topup
     * Customer direct funds top-up (integer minor units cents)
     */
    public function topup(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $body = $request->getBody();
        $amountCents = $this->extractAmountCents($body);

        if ($amountCents === null || $amountCents <= 0) {
            Response::error('INVALID_AMOUNT', 'Please provide a valid top-up amount in cents or currency.', 422);
            return;
        }

        $idempotencyKey = $request->getHeader('idempotency-key') ?? ($body['idempotency_key'] ?? null);
        $description = isset($body['description']) ? trim((string)$body['description']) : null;

        try {
            $result = $this->walletService->topupCustomer($user['id'], $amountCents, $idempotencyKey, $description);
            Response::json([
                'success' => true,
                'message' => 'Funds successfully added to wallet.',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            $statusCode = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error('TOPUP_FAILED', $e->getMessage(), $statusCode);
        }
    }

    /**
     * POST /api/v1/partner/wallet/credit
     * Partner credits customer balance, automatically increasing partner debt
     */
    public function partnerCredit(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('ADMIN', $roles, true);
        $isPartner = in_array('PARTNER', $roles, true);

        if (!$isAdmin && !$isPartner) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $body = $request->getBody();
        $identifier = trim((string)($body['customer_id'] ?? $body['identifier'] ?? $body['username'] ?? ''));

        if ($identifier === '') {
            Response::error('INVALID_CUSTOMER', 'Customer ID, username, or email is required.', 422);
            return;
        }

        $customer = $this->walletService->findCustomerByIdOrUsername($identifier);
        if (!$customer) {
            Response::error('CUSTOMER_NOT_FOUND', "Customer '{$identifier}' not found.", 404);
            return;
        }

        $amountCents = $this->extractAmountCents($body);
        if ($amountCents === null || $amountCents <= 0) {
            Response::error('INVALID_AMOUNT', 'Please provide a valid positive credit amount in cents.', 422);
            return;
        }

        $notes = isset($body['notes']) ? trim((string)$body['notes']) : null;
        $idempotencyKey = $request->getHeader('idempotency-key') ?? ($body['idempotency_key'] ?? null);

        try {
            $result = $this->walletService->partnerCreditCustomer(
                $user['id'],
                $customer['id'],
                $amountCents,
                $idempotencyKey,
                $notes
            );

            Response::json([
                'success' => true,
                'message' => 'Customer account credited successfully.',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            $statusCode = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error('CREDIT_FAILED', $e->getMessage(), $statusCode);
        }
    }

    /**
     * GET /api/v1/partner/wallet/customers
     * List customers for partner crediting
     */
    public function listCustomers(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('ADMIN', $roles, true);
        $isPartner = in_array('PARTNER', $roles, true);

        if (!$isAdmin && !$isPartner) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : null;

        try {
            $customers = $this->walletService->listCustomersForPartner($user['id'], $search);
            Response::success([
                'customers' => $customers,
                'total' => count($customers),
            ]);
        } catch (Exception $e) {
            Response::error('CUSTOMERS_FETCH_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Helper to safely extract integer minor units (cents) from request payload
     */
    private function extractAmountCents(array $body): ?int
    {
        if (isset($body['amount_cents'])) {
            $val = filter_var($body['amount_cents'], FILTER_VALIDATE_INT);
            if ($val !== false) {
                return (int)$val;
            }
        }

        if (isset($body['amount'])) {
            if (is_numeric($body['amount'])) {
                return (int)round((float)$body['amount'] * 100);
            }
        }

        return null;
    }
}
