<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\AuthService;

Config::load();

$authService = new AuthService();

echo "====================================================\n";
echo "Platform CLI: Seeding Default System Users & Roles\n";
echo "====================================================\n";

$seedUsers = [
    [
        'username' => 'admin',
        'email' => 'admin@platform.local',
        'password' => 'AdminPass123!',
        'roles' => ['ADMIN', 'CUSTOMER'],
    ],
    [
        'username' => 'partner',
        'email' => 'partner@platform.local',
        'password' => 'PartnerPass123!',
        'roles' => ['PARTNER', 'CUSTOMER'],
    ],
    [
        'username' => 'customer',
        'email' => 'customer@platform.local',
        'password' => 'CustomerPass123!',
        'roles' => ['CUSTOMER'],
    ],
];

foreach ($seedUsers as $u) {
    try {
        $user = $authService->createUser(
            $u['username'],
            $u['email'],
            $u['password'],
            $u['roles']
        );
        echo "  ✔ Seeded {$u['username']} ({$u['email']}) -> [" . implode(', ', $user['roles']) . "]\n";
    } catch (Exception $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            echo "  ℹ {$u['username']} already exists. Skipping.\n";
        } else {
            echo "  ✖ Error seeding {$u['username']}: " . $e->getMessage() . "\n";
        }
    }
}

echo "====================================================\n";
echo "User Seeding Completed.\n";
echo "====================================================\n";
