<?php

declare(strict_types=1);

/**
 * Migration 51 (NI2-92; era a 48 e depois a 50, renumerada na integração depois da NI2-73): registro de hidratação com histórico, edição e idempotência.
 *
 * - hydration_logs.idempotency_key: a mesma operação do app (toque + reenvios) grava uma linha só.
 *   Índice único (clinic_id, patient_id, idempotency_key); linhas antigas ficam com NULL (permitido em duplicidade).
 * - hydration_logs.source: quem originou o registro ('app' = a própria paciente). B3.
 * - hydration_logs.updated_at / deleted_at: edição e exclusão lógica pela paciente (a linha nunca é apagada).
 * - idx_hydration_patient_day (clinic_id, patient_id, logged_at): totais por dia e histórico por intervalo.
 *
 * logged_at continua no fuso da aplicação (APP_TIMEZONE, America/Sao_Paulo), como desde a NI2-89.
 */
class HardenHydrationLogs
{
    public function up(PDO $db): void
    {
        foreach ([
            'idempotency_key' => 'VARCHAR(64) NULL',
            'source' => "VARCHAR(20) NOT NULL DEFAULT 'app'",
            'updated_at' => 'DATETIME NULL',
            'deleted_at' => 'DATETIME NULL',
        ] as $column => $definition) {
            if (!$this->columnExists($db, 'hydration_logs', $column)) {
                $db->exec("ALTER TABLE hydration_logs ADD COLUMN {$column} {$definition}");
            }
        }

        if (!$this->indexExists($db, 'hydration_logs', 'uq_hydration_operation')) {
            $db->exec('CREATE UNIQUE INDEX uq_hydration_operation ON hydration_logs (clinic_id, patient_id, idempotency_key)');
        }
        if (!$this->indexExists($db, 'hydration_logs', 'idx_hydration_patient_day')) {
            $db->exec('CREATE INDEX idx_hydration_patient_day ON hydration_logs (clinic_id, patient_id, logged_at)');
        }
    }

    public function down(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach (['uq_hydration_operation', 'idx_hydration_patient_day'] as $index) {
            if ($this->indexExists($db, 'hydration_logs', $index)) {
                $db->exec($mysql ? "DROP INDEX {$index} ON hydration_logs" : "DROP INDEX {$index}");
            }
        }
    }

    private function columnExists(PDO $db, string $table, string $column): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $st->execute(['t' => $table, 'c' => $column]);
        return (int)$st->fetchColumn() > 0;
    }

    private function indexExists(PDO $db, string $table, string $index): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :n");
            $st->execute(['n' => $index]);
            return (bool)$st->fetchColumn();
        }
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :n');
        $st->execute(['t' => $table, 'n' => $index]);
        return (int)$st->fetchColumn() > 0;
    }
}
