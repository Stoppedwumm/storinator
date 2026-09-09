<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

class MigrationService
{
    private PDO $pdo;
    private string $migrationsDir;

    public function __construct(?PDO $pdo = null, ?string $migrationsDir = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->migrationsDir = $migrationsDir ?? dirname(__DIR__, 2) . '/migrations';
    }

    public function run(): array
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        if (!is_dir($this->migrationsDir)) {
            return [];
        }

        $files = scandir($this->migrationsDir);
        $sqlFiles = array_filter($files, fn($f) => str_ends_with($f, '.sql'));
        sort($sqlFiles);

        $applied = [];
        $stmtCheck = $this->pdo->prepare("SELECT id FROM migrations WHERE migration = :migration");
        $stmtRecord = $this->pdo->prepare("INSERT INTO migrations (migration) VALUES (:migration)");

        foreach ($sqlFiles as $file) {
            $stmtCheck->execute([':migration' => $file]);
            if ($stmtCheck->fetchColumn()) {
                continue;
            }

            $sql = file_get_contents($this->migrationsDir . '/' . $file);
            if ($sql === false) {
                throw new RuntimeException("Could not read migration file: {$file}");
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmtRecord->execute([':migration' => $file]);
                $this->pdo->commit();
                $applied[] = $file;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration {$file} failed: " . $e->getMessage(), 0, $e);
            }
        }

        return $applied;
    }
}
