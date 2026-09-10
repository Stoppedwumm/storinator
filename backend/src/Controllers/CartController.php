<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CartService;
use Exception;
use InvalidArgumentException;

class CartController
{
    private CartService $cartService;

    public function __construct(?CartService $cartService = null)
    {
        $this->cartService = $cartService ?? new CartService();
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

    /**
     * GET /api/v1/cart
     */
    public function getCart(Request $request): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        try {
            $cart = $this->cartService->getCart($user['id']);
            Response::success($cart);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/cart/items
     */
    public function addItem(Request $request): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $storeId = $request->getBody('store_id');
        $productId = $request->getBody('product_id');
        $quantity = (int)($request->getBody('quantity') ?? 1);
        $variant = $request->getBody('variant');

        if (!$storeId || !is_string($storeId) || !$productId || !is_string($productId)) {
            Response::error('VALIDATION_ERROR', 'store_id and product_id are required strings.', 422);
            return;
        }
        if ($quantity <= 0) {
            Response::error('VALIDATION_ERROR', 'quantity must be at least 1.', 422);
            return;
        }

        try {
            $cart = $this->cartService->addItem($user['id'], $storeId, $productId, $quantity, is_array($variant) ? $variant : null);
            Response::success($cart, 201);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            $code = $e->getCode() ?: 500;
            Response::error('CART_ERROR', $e->getMessage(), is_int($code) && $code >= 400 && $code < 600 ? $code : 500);
        }
    }

    /**
     * PATCH /api/v1/cart/items/{id}
     */
    public function updateItem(Request $request, array $params = []): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $cartItemId = $params['id'] ?? '';
        $quantity = (int)($request->getBody('quantity') ?? 0);

        try {
            $cart = $this->cartService->updateItem($user['id'], $cartItemId, $quantity);
            Response::success($cart);
        } catch (Exception $e) {
            Response::error('CART_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/cart/items/{id}
     */
    public function removeItem(Request $request, array $params = []): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $cartItemId = $params['id'] ?? '';

        try {
            $cart = $this->cartService->removeItem($user['id'], $cartItemId);
            Response::success($cart);
        } catch (Exception $e) {
            Response::error('CART_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/cart
     */
    public function clearCart(Request $request): void
    {
        $user = $this->requireUser($request);
        if (!$user) return;

        $storeId = $request->getQuery('store_id');

        try {
            $cart = $this->cartService->clearCart($user['id'], $storeId ? (string)$storeId : null);
            Response::success($cart);
        } catch (Exception $e) {
            Response::error('CART_ERROR', $e->getMessage(), 500);
        }
    }
}
