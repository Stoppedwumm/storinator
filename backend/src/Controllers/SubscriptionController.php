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
    public function request(Request $request, Response $response): void
    {
        $user = $request->getUser();
        if (!$user) {
            $response->status(401)->json([
                'success' => false,
                'error' => 'Authentication required',
                'code' => 'UNAUTHENTICATED',
            ]);
            return;
        }

        $body = $request->getBody();
        $partnerId = isset($body['partner_id']) && !empty($body['partner_id']) ? (string)$body['partner_id'] : null;
        $notes = isset($body['notes']) ? (string)$body['notes'] : null;

        try {
            $req = $this->subscriptionService->requestSubscription($user['id'], $partnerId, $notes);
            $response->status(201)->json([
                'success' => true,
                'request' => $req,
                'message' => 'Subscription request submitted successfully.',
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            $response->status($status)->json([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $status === 409 ? 'CONFLICT' : 'REQUEST_FAILED',
            ]);
        }
    }

    /**
     * GET /api/v1/subscriptions/current
     */
    public function current(Request $request, Response $response): void
    {
        $user = $request->getUser();
        if (!$user) {
            $response->status(401)->json([
                'success' => false,
                'error' => 'Authentication required',
                'code' => 'UNAUTHENTICATED',
            ]);
            return;
        }

        $sub = $this->subscriptionService->getSubscriptionForUser($user['id']);
        $req = $this->subscriptionService->getLatestRequestForUser($user['id']);
        $isActive = $this->subscriptionService->hasActiveSubscription($user['id']);

        $response->status(200)->json([
            'success' => true,
            'is_active' => $isActive,
            'is_exempt_from_platform_fee' => $isActive,
            'subscription' => $sub,
            'latest_request' => $req,
            'monthly_fee_cents' => SubscriptionService::MONTHLY_FEE_CENTS,
            'quota_bytes' => SubscriptionService::DEFAULT_QUOTA_BYTES,
        ]);
    }

    /**
     * POST /api/v1/subscriptions/cancel
     */
    public function cancel(Request $request, Response $response): void
    {
        $user = $request->getUser();
        if (!$user) {
            $response->status(401)->json([
                'success' => false,
                'error' => 'Authentication required',
                'code' => 'UNAUTHENTICATED',
            ]);
            return;
        }

        try {
            $sub = $this->subscriptionService->cancelSubscription($user['id'], $user['id']);
            $response->status(200)->json([
                'success' => true,
                'subscription' => $sub,
                'message' => 'Subscription cancelled successfully.',
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            $response->status($status)->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /api/v1/partner/subscription-requests
     */
    public function listPartnerRequests(Request $request, Response $response): void
    {
        $user = $request->getUser();
        if (!$user) {
            $response->status(401)->json(['success' => false, 'error' => 'Authentication required']);
            return;
        }

        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('ADMIN', $roles, true);
        $isPartner = in_array('PARTNER', $roles, true);

        if (!$isAdmin && !$isPartner) {
            $response->status(403)->json(['success' => false, 'error' => 'Access denied: Partner or Admin role required', 'code' => 'FORBIDDEN']);
            return;
        }

        $partnerId = null;
        if (!$isAdmin) {
            $partnerId = $this->subscriptionService->getPartnerIdByUserId($user['id']);
            if (!$partnerId) {
                $response->status(404)->json(['success' => false, 'error' => 'Partner profile not found for user']);
                return;
            }
        }

        $query = $request->getQueryParams();
        $status = $query['status'] ?? null;

        $requests = $this->subscriptionService->listRequests($partnerId, $status);
        $response->status(200)->json([
            'success' => true,
            'requests' => $requests,
        ]);
    }

    /**
     * POST /api/v1/partner/subscription-requests/{id}/approve
     */
    public function approveRequest(Request $request, Response $response, array $args): void
    {
        $user = $request->getUser();
        if (!$user) {
            $response->status(401)->json(['success' => false, 'error' => 'Authentication required']);
            return;
        }

        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('ADMIN', $roles, true);
        $isPartner = in_array('PARTNER', $roles, true);

        if (!$isAdmin && !$isPartner) {
            $response->status(403)->json(['success' => false, 'error' => 'Access denied', 'code' => 'FORBIDDEN']);
            return;
        }

        $partnerId = $isPartner ? $this->subscriptionService->getPartnerIdByUserId($user['id']) : null;
        $requestId = $args['id'] ?? '';

        try {
            $result = $this->subscriptionService->approveRequest($requestId, $user['id'], $partnerId, $isAdmin);
            $response->status(200)->json([
                'success' => true,
                'message' => 'Subscription approved and activated.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            $response->status($status)->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/v1/partner/subscription-requests/{id}/reject
     */
    public function rejectRequest(Request $request, Response $response, array $args): void
    {
        $user = $request->getUser();
        if (!$user) {
            $response->status(401)->json(['success' => false, 'error' => 'Authentication required']);
            return;
        }

        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('ADMIN', $roles, true);
        $isPartner = in_array('PARTNER', $roles, true);

        if (!$isAdmin && !$isPartner) {
            $response->status(403)->json(['success' => false, 'error' => 'Access denied', 'code' => 'FORBIDDEN']);
            return;
        }

        $partnerId = $isPartner ? $this->subscriptionService->getPartnerIdByUserId($user['id']) : null;
        $requestId = $args['id'] ?? '';
        $body = $request->getBody();
        $reason = $body['reason'] ?? null;

        try {
            $req = $this->subscriptionService->rejectRequest($requestId, $user['id'], $partnerId, $isAdmin, $reason);
            $response->status(200)->json([
                'success' => true,
                'message' => 'Subscription request rejected.',
                'request' => $req,
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            $response->status($status)->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/v1/partner/subscriptions/{id}/renew
     */
    public function renewSubscription(Request $request, Response $response, array $args): void
    {
        $user = $request->getUser();
        if (!$user) {
            $response->status(401)->json(['success' => false, 'error' => 'Authentication required']);
            return;
        }

        $roles = $user['roles'] ?? [];
        $isAdmin = in_array('ADMIN', $roles, true);
        $isPartner = in_array('PARTNER', $roles, true);

        if (!$isAdmin && !$isPartner) {
            $response->status(403)->json(['success' => false, 'error' => 'Access denied', 'code' => 'FORBIDDEN']);
            return;
        }

        $partnerId = $isPartner ? $this->subscriptionService->getPartnerIdByUserId($user['id']) : null;
        $subscriptionId = $args['id'] ?? '';

        try {
            $sub = $this->subscriptionService->renewSubscription($subscriptionId, $user['id'], $partnerId, $isAdmin);
            $response->status(200)->json([
                'success' => true,
                'message' => 'Subscription renewed successfully.',
                'subscription' => $sub,
            ]);
        } catch (Exception $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 499 ? $e->getCode() : 400;
            $response->status($status)->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
