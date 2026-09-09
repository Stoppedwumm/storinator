<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Services\MigrationService;

Config::load();

echo "[Backend Migration] Starting migrations...\n";

try {
    $service = new MigrationService();
    $applied = $service->run();

    if (empty($applied)) {
        echo "[Backend Migration] No new migrations to apply.\n";
    } else {
        foreach ($applied as $m) {
            echo "[Backend Migration] Applied: {$m}\n";
        }
        echo "[Backend Migration] Completed successfully. Total applied: " . count($applied) . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    echo "[Backend Migration ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
