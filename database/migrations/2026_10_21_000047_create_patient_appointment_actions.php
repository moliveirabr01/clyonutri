<?php

declare(strict_types=1);

/**
 * Migration 47 (NI2-137): ações da paciente na próxima consulta.
 *
 * - booking_settings: o que a paciente pode fazer pelo app, por clínica.
 *   patient_can_confirm (padrão ligado), patient_can_cancel e patient_can_request_reschedule (padrão desligados),
 *   patient_show_address (mostrar o endereço do consultório no card da consulta presencial; padrão ligado).
 * - appointment_status_history.changed_by_patient_id: quem mudou o status quando foi a própria paciente.
 * - appointment_change_requests: pedidos de remarcação feitos pela paciente; o nutricionista aceita ou recusa.
 *   Estados: pending, accepted, declined, cancelled (a paciente desistiu ou a consulta foi cancelada), expired.
 */
class CreatePatientAppointmentActions
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        foreach ([
            'patient_can_confirm' => 1,
            'patient_can_cancel' => 0,
            'patient_can_request_reschedule' => 0,
            'patient_show_address' => 1,
        ] as $column => $default) {
            if (!$this->columnExists($db, 'booking_settings', $column)) {
                $db->exec("ALTER TABLE booking_settings ADD COLUMN {$column} INT NOT NULL DEFAULT {$default}");
            }
        }
        // Pelo app, cancelar ou pedir remarcação exige pelo menos 1 hora de antecedência.
        $db->exec('UPDATE booking_settings SET cancel_min_advance_hours = 1 WHERE cancel_min_advance_hours < 1');
        $db->exec('UPDATE booking_settings SET reschedule_min_advance_hours = 1 WHERE reschedule_min_advance_hours < 1');
        if (!$this->columnExists($db, 'appointment_status_history', 'changed_by_patient_id')) {
            $db->exec('ALTER TABLE appointment_status_history ADD COLUMN changed_by_patient_id INT NULL');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS appointment_change_requests (
            id {$pk},
            clinic_id INT NOT NULL,
            appointment_id INT NOT NULL,
            patient_id INT NOT NULL,
            professional_id INT NOT NULL,
            request_type VARCHAR(20) NOT NULL DEFAULT 'reschedule',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            from_scheduled_at DATETIME NOT NULL,
            requested_scheduled_at DATETIME NOT NULL,
            duration_minutes INT NOT NULL,
            reason VARCHAR(300) NULL,
            decided_by_user_id INT NULL,
            decided_at DATETIME NULL,
            decision_note VARCHAR(300) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine}");

        foreach ([
            ['idx_change_requests_clinic_status', 'clinic_id, status, requested_scheduled_at'],
            ['idx_change_requests_appointment', 'appointment_id, status'],
        ] as [$name, $cols]) {
            if (!$this->indexExists($db, 'appointment_change_requests', $name)) {
                $db->exec("CREATE INDEX {$name} ON appointment_change_requests ({$cols})");
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
