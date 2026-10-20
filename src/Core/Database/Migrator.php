<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Database;

use PDO;
use RuntimeException;

class Migrator
{
    private PDO $db;
    private string $migrationsDir;

    public function __construct(PDO $db, string $migrationsDir)
    {
        $this->db = $db;
        $this->migrationsDir = $migrationsDir;
        $this->initMigrationsTable();
    }

    private function initMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }

        $this->db->exec($sql);
    }

    public function run(): array
    {
        $executed = [];
        $stmt = $this->db->query("SELECT migration FROM migrations");
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $files = glob($this->migrationsDir . '/*.php');
        sort($files);

        foreach ($files as $file) {
            $migrationName = basename($file, '.php');
            if (in_array($migrationName, $applied, true)) {
                if ($migrationName === '2026_10_15_000040_create_consultation_note_drafts') {
                    require_once $file;
                    if (class_exists('CreateConsultationNoteDrafts') && method_exists('CreateConsultationNoteDrafts', 'ensureRestrictForeignKeys')) {
                        \CreateConsultationNoteDrafts::ensureRestrictForeignKeys($this->db);
                    }
                }
                continue;
            }

            require_once $file;
            $className = $this->resolveClassName($migrationName);

            if (!class_exists($className)) {
                throw new RuntimeException("Classe de migration {$className} não encontrada em {$file}");
            }

            $migration = new $className();
            $this->db->beginTransaction();
            try {
                $migration->up($this->db);
                $ins = $this->db->prepare("INSERT INTO migrations (migration) VALUES (:migration)");
                $ins->execute(['migration' => $migrationName]);
                // MySQL faz commit implícito em DDL (CREATE/ALTER/DROP); só confirma se ainda houver transação aberta.
                if ($this->db->inTransaction()) {
                    $this->db->commit();
                }
                $executed[] = $migrationName;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new RuntimeException("Falha ao executar migration {$migrationName}: " . $e->getMessage(), 0, $e);
            }
        }

        return $executed;
    }

    private function resolveClassName(string $filename): string
    {
        // 2026_10_07_000001_create_tenants_table -> CreateTenantsTable
        $parts = explode('_', $filename);
        // remove prefix numeric/date parts
        $filtered = array_filter($parts, fn($p) => !is_numeric($p));
        $studly = implode('', array_map('ucfirst', $filtered));
        return $studly;
    }
}
