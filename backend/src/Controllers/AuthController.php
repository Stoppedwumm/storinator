<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use Exception;

class AuthController
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    public function login(Request $request): void
    {
        $identifier = (string) ($request->getBody('identifier') ?? $request->getBody('username') ?? $request->getBody('email') ?? '');
        $password = (string) ($request->getBody('password') ?? '');

        if (empty($identifier) || empty($password)) {
            Response::error(
                'VALIDATION_ERROR',
                'Both username/email and password are required.',
                422
            );
        }

        try {
            $result = $this->authService->login(
                $identifier,
                $password,
                $request->getClientIp(),
                $request->getUserAgent()
            );

            // Set session cookie (7 days, HttpOnly)
            Response::setCookie(
                'platform_session',
                $result['token'],
                time() + (7 * 86400),
                '/',
                '',
                false, // set to true if enforcing strict HTTPS
                true,  // HttpOnly
                'Lax'
            );

            Response::success([
                'token' => $result['token'],
                'expires_at' => $result['expires_at'],
                'user' => $result['user'],
            ]);
        } catch (Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 401;
            $errCode = match ($status) {
                429 => 'RATE_LIMITED',
                403 => 'ACCOUNT_SUSPENDED',
                default => 'INVALID_CREDENTIALS',
            };
            Response::error($errCode, $e->getMessage(), $status);
        }
    }

    public function register(Request $request): void
    {
        $username = (string) ($request->getBody('username') ?? '');
        $email = (string) ($request->getBody('email') ?? '');
        $password = (string) ($request->getBody('password') ?? '');

        if (empty($username) || empty($email) || empty($password)) {
            Response::error(
                'VALIDATION_ERROR',
                'Username, email, and password are required.',
                422
            );
        }

        try {
            $user = $this->authService->registerCustomer($username, $email, $password);

            // Immediately create session for the registered customer
            $loginResult = $this->authService->login(
                $username,
                $password,
                $request->getClientIp(),
                $request->getUserAgent()
            );

            Response::setCookie(
                'platform_session',
                $loginResult['token'],
                time() + (7 * 86400),
                '/',
                '',
                false,
                true,
                'Lax'
            );

            Response::success([
                'token' => $loginResult['token'],
                'expires_at' => $loginResult['expires_at'],
                'user' => $loginResult['user'],
            ], 201);
        } catch (Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            $errCode = match ($status) {
                409 => 'CONFLICT',
                422 => 'VALIDATION_ERROR',
                default => 'REGISTRATION_FAILED',
            };
            Response::error($errCode, $e->getMessage(), $status);
        }
    }

    public function logout(Request $request): void
    {
        $token = $request->getAuthToken();
        if ($token) {
            $this->authService->logout($token);
        }

        Response::clearCookie('platform_session');

        Response::success([
            'message' => 'Successfully signed out.',
        ]);
    }

    public function me(Request $request): void
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('UNAUTHORIZED', 'Not authenticated.', 401);
        }

        Response::success([
            'user' => $user,
        ]);
    }

    public function resetPassword(Request $request): void
    {
        $oldPassword = (string) ($request->getBody('current_password') ?? '');
        $newPassword = (string) ($request->getBody('new_password') ?? '');
        $user = $request->getUser();

        if (!$user) {
            Response::error('UNAUTHORIZED', 'Not authenticated.', 401);
        }

        if (empty($oldPassword) || empty($newPassword)) {
            Response::error('VALIDATION_ERROR', 'Current password and new password are required.', 422);
        }

        try {
            $this->authService->changePassword($user['id'], $oldPassword, $newPassword);
            Response::success([
                'message' => 'Password updated successfully.',
            ]);
        } catch (Exception $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code < 600) ? $code : 400;
            Response::error('PASSWORD_UPDATE_FAILED', $e->getMessage(), $status);
        }
    }
}
