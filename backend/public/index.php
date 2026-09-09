<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\EntryController;
use App\Controllers\HealthController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\CorsMiddleware;

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

// Phase 3 secret entry-code verification endpoint (Spec Section 32 & 41)
$router->post('/api/v1/entry/verify', [EntryController::class, 'verify']);

// Dispatch incoming request
$router->dispatch($request);
