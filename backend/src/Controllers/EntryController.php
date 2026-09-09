<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

class EntryController
{
    public function verify(Request $request): void
    {
        $submittedCode = trim((string) $request->getBody('code', ''));
        $expectedCode = (string) Config::get('ENTRY_CODE', 'anticipation2026');

        if ($submittedCode === '') {
            Response::error('INVALID_INPUT', 'Access code cannot be empty.', 400);
        }

        // Timing attack safe string comparison
        if (!hash_equals($expectedCode, $submittedCode)) {
            // Obscure failed attempts
            Response::error('INVALID_CODE', 'Invalid platform access code.', 403);
        }

        // Issue short-lived entry cookie
        $cookieToken = bin2hex(random_bytes(24));
        $cookieHeader = [
            'Set-Cookie' => "platform_entry={$cookieToken}; Path=/; Max-Age=3600; SameSite=Lax; HttpOnly"
        ];

        Response::success([
            'message' => 'Platform access authorized.',
            'entry_unlocked' => true,
        ], 200, $cookieHeader);
    }
}
