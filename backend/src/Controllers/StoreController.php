<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\StoreService;
use Exception;
use InvalidArgumentException;

class StoreController
{
    private StoreService $storeService;

    public function __construct(?StoreService $storeService = null)
    {
        $this->storeService = $storeService ?? new StoreService();
    }

    private function requirePartnerOrAdmin(Request $request): ?array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHENTICATED', 'Authentication required', 401);
            return null;
        }

        $roles = $user['roles'] ?? [];
        if (!in_array('PARTNER', $roles, true) && !in_array('ADMIN', $roles, true)) {
            Response::error('FORBIDDEN', 'Partner or Admin role required', 403);
            return null;
        }

        return $user;
    }

    // =========================================================================
    // Public Storefront Endpoints (NO subscription required, Spec Section 22)
    // =========================================================================

    /**
     * GET /api/v1/stores
     */
    public function index(Request $request): void
    {
        $search = $request->getQuery('search');
        $limit = min(100, max(1, (int)$request->getQuery('limit', 50)));
        $offset = max(0, (int)$request->getQuery('offset', 0));

        try {
            $result = $this->storeService->listStores($search, 'ACTIVE', $limit, $offset);
            Response::success($result);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/stores/{slug}
     */
    public function show(Request $request, array $params = []): void
    {
        $slug = $params['slug'] ?? '';
        if (empty($slug)) {
            Response::error('NOT_FOUND', 'Store slug required', 404);
            return;
        }

        try {
            $store = $this->storeService->getStoreBySlug($slug, true);
            if (!$store) {
                Response::error('NOT_FOUND', 'Store not found or inactive', 404);
                return;
            }

            Response::success(['store' => $store]);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/stores/{slug}/products
     */
    public function products(Request $request, array $params = []): void
    {
        $slug = $params['slug'] ?? '';
        $store = $this->storeService->getStoreBySlug($slug, true);
        if (!$store) {
            Response::error('NOT_FOUND', 'Store not found or inactive', 404);
            return;
        }

        $categoryId = $request->getQuery('category_id');
        $search = $request->getQuery('search');
        $sort = $request->getQuery('sort', 'created_at');
        $order = $request->getQuery('order', 'DESC');
        $limit = min(100, max(1, (int)$request->getQuery('limit', 50)));
        $offset = max(0, (int)$request->getQuery('offset', 0));

        try {
            $result = $this->storeService->listProducts(
                $store['id'],
                $categoryId,
                $search,
                'ACTIVE',
                $sort,
                $order,
                $limit,
                $offset
            );
            $result['store'] = [
                'id' => $store['id'],
                'name' => $store['name'],
                'slug' => $store['slug'],
                'theme_color' => $store['theme_color'],
            ];
            Response::success($result);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/stores/{slug}/products/{productSlug}
     */
    public function productDetail(Request $request, array $params = []): void
    {
        $slug = $params['slug'] ?? '';
        $productSlug = $params['productSlug'] ?? '';

        try {
            $product = $this->storeService->getProductBySlug($slug, $productSlug);
            if (!$product || $product['status'] !== 'ACTIVE') {
                Response::error('NOT_FOUND', 'Product not found', 404);
                return;
            }

            Response::success(['product' => $product]);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Partner Store Management Endpoints
    // =========================================================================

    /**
     * GET /api/v1/partner/stores
     */
    public function partnerList(Request $request): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);
        $limit = min(100, max(1, (int)$request->getQuery('limit', 50)));
        $offset = max(0, (int)$request->getQuery('offset', 0));

        try {
            $result = $this->storeService->listPartnerStores($user['id'], $isAdmin, $limit, $offset);
            Response::success($result);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/partner/stores
     */
    public function create(Request $request): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $data = $request->getBody();
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $store = $this->storeService->createStore($user['id'], $data, $isAdmin);
            Response::success(['store' => $store], 201);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/partner/stores/{id}
     */
    public function partnerShow(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $store = $this->storeService->verifyStoreAccess($user['id'], $id, $isAdmin);
            Response::success(['store' => $store]);
        } catch (InvalidArgumentException $e) {
            Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/v1/partner/stores/{id}
     */
    public function update(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $data = $request->getBody();
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $store = $this->storeService->updateStore($user['id'], $id, $data, $isAdmin);
            Response::success(['store' => $store]);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/partner/stores/{id}
     */
    public function destroy(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $this->storeService->deleteStore($user['id'], $id, $isAdmin);
            Response::success(['message' => 'Store deleted successfully']);
        } catch (InvalidArgumentException $e) {
            Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Category Endpoints
    // =========================================================================

    /**
     * GET /api/v1/partner/stores/{id}/categories
     */
    public function listCategories(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $this->storeService->verifyStoreAccess($user['id'], $id, $isAdmin);
            $categories = $this->storeService->listCategories($id);
            Response::success(['categories' => $categories]);
        } catch (InvalidArgumentException $e) {
            Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/partner/stores/{id}/categories
     */
    public function createCategory(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $data = $request->getBody();
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $cat = $this->storeService->createCategory($user['id'], $id, $data, $isAdmin);
            Response::success(['category' => $cat], 201);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/partner/stores/{id}/categories/{categoryId}
     */
    public function deleteCategory(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $categoryId = $params['categoryId'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $this->storeService->deleteCategory($user['id'], $id, $categoryId, $isAdmin);
            Response::success(['message' => 'Category deleted successfully']);
        } catch (InvalidArgumentException $e) {
            Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Product Endpoints
    // =========================================================================

    /**
     * GET /api/v1/partner/stores/{id}/products
     */
    public function partnerListProducts(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $this->storeService->verifyStoreAccess($user['id'], $id, $isAdmin);
            $categoryId = $request->getQuery('category_id');
            $search = $request->getQuery('search');
            $status = $request->getQuery('status'); // null means all statuses
            $sort = $request->getQuery('sort', 'created_at');
            $order = $request->getQuery('order', 'DESC');
            $limit = min(100, max(1, (int)$request->getQuery('limit', 50)));
            $offset = max(0, (int)$request->getQuery('offset', 0));

            $result = $this->storeService->listProducts(
                $id,
                $categoryId,
                $search,
                $status,
                $sort,
                $order,
                $limit,
                $offset
            );
            Response::success($result);
        } catch (InvalidArgumentException $e) {
            Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/partner/stores/{id}/products
     */
    public function createProduct(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $data = $request->getBody();
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $product = $this->storeService->createProduct($user['id'], $id, $data, $isAdmin);
            Response::success(['product' => $product], 201);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v1/partner/stores/{id}/products/{productId}
     */
    public function partnerShowProduct(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $productId = $params['productId'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $this->storeService->verifyStoreAccess($user['id'], $id, $isAdmin);
            $product = $this->storeService->getProduct($id, $productId);
            if (!$product) {
                Response::error('NOT_FOUND', 'Product not found', 404);
                return;
            }
            Response::success(['product' => $product]);
        } catch (InvalidArgumentException $e) {
            Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/v1/partner/stores/{id}/products/{productId}
     */
    public function updateProduct(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $productId = $params['productId'] ?? '';
        $data = $request->getBody();
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $product = $this->storeService->updateProduct($user['id'], $id, $productId, $data, $isAdmin);
            Response::success(['product' => $product]);
        } catch (InvalidArgumentException $e) {
            Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/partner/stores/{id}/products/{productId}
     */
    public function deleteProduct(Request $request, array $params = []): void
    {
        $user = $this->requirePartnerOrAdmin($request);
        if (!$user) return;

        $id = $params['id'] ?? '';
        $productId = $params['productId'] ?? '';
        $isAdmin = in_array('ADMIN', $user['roles'] ?? [], true);

        try {
            $this->storeService->deleteProduct($user['id'], $id, $productId, $isAdmin);
            Response::success(['message' => 'Product deleted successfully']);
        } catch (InvalidArgumentException $e) {
            Response::error('NOT_FOUND', $e->getMessage(), 404);
        } catch (Exception $e) {
            Response::error('SERVER_ERROR', $e->getMessage(), 500);
        }
    }
}
