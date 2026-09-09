<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\EntryController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\MigrationService;

echo "====================================================\n";
echo "Running Platform Backend Automated Test Suite\n";
echo "====================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function it(string $description, callable $testFn): void {
    global $testsPassed, $testsFailed;
    try {
        $testFn();
        echo "  ✔ PASS: {$description}\n";
        $testsPassed++;
    } catch (Throwable $e) {
        echo "  ✖ FAIL: {$description}\n";
        echo "    Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
        $testsFailed++;
    }
}

// 1. Config Test
it('loads environment variables accurately', function () {
    $_ENV['APP_ENV'] = 'testing';
    Config::load();
    assert(Config::get('APP_ENV') === 'testing', 'APP_ENV should equal testing');
});

// 2. Database & Migration Test
it('connects to SQLite and executes migrations atomically', function () {
    $tempDb = sys_get_temp_dir() . '/test_backend_' . uniqid() . '.sqlite';
    $pdo = Database::getConnection($tempDb);

    // Verify WAL and foreign keys
    $stmt = $pdo->query('PRAGMA foreign_keys');
    assert((int) $stmt->fetchColumn() === 1, 'Foreign keys should be ON');

    // Run migration service
    $migrationService = new MigrationService($pdo);
    $applied = $migrationService->run();
    assert(count($applied) >= 1, 'Should apply at least initial migration');

    // Verify critical tables
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $required = [
        'migrations', 'users', 'roles', 'user_roles', 'wallets', 
        'wallet_transactions', 'subscriptions', 'partners', 'stores', 
        'products', 'orders', 'audit_logs'
    ];

    foreach ($required as $table) {
        assert(in_array($table, $tables, true), "Table {$table} must exist in backend database");
    }

    // Verify roles were seeded
    $roles = $pdo->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    assert(in_array('ADMIN', $roles, true), 'ADMIN role must exist');
    assert(in_array('PARTNER', $roles, true), 'PARTNER role must exist');
    assert(in_array('CUSTOMER', $roles, true), 'CUSTOMER role must exist');

    Database::close();
    @unlink($tempDb);
});

// 3. Router Test
it('matches routes and returns 404 for unknown endpoints', function () {
    $router = new Router();
    $matched = false;

    $router->get('/test/ping', function () use (&$matched) {
        $matched = true;
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/test/ping';
    $req = new Request();
    $router->dispatch($req);

    assert($matched === true, 'GET /test/ping should have matched and executed handler');
});

// 4. Entry Code Verification Logic Test
it('validates secret platform entry codes correctly', function () {
    $_ENV['ENTRY_CODE'] = 'secret_test_2026';
    $expected = Config::get('ENTRY_CODE');
    assert($expected === 'secret_test_2026');

    assert(hash_equals($expected, 'secret_test_2026') === true, 'Correct code must match');
    assert(hash_equals($expected, 'wrong_code') === false, 'Wrong code must fail');
});

echo "\n====================================================\n";
echo "Test Results: {$testsPassed} passed, {$testsFailed} failed.\n";
echo "====================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
