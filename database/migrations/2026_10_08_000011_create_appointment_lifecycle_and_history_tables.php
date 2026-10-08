<?php

declare(strict_types=1);

class CreateAppointmentLifecycleAndHistoryTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // Tabela de Histórico de Transições e Auditoria de Estados da Consulta (NI2-82)
        $db->exec("CREATE TABLE IF NOT EXISTS appointment_status_history (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            from_status VARCHAR(30) NULL,
            to_status VARCHAR(30) NOT NULL,
            changed_by_user_id INTEGER NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        ){$engine};");

        // Índices para performance de busca por agenda e auditoria
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_app_status_hist_app ON appointment_status_history (appointment_id);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_appointments_clinic_date ON appointments (clinic_id, scheduled_at);");
        } catch (\Throwable $e) {
            // Ignora se o driver não suportar ou se já existir
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS appointment_status_history;");
    }
}
