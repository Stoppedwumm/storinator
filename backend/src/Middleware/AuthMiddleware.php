<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

class AuthMiddleware
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    public function handle(Request $request): void
    {
        $token = $request->getAuthToken();
        if (!$token) {
            Response::error(
                'UNAUTHORIZED',
                'Authentication required. Please sign in to access this resource.',
                401
            );
        }

        $user = $this->authService->validateSession($token);
        if (!$user) {
            Response::error(
                'UNAUTHORIZED',
                'Session expired or invalid. Please sign in again.',
                401
            );
        }

        if (($user['status'] ?? '') !== 'ACTIVE') {
            Response::error(
                'ACCOUNT_DISABLED',
                'Your account has been suspended or deactivated. Contact support.',
                403
            );
        }

        $request->setUser($user);
    }
}
