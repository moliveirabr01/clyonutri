<?php

declare(strict_types=1);

class EnhanceAnthropometryAndSharingTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // Adicionar colunas de circunferências complementares e controle de compartilhamento (NI2-65, NI2-69)
        $newColumns = [
            'neck_cm' => 'DECIMAL(5,2) NULL',
            'chest_cm' => 'DECIMAL(5,2) NULL',
            'arm_cm' => 'DECIMAL(5,2) NULL',
            'thigh_cm' => 'DECIMAL(5,2) NULL',
            'calf_cm' => 'DECIMAL(5,2) NULL',
            'is_shared' => 'BOOLEAN NOT NULL DEFAULT 0',
            'shared_at' => 'DATETIME NULL',
            'shared_by_user_id' => 'INTEGER NULL',
        ];

        foreach ($newColumns as $colName => $colDef) {
            try {
                $db->exec("ALTER TABLE anthropometric_assessments ADD COLUMN {$colName} {$colDef};");
            } catch (\Throwable $e) {
                // Coluna já existe ou não pôde ser adicionada individualmente
            }
        }

        // Tabela de Auditoria de Compartilhamento da Evolução Antropométrica (NI2-69)
        $db->exec("CREATE TABLE IF NOT EXISTS anthropometry_shares_audit (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            assessment_id INTEGER NOT NULL,
            action VARCHAR(20) NOT NULL,
            changed_by_user_id INTEGER NULL,
            reason TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (assessment_id) REFERENCES anthropometric_assessments(id) ON DELETE CASCADE
        ){$engine};");

        // Índices para otimização de consultas temporais e filtros de compartilhamento
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_anthro_patient_date ON anthropometric_assessments (clinic_id, patient_id, assessed_at);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_anthro_shares_audit ON anthropometry_shares_audit (assessment_id, clinic_id);");
        } catch (\Throwable $e) {
            // Ignora se não suportado
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS anthropometry_shares_audit;");
    }
}
