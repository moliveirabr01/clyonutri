<?php

declare(strict_types=1);

/**
 * Migration 22: Criação da Tabela de Métricas e Autorrelatos de Evolução do Paciente (NI2-295 / PX-02)
 */
class CreatePatientSelfMetricsTable
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("
            CREATE TABLE IF NOT EXISTS patient_self_metrics (
                id {$pk},
                clinic_id INTEGER NOT NULL,
                patient_id INTEGER NOT NULL,
                metric_type VARCHAR(50) NOT NULL,
                value REAL NOT NULL,
                unit VARCHAR(30) NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'patient_self_report',
                notes TEXT NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
                FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
            ){$engine}
        ");

        if ($driver === 'sqlite') {
            $cols = $db->query("PRAGMA table_info(patient_self_metrics)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($cols, 'name');
            if (!in_array('updated_at', $colNames, true)) {
                $db->exec("ALTER TABLE patient_self_metrics ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            if (!in_array('created_at', $colNames, true)) {
                $db->exec("ALTER TABLE patient_self_metrics ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            $db->exec("CREATE INDEX IF NOT EXISTS idx_patient_self_metrics_tenant_patient ON patient_self_metrics (clinic_id, patient_id, recorded_at)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_patient_self_metrics_type ON patient_self_metrics (metric_type)");
        } else {
            $db->exec("CREATE INDEX idx_patient_self_metrics_tenant_patient ON patient_self_metrics (clinic_id, patient_id, recorded_at)");
            $db->exec("CREATE INDEX idx_patient_self_metrics_type ON patient_self_metrics (metric_type)");
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS patient_self_metrics");
    }
}
