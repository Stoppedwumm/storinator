<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PartnerBillingService;
use Exception;

class PartnerBillingController
{
    private PartnerBillingService $billingService;

    public function __construct(?PartnerBillingService $billingService = null)
    {
        $this->billingService = $billingService ?? new PartnerBillingService();
    }

    private function resolvePartnerId(Request $request, array $user): ?string
    {
        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('ADMIN', $roles, true);

        // If admin specifies partner_id in query params, use that
        $queryParams = $request->getQueryParams();
        if ($isAdmin && !empty($queryParams['partner_id'])) {
            return trim((string)$queryParams['partner_id']);
        }

        // Otherwise find partner associated with authenticated user
        $partner = $this->billingService->getPartnerByUserId($user['id']);
        return $partner['id'] ?? null;
    }

    /**
     * GET /api/v1/partner/billing
     * Partner or Admin retrieves partner billing overview and debt summary
     */
    public function getSummary(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $partnerId = $this->resolvePartnerId($request, $user);
        if (!$partnerId) {
            Response::error('PARTNER_NOT_FOUND', 'No partner profile associated with this account.', 404);
            return;
        }

        try {
            $summary = $this->billingService->getBillingSummary($partnerId);
            Response::success($summary);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 500;
            Response::error('BILLING_FETCH_FAILED', $e->getMessage(), $code);
        }
    }

    /**
     * GET /api/v1/partner/billing/entries
     * Paginated partner billing ledger charges
     */
    public function getEntries(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $partnerId = $this->resolvePartnerId($request, $user);
        if (!$partnerId) {
            Response::error('PARTNER_NOT_FOUND', 'No partner profile associated with this account.', 404);
            return;
        }

        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? (int)$query['limit'] : 20;
        $offset = isset($query['offset']) ? (int)$query['offset'] : 0;
        $opType = isset($query['operation_type']) ? (string)$query['operation_type'] : null;

        try {
            $result = $this->billingService->getBillingEntries($partnerId, $limit, $offset, $opType);
            Response::success($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 500;
            Response::error('ENTRIES_FETCH_FAILED', $e->getMessage(), $code);
        }
    }

    /**
     * GET /api/v1/partner/billing/payments
     * Paginated partner payment settlement history
     */
    public function getPayments(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $partnerId = $this->resolvePartnerId($request, $user);
        if (!$partnerId) {
            Response::error('PARTNER_NOT_FOUND', 'No partner profile associated with this account.', 404);
            return;
        }

        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? (int)$query['limit'] : 20;
        $offset = isset($query['offset']) ? (int)$query['offset'] : 0;

        try {
            $result = $this->billingService->getPayments($partnerId, $limit, $offset);
            Response::success($result);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 500;
            Response::error('PAYMENTS_FETCH_FAILED', $e->getMessage(), $code);
        }
    }

    /**
     * GET /api/v1/partner/billing/statements
     * Retrieve partner generated statements
     */
    public function getStatements(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $partnerId = $this->resolvePartnerId($request, $user);
        if (!$partnerId) {
            Response::error('PARTNER_NOT_FOUND', 'No partner profile associated with this account.', 404);
            return;
        }

        try {
            $statements = $this->billingService->getStatements($partnerId);
            Response::success(['statements' => $statements]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 500;
            Response::error('STATEMENTS_FETCH_FAILED', $e->getMessage(), $code);
        }
    }

    /**
     * POST /api/v1/partner/billing/statements/generate
     * Generate formal accounting statement for a period
     */
    public function generateStatement(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $partnerId = $this->resolvePartnerId($request, $user);
        if (!$partnerId) {
            Response::error('PARTNER_NOT_FOUND', 'No partner profile associated with this account.', 404);
            return;
        }

        $body = $request->getBody();
        $period = !empty($body['period']) ? trim((string)$body['period']) : null;
        $notes = !empty($body['notes']) ? trim((string)$body['notes']) : null;

        try {
            $statement = $this->billingService->generateStatement($partnerId, $period, $notes);
            Response::json([
                'success' => true,
                'message' => 'Statement generated successfully.',
                'data' => ['statement' => $statement],
            ], 201);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 500;
            Response::error('STATEMENT_GENERATION_FAILED', $e->getMessage(), $code);
        }
    }

    /**
     * POST /api/v1/partner/settings/invoice-retention
     * Partner or Admin toggles invoice retention setting
     */
    public function updateInvoiceRetention(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Partner or Admin role required', 403);
            return;
        }

        $partnerId = $this->resolvePartnerId($request, $user);
        if (!$partnerId) {
            Response::error('PARTNER_NOT_FOUND', 'No partner profile associated with this account.', 404);
            return;
        }

        $body = $request->getBody();
        if (!isset($body['enabled'])) {
            Response::error('INVALID_SETTING', 'Field "enabled" (boolean) is required.', 422);
            return;
        }

        $enabled = (bool)$body['enabled'];

        try {
            $updated = $this->billingService->updateInvoiceRetention($partnerId, $enabled);
            Response::json([
                'success' => true,
                'message' => 'Invoice retention setting updated.',
                'data' => ['partner' => $updated],
            ], 200);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 500;
            Response::error('SETTING_UPDATE_FAILED', $e->getMessage(), $code);
        }
    }

    /**
     * GET /api/v1/admin/billing/partners
     * Admin view of all partners with outstanding debts
     */
    public function adminListPartners(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Admin role required', 403);
            return;
        }

        $query = $request->getQueryParams();
        $limit = isset($query['limit']) ? (int)$query['limit'] : 50;
        $offset = isset($query['offset']) ? (int)$query['offset'] : 0;
        $search = isset($query['search']) ? (string)$query['search'] : null;

        try {
            $result = $this->billingService->listAllPartners($limit, $offset, $search);
            Response::success($result);
        } catch (Exception $e) {
            Response::error('ADMIN_PARTNERS_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/admin/billing/settle
     * Admin marks part or all of partner debt as paid
     */
    public function adminSettleDebt(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Access denied: Admin role required', 403);
            return;
        }

        $body = $request->getBody();
        $partnerId = trim((string)($body['partner_id'] ?? ''));
        if ($partnerId === '') {
            Response::error('INVALID_PARTNER', 'partner_id is required.', 422);
            return;
        }

        $amountCents = null;
        if (isset($body['amount_cents']) && is_numeric($body['amount_cents'])) {
            $amountCents = (int)$body['amount_cents'];
        } elseif (isset($body['amount_eur']) && is_numeric($body['amount_eur'])) {
            $amountCents = (int)round((float)$body['amount_eur'] * 100);
        }

        if ($amountCents === null || $amountCents <= 0) {
            Response::error('INVALID_AMOUNT', 'Please provide a valid positive payment settlement amount.', 422);
            return;
        }

        $method = isset($body['payment_method']) ? trim((string)$body['payment_method']) : 'MANUAL';
        $ref = isset($body['reference_number']) ? trim((string)$body['reference_number']) : null;
        $notes = isset($body['notes']) ? trim((string)$body['notes']) : null;
        $idempotencyKey = $request->getHeader('idempotency-key') ?? ($body['idempotency_key'] ?? null);

        try {
            $result = $this->billingService->settlePayment(
                $user['id'],
                $partnerId,
                $amountCents,
                $method,
                $ref,
                $notes,
                $idempotencyKey
            );

            Response::json([
                'success' => true,
                'message' => 'Partner debt payment recorded successfully.',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error('SETTLEMENT_FAILED', $e->getMessage(), $code);
        }
    }
}
