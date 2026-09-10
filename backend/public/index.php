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

// Dispatch incoming request
$router->dispatch($request);
