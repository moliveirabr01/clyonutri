<?php

declare(strict_types=1);

/**
 * Migration 49 (NI2-96): métricas de adesão com privacidade + check-in diário da paciente.
 *
 * - checkins.updated_at e índice (clínica, paciente, dia): um check-in por paciente por dia, editável no mesmo dia.
 * - analytics_daily_metrics: só contadores diários por clínica e métrica (plano visto, check-in, hidratação).
 *   Sem id de paciente, sem conteúdo clínico.
 * - analytics_daily_subjects + analytics_daily_salts: pseudônimo transitório (HMAC com sal aleatório do dia) só
 *   para contar pacientes distintos no dia. São apagados no dia seguinte; sem o sal, o hash não volta ao paciente.
 */
class CreateAdherenceAnalytics
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        if (!$this->columnExists($db, 'checkins', 'updated_at')) {
            $db->exec('ALTER TABLE checkins ADD COLUMN updated_at DATETIME NULL');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS analytics_daily_metrics (
            id {$pk},
            metric_date DATE NOT NULL,
            clinic_id INT NOT NULL,
            metric VARCHAR(40) NOT NULL,
            event_count INT NOT NULL DEFAULT 0,
            unique_patients INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS analytics_daily_subjects (
            id {$pk},
            metric_date DATE NOT NULL,
            clinic_id INT NOT NULL,
            metric VARCHAR(40) NOT NULL,
            subject_hash CHAR(64) NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS analytics_daily_salts (
            id {$pk},
            metric_date DATE NOT NULL,
            salt CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL
        ){$engine}");

        foreach ([
            ['checkins', 'idx_checkins_patient_date', 'clinic_id, patient_id, checkin_date', false],
            ['analytics_daily_metrics', 'uq_analytics_daily_metric', 'metric_date, clinic_id, metric', true],
            ['analytics_daily_subjects', 'uq_analytics_daily_subject', 'metric_date, clinic_id, metric, subject_hash', true],
            ['analytics_daily_salts', 'uq_analytics_daily_salt', 'metric_date', true],
        ] as [$table, $name, $cols, $unique]) {
            if (!$this->indexExists($db, $table, $name)) {
                $db->exec(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . "{$name} ON {$table} ({$cols})");
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
