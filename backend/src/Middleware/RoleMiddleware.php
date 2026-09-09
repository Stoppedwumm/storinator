<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

class RoleMiddleware
{
    private array $allowedRoles;

    public function __construct(string ...$allowedRoles)
    {
        $this->allowedRoles = array_map('strtoupper', $allowedRoles);
    }

    public static function forRoles(string ...$roles): callable
    {
        $middleware = new self(...$roles);
        return [$middleware, 'handle'];
    }

    public static function requireAdmin(): callable
    {
        return self::forRoles('ADMIN');
    }

    public static function requirePartner(): callable
    {
        // PARTNER or ADMIN can access partner endpoints
        return self::forRoles('PARTNER', 'ADMIN');
    }

    public static function requireCustomer(): callable
    {
        // CUSTOMER, PARTNER or ADMIN can access general customer endpoints
        return self::forRoles('CUSTOMER', 'PARTNER', 'ADMIN');
    }

    public function handle(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error(
                'UNAUTHORIZED',
                'Authentication required.',
                401
            );
        }

        $userRoles = array_map('strtoupper', $user['roles'] ?? []);

        // Check if user has at least one of the allowed roles
        $hasAccess = false;
        foreach ($this->allowedRoles as $allowedRole) {
            if (in_array($allowedRole, $userRoles, true)) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            Response::error(
                'FORBIDDEN',
                'Access forbidden. You do not possess the required permissions (' . implode(', ', $this->allowedRoles) . ') to access this resource.',
                403
            );
        }
    }
}
