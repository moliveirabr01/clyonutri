<?php

declare(strict_types=1);

/**
 * Migration 42 (v20 / NI2-433 & NI2-434):
 * Autoagendamento Self-Service — Contrato de Estados, Reserva Temporária (Hold) e Configurações
 *
 * 1. Tabela booking_holds: holds temporários com TTL, idempotência, auditoria e vínculo transacional ao agendamento canônico.
 * 2. Tabela booking_settings: configurações operacionais por clínica (janela de antecedência, TTL, cancelamento/reagendamento).
 */
class CreateBookingHoldsAndSelfServiceTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        // 1. Tabela booking_holds
        if ($driver === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS booking_holds (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    clinic_id INTEGER NOT NULL,
                    professional_id INTEGER NOT NULL,
                    patient_id INTEGER NULL,
                    hold_token VARCHAR(64) NOT NULL UNIQUE,
                    idempotency_key VARCHAR(64) NULL,
                    scheduled_at DATETIME NOT NULL,
                    duration_minutes INTEGER NOT NULL DEFAULT 60,
                    service_type VARCHAR(50) NOT NULL DEFAULT 'first_visit',
                    modality VARCHAR(20) NOT NULL DEFAULT 'presential',
                    status VARCHAR(30) NOT NULL DEFAULT 'held',
                    expires_at_utc DATETIME NOT NULL,
                    appointment_id INTEGER NULL,
                    patient_name VARCHAR(150) NULL,
                    patient_email VARCHAR(150) NULL,
                    patient_phone VARCHAR(50) NULL,
                    patient_cpf VARCHAR(20) NULL,
                    patient_notes TEXT NULL,
                    payment_status VARCHAR(30) NOT NULL DEFAULT 'not_required',
                    payment_reference VARCHAR(100) NULL,
                    teleconsultation_session_id INTEGER NULL,
                    notification_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT,
                    FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE RESTRICT,
                    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT,
                    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_booking_holds_slot ON booking_holds (clinic_id, professional_id, scheduled_at)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_booking_holds_status_exp ON booking_holds (status, expires_at_utc)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_booking_holds_idempotency ON booking_holds (clinic_id, idempotency_key)");
        } else {
            // MySQL 5.7+ / 8
            $db->exec("
                CREATE TABLE IF NOT EXISTS `booking_holds` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `clinic_id` INT NOT NULL,
                    `professional_id` INT NOT NULL,
                    `patient_id` INT NULL,
                    `hold_token` VARCHAR(64) NOT NULL UNIQUE,
                    `idempotency_key` VARCHAR(64) NULL,
                    `scheduled_at` DATETIME NOT NULL,
                    `duration_minutes` INT NOT NULL DEFAULT 60,
                    `service_type` VARCHAR(50) NOT NULL DEFAULT 'first_visit',
                    `modality` VARCHAR(20) NOT NULL DEFAULT 'presential',
                    `status` VARCHAR(30) NOT NULL DEFAULT 'held',
                    `expires_at_utc` DATETIME NOT NULL,
                    `appointment_id` INT NULL,
                    `patient_name` VARCHAR(150) NULL,
                    `patient_email` VARCHAR(150) NULL,
                    `patient_phone` VARCHAR(50) NULL,
                    `patient_cpf` VARCHAR(20) NULL,
                    `patient_notes` TEXT NULL,
                    `payment_status` VARCHAR(30) NOT NULL DEFAULT 'not_required',
                    `payment_reference` VARCHAR(100) NULL,
                    `teleconsultation_session_id` INT NULL,
                    `notification_status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_booking_holds_slot` (`clinic_id`, `professional_id`, `scheduled_at`),
                    INDEX `idx_booking_holds_status_exp` (`status`, `expires_at_utc`),
                    INDEX `idx_booking_holds_idempotency` (`clinic_id`, `idempotency_key`),
                    CONSTRAINT `fk_booking_holds_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_booking_holds_prof` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_booking_holds_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_booking_holds_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // 2. Tabela booking_settings
        if ($driver === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS booking_settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    clinic_id INTEGER NOT NULL UNIQUE,
                    is_enabled INTEGER NOT NULL DEFAULT 1,
                    min_advance_hours INTEGER NOT NULL DEFAULT 2,
                    max_future_days INTEGER NOT NULL DEFAULT 60,
                    hold_ttl_minutes INTEGER NOT NULL DEFAULT 15,
                    cancel_min_advance_hours INTEGER NOT NULL DEFAULT 4,
                    reschedule_min_advance_hours INTEGER NOT NULL DEFAULT 4,
                    require_cpf INTEGER NOT NULL DEFAULT 0,
                    allow_telehealth INTEGER NOT NULL DEFAULT 1,
                    allow_presential INTEGER NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_booking_settings_clinic ON booking_settings (clinic_id)");
        } else {
            // MySQL 5.7+ / 8
            $db->exec("
                CREATE TABLE IF NOT EXISTS `booking_settings` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `clinic_id` INT NOT NULL UNIQUE,
                    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `min_advance_hours` INT NOT NULL DEFAULT 2,
                    `max_future_days` INT NOT NULL DEFAULT 60,
                    `hold_ttl_minutes` INT NOT NULL DEFAULT 15,
                    `cancel_min_advance_hours` INT NOT NULL DEFAULT 4,
                    `reschedule_min_advance_hours` INT NOT NULL DEFAULT 4,
                    `require_cpf` TINYINT(1) NOT NULL DEFAULT 0,
                    `allow_telehealth` TINYINT(1) NOT NULL DEFAULT 1,
                    `allow_presential` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT `fk_booking_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS booking_holds");
        $db->exec("DROP TABLE IF EXISTS booking_settings");
    }
}
