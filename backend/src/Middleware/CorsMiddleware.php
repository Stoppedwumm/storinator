<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;

class CorsMiddleware
{
    public function handle(Request $request): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Idempotency-Key');

        if ($request->getMethod() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
