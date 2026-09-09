<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AuthController;
use App\Controllers\EntryController;
use App\Controllers\HealthController;
use App\Controllers\RoleTestController;
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

// Dispatch incoming request
$router->dispatch($request);
