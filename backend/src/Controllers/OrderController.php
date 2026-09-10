<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\OrderService;
use Exception;
use InvalidArgumentException;

class OrderController
{
    private OrderService $orderService;

    public function __construct(?OrderService $orderService = null)
    {
        $this->orderService = $orderService ?? new OrderService();
    }

    private function requireUser(Request $request): ?array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return null;
        }
        return $user;
    }

    private function requirePartnerOrAdmin(Request $request): ?array
    {
        $user = $this->requireUser($request);
        if (!$user) return null;

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Partner or Admin role required', 403);
            return null;
        }
        return $user;
    }

    /**
     * POST /api/v1/orders/checkout (and /api/checkout)
     */
    public function checkout(Request $request): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $data = $request->getBody();
        if (empty($data) || !is_array($data)) {
            Response::error('VALIDATION_ERROR', 'Checkout payload is required.', 422);
            return;
        }

        $idempotencyKey = $request->getHeader('idempotency-key') ?? $request->getHeader('x-idempotency-key');

        try {
            $order = $this->orderService->checkout($user['id'], $data, $idempotencyKey);
            $statusCode = !empty($order['idempotent_replay']) ? 200 : 201;
            Response::success($order, $statusCode);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 402) {
                Response::error('INSUFFICIENT_FUNDS', $e->getMessage(), 402);
            } elseif ($code === 404) {
                Response::error('NOT_FOUND', $e->getMessage(), 404);
            } elseif ($code === 422) {
                Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
            } else {
                Response::error('CHECKOUT_ERROR', $e->getMessage(), is_int($code) && $code >= 400 && $code < 600 ? $code : 500);
            }
        }
    }

    /**
     * GET /api/v1/orders (Customer orders)
     */
    public function customerOrders(Request $request): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $limit = min(100, max(1, (int)$request->getQuery('limit', 20)));
        $offset = max(0, (int)$request->getQuery('offset', 0));
        $status = $request->getQuery('status');

        try {
            $result = $this->orderService->listCustomerOrders($user['id'], $limit, $offset, $status ? (string)$status : null);
            Response::success($result);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/orders/{id} (Customer order detail)
     */
    public function customerOrderDetail(Request $request, array $params = []): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $orderId = $params['id'] ?? '';

        try {
            $order = $this->orderService->getOrder($orderId, $user['id'], $user['roles'] ?? []);
            Response::success($order);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                Response::error('NOT_FOUND', $e->getMessage(), 404);
            } elseif ($code === 403) {
                Response::error('FORBIDDEN', $e->getMessage(), 403);
            } else {
                Response::error('SERVER_ERROR', $e->getMessage(), 500);
            }
        }
    }

    /**
     * GET /api/v1/orders/{id}/invoice
     */
    public function downloadInvoice(Request $request, array $params = []): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $orderId = $params['id'] ?? '';

        try {
            $invoice = $this->orderService->getInvoice($orderId, $user['id'], $user['roles'] ?? []);
            Response::success($invoice);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                Response::error('NOT_FOUND', $e->getMessage(), 404);
            } elseif ($code === 403) {
                Response::error('FORBIDDEN', $e->getMessage(), 403);
            } else {
                Response::error('SERVER_ERROR', $e->getMessage(), 500);
            }
        }
    }

    /**
     * GET /api/v1/partner/orders (Partner orders across their stores)
     */
    public function partnerOrders(Request $request): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $storeId = $request->getQuery('store_id');
        $status = $request->getQuery('status');
        $limit = min(100, max(1, (int)$request->getQuery('limit', 20)));
        $offset = max(0, (int)$request->getQuery('offset', 0));

        try {
            $result = $this->orderService->listPartnerOrders(
                $user['id'],
                $user['roles'] ?? [],
                $storeId ? (string)$storeId : null,
                $status ? (string)$status : null,
                $limit,
                $offset
            );
            Response::success($result);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/partner/orders/{id}
     */
    public function partnerOrderDetail(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $orderId = $params['id'] ?? '';

        try {
            $order = $this->orderService->getOrder($orderId, $user['id'], $user['roles'] ?? []);
            Response::success($order);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                Response::error('NOT_FOUND', $e->getMessage(), 404);
            } elseif ($code === 403) {
                Response::error('FORBIDDEN', $e->getMessage(), 403);
            } else {
                Response::error('SERVER_ERROR', $e->getMessage(), 500);
            }
        }
    }

    /**
     * PATCH /api/v1/partner/orders/{id}/fulfill
     */
    public function partnerFulfill(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $orderId = $params['id'] ?? '';
        $notes = $request->getBody('notes');

        try {
            $updated = $this->orderService->updateOrderStatus(
                $orderId,
                $user['id'],
                'COMPLETED',
                $user['roles'] ?? [],
                $notes ? (string)$notes : 'Order fulfilled by merchant'
            );
            Response::success($updated);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                Response::error('NOT_FOUND', $e->getMessage(), 404);
            } elseif ($code === 403) {
                Response::error('FORBIDDEN', $e->getMessage(), 403);
            } else {
                Response::error('SERVER_ERROR', $e->getMessage(), 500);
            }
        }
    }

    /**
     * PATCH /api/v1/partner/orders/{id}/status
     */
    public function partnerUpdateStatus(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $orderId = $params['id'] ?? '';
        $status = $request->getBody('status');
        $notes = $request->getBody('notes');

        if (!$status || !is_string($status)) {
            Response::error('VALIDATION_ERROR', 'status string is required.', 422);
            return;
        }

        try {
            $updated = $this->orderService->updateOrderStatus(
                $orderId,
                $user['id'],
                $status,
                $user['roles'] ?? [],
                $notes ? (string)$notes : null
            );
            Response::success($updated);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code === 404) {
                Response::error('NOT_FOUND', $e->getMessage(), 404);
            } elseif ($code === 403) {
                Response::error('FORBIDDEN', $e->getMessage(), 403);
            } else {
                Response::error('SERVER_ERROR', $e->getMessage(), 500);
            }
        }
    }
}
