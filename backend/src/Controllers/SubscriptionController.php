<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BigStore\BigStoreClient;
use App\Core\Request;
use App\Core\Response;
use App\Services\SubscriptionService;
use Exception;

class SubscriptionController
{
    private SubscriptionService $subscriptionService;

    public function __construct()
    {
        $bigStore = new BigStoreClient();
        $this->subscriptionService = new SubscriptionService($bigStore);
    }

    /**
     * POST /api/v1/subscriptions/request
     */
    public function request(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $body = $request->getBody();
        $partnerId = isset($body['partner_id']) && !empty($body['partner_id']) ? (string)$body['partner_id'] : null;
        $notes = isset($body['notes']) ? (string)$body['notes'] : null;

        try {
            $req = $this->subscriptionService->requestSubscription($user['id'], $partnerId, $notes);
            Response::json([
                'success' => true,
                'request' => $req,
                'message' => 'Subscription request submitted successfully.',
            ], 201);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error($status === 409 ? 'CONFLICT' : 'REQUEST_FAILED', $e->getMessage(), $status);
        }
    }

    /**
     * GET /api/v1/subscriptions/current
     */
    public function current(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        $sub = $this->subscriptionService->getSubscriptionForUser($user['id']);
        $req = $this->subscriptionService->getLatestRequestForUser($user['id']);
        $isActive = $this->subscriptionService->hasActiveSubscription($user['id']);

        Response::json([
            'success' => true,
            'is_active' => $isActive,
            'is_exempt_from_platform_fee' => $isActive,
            'subscription' => $sub,
            'latest_request' => $req,
            'monthly_fee_cents' => SubscriptionService::MONTHLY_FEE_CENTS,
            'quota_bytes' => SubscriptionService::DEFAULT_QUOTA_BYTES,
        ], 200);
    }

    /**
     * POST /api/v1/subscriptions/cancel
     */
    public function cancel(Request $request, array $params = []): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return;
        }

        try {
            $sub = $this->subscriptionService->cancelSubscription($user['id'], $user['id']);
            Response::json([
                'success' => true,
                'subscription' => $sub,
                'message' => 'Subscription cancelled successfully.',
            ], 200);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error('CANCEL_FAILED', $e->getMessage(), $status);
        }
    }

    /**
     * GET /api/v1/partner/subscription-requests
     */
    public function listPartnerRequests(Request $request, array $params = []): void
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

        $partnerId = null;
        if (!$isAdmin) {
            $partnerId = $this->subscriptionService->getPartnerIdByUserId($user['id']);
            if (!$partnerId) {
                Response::error('NOT_FOUND', 'Partner profile not found for user', 404);
                return;
            }
        }

        $query = $request->getQueryParams();
        $status = $query['status'] ?? null;

        $requests = $this->subscriptionService->listRequests($partnerId, $status);
        Response::json([
            'success' => true,
            'requests' => $requests,
        ], 200);
    }

    /**
     * POST /api/v1/partner/subscription-requests/{id}/approve
     */
    public function approveRequest(Request $request, array $params = []): void
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
            Response::error('FORBIDDEN', 'Access denied', 403);
            return;
        }

        $partnerId = $isPartner ? $this->subscriptionService->getPartnerIdByUserId($user['id']) : null;
        $requestId = $params['id'] ?? '';

        try {
            $result = $this->subscriptionService->approveRequest($requestId, $user['id'], $partnerId, $isAdmin);
            Response::json([
                'success' => true,
                'message' => 'Subscription approved and activated.',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error('APPROVE_FAILED', $e->getMessage(), $status);
        }
    }

    /**
     * POST /api/v1/partner/subscription-requests/{id}/reject
     */
    public function rejectRequest(Request $request, array $params = []): void
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
            Response::error('FORBIDDEN', 'Access denied', 403);
            return;
        }

        $partnerId = $isPartner ? $this->subscriptionService->getPartnerIdByUserId($user['id']) : null;
        $requestId = $params['id'] ?? '';
        $body = $request->getBody();
        $reason = $body['reason'] ?? null;

        try {
            $req = $this->subscriptionService->rejectRequest($requestId, $user['id'], $partnerId, $isAdmin, $reason);
            Response::json([
                'success' => true,
                'message' => 'Subscription request rejected.',
                'request' => $req,
            ], 200);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error('REJECT_FAILED', $e->getMessage(), $status);
        }
    }

    /**
     * POST /api/v1/partner/subscriptions/{id}/renew
     */
    public function renewSubscription(Request $request, array $params = []): void
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
            Response::error('FORBIDDEN', 'Access denied', 403);
            return;
        }

        $partnerId = $isPartner ? $this->subscriptionService->getPartnerIdByUserId($user['id']) : null;
        $subscriptionId = $params['id'] ?? '';

        try {
            $sub = $this->subscriptionService->renewSubscription($subscriptionId, $user['id'], $partnerId, $isAdmin);
            Response::json([
                'success' => true,
                'message' => 'Subscription renewed successfully.',
                'subscription' => $sub,
            ], 200);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            Response::error('RENEW_FAILED', $e->getMessage(), $status);
        }
    }
}
