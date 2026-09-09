<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\AuthService;

Config::load();

$username = $argv[1] ?? 'admin';
$email = $argv[2] ?? 'admin@platform.local';
$password = $argv[3] ?? 'AdminPass123!';

echo "====================================================\n";
echo "Platform CLI: First Administrator Account Creation\n";
echo "====================================================\n";

$authService = new AuthService();

try {
    $user = $authService->createUser(
        $username,
        $email,
        $password,
        ['ADMIN', 'CUSTOMER']
    );

    echo "✔ Success: Administrator account created successfully!\n";
    echo "  User ID:  {$user['id']}\n";
    echo "  Username: {$user['username']}\n";
    echo "  Email:    {$user['email']}\n";
    echo "  Roles:    " . implode(', ', $user['roles']) . "\n";
    echo "====================================================\n";
    exit(0);
} catch (Exception $e) {
    if (str_contains($e->getMessage(), 'already exists')) {
        echo "ℹ Notice: Administrator account '{$username}' or email '{$email}' already exists.\n";
        exit(0);
    }
    echo "✖ Error creating admin: " . $e->getMessage() . "\n";
    exit(1);
}
