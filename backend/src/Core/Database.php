<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(?string $customPath = null): PDO
    {
        if (self::$pdo !== null && $customPath === null) {
            return self::$pdo;
        }

        $dbPath = $customPath ?? Config::get('SQLITE_BACKEND_PATH', dirname(__DIR__, 2) . '/data/backend.sqlite');
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
            throw new RuntimeException("Failed to create database directory: {$dbDir}");
        }

        $dsn = "sqlite:{$dbPath}";
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Enforce concurrency, integrity and timeout pragmas
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        if ($customPath === null) {
            self::$pdo = $pdo;
        }

        return $pdo;
    }

    public static function close(): void
    {
        self::$pdo = null;
    }
}
