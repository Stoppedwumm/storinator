<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AuthController;
use App\Controllers\EntryController;
use App\Controllers\FileController;
use App\Controllers\HealthController;
use App\Controllers\RoleTestController;
use App\Controllers\SubscriptionController;
use App\Controllers\WalletController;
use App\Controllers\PartnerBillingController;
use App\Controllers\ShareController;
use App\Controllers\MovieController;
use App\Controllers\StoreController;
use App\Controllers\CartController;
use App\Controllers\OrderController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\RoleMiddleware;

// Uncaught exception handler ensuring standardized JSON error format (Spec Section 59)
set_exception_handler(function (Throwable $e) {
    error_log('[Backend Unhandled Exception] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    $isDev = Config::get('APP_ENV', 'development') === 'development';
    Response::error(
        'INTERNAL_SERVER_ERROR',
        $isDev ? $e->getMessage() : 'An unexpected internal server error occurred.',
        500
    );
});

// Load environment settings
Config::load();

$router = new Router();
$request = new Request();

// Global middleware
$router->use([CorsMiddleware::class, 'handle']);

// Health check endpoints (Spec Section 60)
$router->get('/health', [HealthController::class, 'check']);
$router->get('/api/health', [HealthController::class, 'check']);
$router->get('/api/v1/health', [HealthController::class, 'check']);

// Secret teaser entry-code endpoint (Spec Section 32 & 41)
$router->post('/api/v1/entry/verify', [EntryController::class, 'verify']);
$router->post('/api/entry/verify', [EntryController::class, 'verify']);
$router->post('/api/entry-code', [EntryController::class, 'verify']);
$router->get('/api/v1/entry/status', [EntryController::class, 'status']);
$router->get('/api/entry/status', [EntryController::class, 'status']);

// Authentication Endpoints (Spec Section 30 & 40)
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/register', [AuthController::class, 'register']);
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout']);

// Authenticated User Endpoints
$router->get('/api/v1/auth/me', [AuthController::class, 'me'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/users/me', [AuthController::class, 'me'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/auth/password/reset', [AuthController::class, 'resetPassword'], [[AuthMiddleware::class, 'handle']]);

// Role-Gated Authorization Verification Endpoints (Spec Phase 2 Acceptance)
$router->get('/api/v1/customer/ping', [RoleTestController::class, 'customerPing'], [
    [AuthMiddleware::class, 'handle'],
    RoleMiddleware::requireCustomer(),
]);

$router->get('/api/v1/partner/ping', [RoleTestController::class, 'partnerPing'], [
    [AuthMiddleware::class, 'handle'],
    RoleMiddleware::requirePartner(),
]);

$router->get('/api/v1/admin/ping', [RoleTestController::class, 'adminPing'], [
    [AuthMiddleware::class, 'handle'],
    RoleMiddleware::requireAdmin(),
]);

$router->get('/api/v1/admin/users', [RoleTestController::class, 'listUsers'], [
    [AuthMiddleware::class, 'handle'],
    RoleMiddleware::requireAdmin(),
]);

// File & Storage Endpoints (Spec Phase 4 & Section 15, 39, 42)
$router->get('/api/v1/files', [FileController::class, 'list'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/files/upload/init', [FileController::class, 'initUpload'], [[AuthMiddleware::class, 'handle']]);
$router->put('/api/v1/files/upload/{id}/chunk/{index}', [FileController::class, 'uploadChunk'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/files/upload/{id}/chunk/{index}', [FileController::class, 'uploadChunk'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/files/upload/{id}/finalize', [FileController::class, 'finalizeUpload'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/files/upload', [FileController::class, 'directUpload'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/files/{id}', [FileController::class, 'show'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/files/{id}/download', [FileController::class, 'download'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/files/{id}/stream', [FileController::class, 'stream'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/files/{id}', [FileController::class, 'delete'], [[AuthMiddleware::class, 'handle']]);

$router->post('/api/v1/directories', [FileController::class, 'createDirectory'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/directories/{id}', [FileController::class, 'deleteDirectory'], [[AuthMiddleware::class, 'handle']]);

$router->get('/api/v1/storage/quota', [FileController::class, 'quota'], [[AuthMiddleware::class, 'handle']]);

// Subscription Endpoints (Spec Phase 5 & Section 11, 41)
$router->post('/api/v1/subscriptions/request', [SubscriptionController::class, 'request'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/subscriptions/request', [SubscriptionController::class, 'request'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/subscriptions/current', [SubscriptionController::class, 'current'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/subscriptions/current', [SubscriptionController::class, 'current'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/subscriptions/cancel', [SubscriptionController::class, 'cancel'], [[AuthMiddleware::class, 'handle']]);

$router->get('/api/v1/partner/subscription-requests', [SubscriptionController::class, 'listPartnerRequests'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/subscription-requests', [SubscriptionController::class, 'listPartnerRequests'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/subscription-requests/{id}/approve', [SubscriptionController::class, 'approveRequest'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/subscription-requests/{id}/approve', [SubscriptionController::class, 'approveRequest'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/subscription-requests/{id}/reject', [SubscriptionController::class, 'rejectRequest'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/subscription-requests/{id}/reject', [SubscriptionController::class, 'rejectRequest'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/subscriptions/{id}/renew', [SubscriptionController::class, 'renewSubscription'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/subscriptions/{id}/renew', [SubscriptionController::class, 'renewSubscription'], [[AuthMiddleware::class, 'handle']]);

// Wallet & Ledger Endpoints (Spec Phase 6 & Section 28, 43)
$router->get('/api/v1/wallet', [WalletController::class, 'getWallet'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/wallet', [WalletController::class, 'getWallet'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/wallet/transactions', [WalletController::class, 'getTransactions'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/wallet/transactions', [WalletController::class, 'getTransactions'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/wallet/topup', [WalletController::class, 'topup'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/wallet/topup', [WalletController::class, 'topup'], [[AuthMiddleware::class, 'handle']]);

$router->post('/api/v1/partner/wallet/credit', [WalletController::class, 'partnerCredit'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/wallet/credit', [WalletController::class, 'partnerCredit'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/partner/wallet/customers', [WalletController::class, 'listCustomers'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/wallet/customers', [WalletController::class, 'listCustomers'], [[AuthMiddleware::class, 'handle']]);

// Partner Billing & Settlement Endpoints (Spec Phase 7 & Section 9, 10, 20-22)
$router->get('/api/v1/partner/billing', [PartnerBillingController::class, 'getSummary'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/billing', [PartnerBillingController::class, 'getSummary'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/partner/billing/entries', [PartnerBillingController::class, 'getEntries'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/billing/entries', [PartnerBillingController::class, 'getEntries'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/partner/billing/payments', [PartnerBillingController::class, 'getPayments'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/billing/payments', [PartnerBillingController::class, 'getPayments'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/partner/billing/statements', [PartnerBillingController::class, 'getStatements'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/billing/statements', [PartnerBillingController::class, 'getStatements'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/billing/statements/generate', [PartnerBillingController::class, 'generateStatement'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/billing/statements/generate', [PartnerBillingController::class, 'generateStatement'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/settings/invoice-retention', [PartnerBillingController::class, 'updateInvoiceRetention'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/settings/invoice-retention', [PartnerBillingController::class, 'updateInvoiceRetention'], [[AuthMiddleware::class, 'handle']]);

$router->get('/api/v1/admin/billing/partners', [PartnerBillingController::class, 'adminListPartners'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/admin/billing/partners', [PartnerBillingController::class, 'adminListPartners'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/admin/billing/settle', [PartnerBillingController::class, 'adminSettleDebt'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/admin/billing/settle', [PartnerBillingController::class, 'adminSettleDebt'], [[AuthMiddleware::class, 'handle']]);

// File Sharing Endpoints (Spec Phase 8 & Section 14)
$router->post('/api/v1/shares', [ShareController::class, 'create'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/shares', [ShareController::class, 'create'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/shares', [ShareController::class, 'list'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/shares', [ShareController::class, 'list'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/shares/{id}', [ShareController::class, 'show'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/shares/{id}', [ShareController::class, 'show'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/v1/shares/{id}', [ShareController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/shares/{id}', [ShareController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/shares/{id}', [ShareController::class, 'revoke'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/shares/{id}', [ShareController::class, 'revoke'], [[AuthMiddleware::class, 'handle']]);

// Public Share Access Endpoints
$router->get('/api/v1/s/{token}', [ShareController::class, 'getPublic']);
$router->get('/api/s/{token}', [ShareController::class, 'getPublic']);
$router->post('/api/v1/s/{token}/unlock', [ShareController::class, 'unlock']);
$router->post('/api/s/{token}/unlock', [ShareController::class, 'unlock']);
$router->get('/api/v1/s/{token}/download', [ShareController::class, 'download']);
$router->get('/api/s/{token}/download', [ShareController::class, 'download']);
$router->get('/s/{token}/download', [ShareController::class, 'download']);
$router->get('/api/v1/s/{token}/stream', [ShareController::class, 'stream']);
$router->get('/api/s/{token}/stream', [ShareController::class, 'stream']);
$router->get('/s/{token}/stream', [ShareController::class, 'stream']);

// Movie Mode & Streaming Endpoints (Spec Phase 9 & Section 16-19)
$router->get('/api/v1/movies', [MovieController::class, 'index'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/movies', [MovieController::class, 'index'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/movies/scan', [MovieController::class, 'scan'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/movies/scan', [MovieController::class, 'scan'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/movies/folders', [MovieController::class, 'addFolder'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/movies/folders', [MovieController::class, 'addFolder'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/movies/folders', [MovieController::class, 'listFolders'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/movies/folders', [MovieController::class, 'listFolders'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/movies/{id}', [MovieController::class, 'show'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/movies/{id}', [MovieController::class, 'show'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/v1/movies/{id}', [MovieController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/movies/{id}', [MovieController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/movies/{id}/metadata', [MovieController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/movies/{id}/metadata', [MovieController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/movies/{id}', [MovieController::class, 'destroy'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/movies/{id}', [MovieController::class, 'destroy'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/movies/{id}/stream-token', [MovieController::class, 'createStreamToken'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/movies/{id}/stream-token', [MovieController::class, 'createStreamToken'], [[AuthMiddleware::class, 'handle']]);

// Short-lived Media Stream Endpoints (Spec Section 19)
$router->get('/api/v1/media/stream/{token}', [MovieController::class, 'streamMedia']);
$router->get('/api/media/stream/{token}', [MovieController::class, 'streamMedia']);
$router->get('/media/stream/{token}', [MovieController::class, 'streamMedia']);

// Public Storefront Endpoints (Spec Section 22 - NO subscription required)
$router->get('/api/v1/stores', [StoreController::class, 'index']);
$router->get('/api/stores', [StoreController::class, 'index']);
$router->get('/api/v1/stores/{slug}/products/{productSlug}', [StoreController::class, 'productDetail']);
$router->get('/api/stores/{slug}/products/{productSlug}', [StoreController::class, 'productDetail']);
$router->get('/api/v1/stores/{slug}/products', [StoreController::class, 'products']);
$router->get('/api/stores/{slug}/products', [StoreController::class, 'products']);
$router->get('/api/v1/stores/{slug}', [StoreController::class, 'show']);
$router->get('/api/stores/{slug}', [StoreController::class, 'show']);

// Partner Store Management Endpoints (Spec Sections 22-25)
$router->get('/api/v1/partner/stores/{id}/categories', [StoreController::class, 'listCategories'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/stores/{id}/categories', [StoreController::class, 'listCategories'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/stores/{id}/categories', [StoreController::class, 'createCategory'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/stores/{id}/categories', [StoreController::class, 'createCategory'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/partner/stores/{id}/categories/{categoryId}', [StoreController::class, 'deleteCategory'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/partner/stores/{id}/categories/{categoryId}', [StoreController::class, 'deleteCategory'], [[AuthMiddleware::class, 'handle']]);

$router->get('/api/v1/partner/stores/{id}/products/{productId}', [StoreController::class, 'partnerShowProduct'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/stores/{id}/products/{productId}', [StoreController::class, 'partnerShowProduct'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/v1/partner/stores/{id}/products/{productId}', [StoreController::class, 'updateProduct'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/partner/stores/{id}/products/{productId}', [StoreController::class, 'updateProduct'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/stores/{id}/products/{productId}', [StoreController::class, 'updateProduct'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/stores/{id}/products/{productId}', [StoreController::class, 'updateProduct'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/partner/stores/{id}/products/{productId}', [StoreController::class, 'deleteProduct'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/partner/stores/{id}/products/{productId}', [StoreController::class, 'deleteProduct'], [[AuthMiddleware::class, 'handle']]);

$router->get('/api/v1/partner/stores/{id}/products', [StoreController::class, 'partnerListProducts'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/stores/{id}/products', [StoreController::class, 'partnerListProducts'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/stores/{id}/products', [StoreController::class, 'createProduct'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/stores/{id}/products', [StoreController::class, 'createProduct'], [[AuthMiddleware::class, 'handle']]);

$router->get('/api/v1/partner/stores/{id}', [StoreController::class, 'partnerShow'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/stores/{id}', [StoreController::class, 'partnerShow'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/v1/partner/stores/{id}', [StoreController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/partner/stores/{id}', [StoreController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/stores/{id}', [StoreController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/stores/{id}', [StoreController::class, 'update'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/partner/stores/{id}', [StoreController::class, 'destroy'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/partner/stores/{id}', [StoreController::class, 'destroy'], [[AuthMiddleware::class, 'handle']]);

$router->get('/api/v1/partner/stores', [StoreController::class, 'partnerList'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/stores', [StoreController::class, 'partnerList'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/stores', [StoreController::class, 'create'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/stores', [StoreController::class, 'create'], [[AuthMiddleware::class, 'handle']]);

// Shopping Cart Endpoints (Spec Section 26)
$router->get('/api/v1/cart', [CartController::class, 'getCart'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/cart', [CartController::class, 'getCart'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/cart/items', [CartController::class, 'addItem'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/cart/items', [CartController::class, 'addItem'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/v1/cart/items/{id}', [CartController::class, 'updateItem'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/cart/items/{id}', [CartController::class, 'updateItem'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/cart/items/{id}', [CartController::class, 'updateItem'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/cart/items/{id}', [CartController::class, 'updateItem'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/cart/items/{id}', [CartController::class, 'removeItem'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/cart/items/{id}', [CartController::class, 'removeItem'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/v1/cart', [CartController::class, 'clearCart'], [[AuthMiddleware::class, 'handle']]);
$router->delete('/api/cart', [CartController::class, 'clearCart'], [[AuthMiddleware::class, 'handle']]);

// Customer Checkout & Order Endpoints (Spec Sections 27-28)
$router->post('/api/v1/orders/checkout', [OrderController::class, 'checkout'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/checkout', [OrderController::class, 'checkout'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/checkout', [OrderController::class, 'checkout'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/orders', [OrderController::class, 'customerOrders'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/orders', [OrderController::class, 'customerOrders'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/orders/{id}', [OrderController::class, 'customerOrderDetail'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/orders/{id}', [OrderController::class, 'customerOrderDetail'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/orders/{id}/invoice', [OrderController::class, 'downloadInvoice'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/orders/{id}/invoice', [OrderController::class, 'downloadInvoice'], [[AuthMiddleware::class, 'handle']]);

// Partner Store Order Endpoints (Spec Section 28)
$router->get('/api/v1/partner/orders', [OrderController::class, 'partnerOrders'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/orders', [OrderController::class, 'partnerOrders'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/v1/partner/orders/{id}', [OrderController::class, 'partnerOrderDetail'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/partner/orders/{id}', [OrderController::class, 'partnerOrderDetail'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/v1/partner/orders/{id}/status', [OrderController::class, 'partnerUpdateStatus'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/partner/orders/{id}/status', [OrderController::class, 'partnerUpdateStatus'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/orders/{id}/status', [OrderController::class, 'partnerUpdateStatus'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/orders/{id}/status', [OrderController::class, 'partnerUpdateStatus'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/v1/partner/orders/{id}/fulfill', [OrderController::class, 'partnerFulfill'], [[AuthMiddleware::class, 'handle']]);
$router->patch('/api/partner/orders/{id}/fulfill', [OrderController::class, 'partnerFulfill'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/v1/partner/orders/{id}/fulfill', [OrderController::class, 'partnerFulfill'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/partner/orders/{id}/fulfill', [OrderController::class, 'partnerFulfill'], [[AuthMiddleware::class, 'handle']]);

// Dispatch incoming request
$router->dispatch($request);
