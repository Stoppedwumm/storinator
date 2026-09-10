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
echo "Platform CLI: Seeding Subscriptions\n";
echo "====================================================\n";

$db = \App\Core\Database::getConnection();
$partnerRow = $db->query("SELECT p.id as partner_id FROM partners p JOIN users u ON p.user_id = u.id WHERE u.username = 'partner'")->fetch(PDO::FETCH_ASSOC);
$customerRow = $db->query("SELECT id as user_id FROM users WHERE username = 'customer'")->fetch(PDO::FETCH_ASSOC);

if ($partnerRow && $customerRow) {
    $subStmt = $db->prepare("SELECT id FROM subscriptions WHERE user_id = :uid");
    $subStmt->execute([':uid' => $customerRow['user_id']]);
    if (!$subStmt->fetch()) {
        $subId = 'sub_' . bin2hex(random_bytes(12));
        $startsAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $stmt = $db->prepare('
            INSERT INTO subscriptions (id, user_id, partner_id, status, quota_bytes, starts_at, expires_at, created_at, updated_at)
            VALUES (:id, :uid, :pid, "ACTIVE", 53687091200, :starts, :expires, datetime("now"), datetime("now"))
        ');
        $stmt->execute([
            ':id' => $subId,
            ':uid' => $customerRow['user_id'],
            ':pid' => $partnerRow['partner_id'],
            ':starts' => $startsAt,
            ':expires' => $expiresAt,
        ]);
        echo "  ✔ Seeded initial 50 GiB active subscription for customer\n";
    } else {
        echo "  ℹ Customer subscription already present.\n";
    }
}

echo "====================================================\n";
echo "User & Subscription Seeding Completed.\n";
echo "====================================================\n";
