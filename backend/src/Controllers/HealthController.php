<?php

declare(strict_types=1);

namespace App\Controllers;

use App\BigStore\BigStoreClient;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Throwable;

class HealthController
{
    public function check(Request $request): void
    {
        $dbStatus = 'connected';
        $dbError = null;

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT 1 as healthy');
            $row = $stmt->fetch();
            if (!$row || (int) $row['healthy'] !== 1) {
                $dbStatus = 'error';
            }
        } catch (Throwable $e) {
            $dbStatus = 'unreachable';
            $dbError = $e->getMessage();
        }

        // Check BigStore connectivity across internal Docker network
        $bigstoreClient = new BigStoreClient();
        $bigstoreHealth = $bigstoreClient->checkHealth();

        $isHealthy = ($dbStatus === 'connected');

        $data = [
            'status' => $isHealthy ? 'healthy' : 'degraded',
            'service' => 'backend',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => Config::get('APP_ENV', 'development'),
            'services' => [
                'php_version' => PHP_VERSION,
                'database' => [
                    'driver' => 'sqlite',
                    'status' => $dbStatus,
                    'error' => $dbError,
                ],
                'bigstore' => $bigstoreHealth,
            ],
        ];

        Response::success($data, $isHealthy ? 200 : 503);
    }
}
