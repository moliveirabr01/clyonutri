<?php

declare(strict_types=1);

/**
 * Migration 46 (NI2-135): evolução da paciente.
 *
 * - patient_self_metric_permissions: quais métricas do catálogo (SelfMetricCatalog) cada paciente pode
 *   autorrelatar pelo app. Sem linha = não habilitada (padrão fechado).
 * - patient_goals: metas definidas pelo profissional (medida, valor, direção, data opcional, mensagem).
 *   Encerrar = status 'achieved' ou 'cancelled'; nada é apagado.
 */
class CreateSelfMetricPermissionsAndGoals
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS patient_self_metric_permissions (
            id {$pk},
            clinic_id INT NOT NULL,
            patient_id INT NOT NULL,
            metric_type VARCHAR(40) NOT NULL,
            enabled INT NOT NULL DEFAULT 1,
            updated_by_user_id INT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS patient_goals (
            id {$pk},
            clinic_id INT NOT NULL,
            patient_id INT NOT NULL,
            professional_id INT NOT NULL,
            metric_type VARCHAR(40) NOT NULL,
            target_value DECIMAL(7,2) NOT NULL,
            unit VARCHAR(10) NOT NULL,
            direction VARCHAR(10) NOT NULL,
            target_date DATE NULL,
            message_for_patient VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by_user_id INT NULL,
            closed_by_user_id INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine}");

        foreach ([
            ['patient_self_metric_permissions', 'uq_self_metric_perm', 'clinic_id, patient_id, metric_type', true],
            ['patient_goals', 'idx_patient_goals', 'clinic_id, patient_id, status', false],
            ['patient_self_metrics', 'idx_self_metrics_patient_date', 'clinic_id, patient_id, recorded_at', false],
        ] as [$table, $name, $cols, $unique]) {
            if (!$this->indexExists($db, $table, $name)) {
                $db->exec(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . "{$name} ON {$table} ({$cols})");
            }
        }
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
