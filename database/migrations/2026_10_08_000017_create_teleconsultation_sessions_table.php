<?php

declare(strict_types=1);

/**
 * Migration 17: Criação da estrutura completa do Consultório Online / Teleconsulta
 * Atende às histórias NI2-221, NI2-234 a NI2-239 e NI2-242 a NI2-250.
 */
class CreateTeleconsultationSessionsTable
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Tabela canônica de Sessões de Teleconsulta
        $db->exec("CREATE TABLE IF NOT EXISTS teleconsultation_sessions (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL UNIQUE,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'daily_co',
            external_room_id VARCHAR(150) NULL,
            external_room_url VARCHAR(255) NULL,
            patient_token_hash VARCHAR(64) NOT NULL UNIQUE,
            professional_token_hash VARCHAR(64) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'scheduled', 
            -- scheduled, device_checked, waiting_room, admitted, in_progress, completed, cancelled, rejected
            device_check_passed INTEGER NOT NULL DEFAULT 0,
            consent_term_version VARCHAR(50) NULL,
            consent_accepted_at DATETIME NULL,
            waiting_room_entered_at DATETIME NULL,
            admitted_at DATETIME NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            clinical_record_id INTEGER NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
        ){$engine};");

        // 2. Tabela de Auditoria e Eventos da Teleconsulta (LGPD / CFN)
        $db->exec("CREATE TABLE IF NOT EXISTS teleconsultation_events (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            -- session_created, token_issued, device_checked, consent_signed, waiting_room_entered, 
            -- patient_admitted, patient_rejected, call_started, clinical_note_appended, call_ended, session_cancelled
            actor_type VARCHAR(30) NOT NULL, -- professional, patient, system
            actor_id INTEGER NULL,
            details_json TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES teleconsultation_sessions(id) ON DELETE CASCADE
        ){$engine};");
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS teleconsultation_events;");
        $db->exec("DROP TABLE IF EXISTS teleconsultation_sessions;");
    }
}
