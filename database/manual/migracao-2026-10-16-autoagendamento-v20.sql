-- ============================================================================
-- ClyoNutri — Migração Manual MySQL 5.7+ / 8.0 (v20 — Autoagendamento NI2-433 / NI2-434)
-- Criação das tabelas canônicas booking_holds e booking_settings com RESTRICT
-- Execução segura idempotente em produção.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. TABELA: booking_settings
-- Configurações de política de autoagendamento por clínica/tenant
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT UNSIGNED NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `default_duration_minutes` INT NOT NULL DEFAULT 60,
  `min_advance_hours` INT NOT NULL DEFAULT 2,
  `max_advance_days` INT NOT NULL DEFAULT 60,
  `hold_ttl_minutes` INT NOT NULL DEFAULT 15,
  `cancel_min_advance_hours` INT NOT NULL DEFAULT 4,
  `reschedule_min_advance_hours` INT NOT NULL DEFAULT 4,
  `allow_telehealth` TINYINT(1) NOT NULL DEFAULT 1,
  `allow_presential` TINYINT(1) NOT NULL DEFAULT 1,
  `terms_url` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_booking_settings_clinic` (`clinic_id`),
  CONSTRAINT `fk_booking_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. TABELA: booking_holds
-- Bloqueio temporário (Hold de 15 minutos) com token criptográfico seguro
-- e proteção ACID contra overbooking e concorrência
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_holds` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT UNSIGNED NOT NULL,
  `professional_id` INT UNSIGNED NOT NULL,
  `patient_id` INT UNSIGNED NULL,
  `hold_token` VARCHAR(64) NOT NULL,
  `idempotency_key` VARCHAR(128) NULL,
  `scheduled_at` DATETIME NOT NULL,
  `duration_minutes` INT NOT NULL DEFAULT 60,
  `service_type` VARCHAR(50) NOT NULL DEFAULT 'first_visit',
  `modality` VARCHAR(30) NOT NULL DEFAULT 'presential',
  `status` VARCHAR(30) NOT NULL DEFAULT 'held',
  `created_at_utc` DATETIME NOT NULL,
  `expires_at_utc` DATETIME NOT NULL,
  `appointment_id` INT UNSIGNED NULL,
  `teleconsultation_session_id` INT UNSIGNED NULL,
  `patient_name` VARCHAR(191) NULL,
  `patient_email` VARCHAR(191) NULL,
  `patient_phone` VARCHAR(50) NULL,
  `patient_cpf` VARCHAR(20) NULL,
  `patient_notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_booking_holds_token` (`hold_token`),
  UNIQUE KEY `uq_booking_holds_idempotency` (`clinic_id`, `idempotency_key`),
  KEY `idx_booking_holds_active_slot` (`clinic_id`, `professional_id`, `scheduled_at`, `status`),
  KEY `idx_booking_holds_expires` (`expires_at_utc`),
  KEY `idx_booking_holds_appointment` (`appointment_id`),
  CONSTRAINT `fk_booking_holds_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. SEED DE CONFIGURAÇÕES PADRÃO PARA CLÍNICAS ATIVAS EXISTENTES
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `booking_settings` (`clinic_id`, `is_enabled`, `default_duration_minutes`, `min_advance_hours`, `max_advance_days`, `hold_ttl_minutes`, `cancel_min_advance_hours`, `reschedule_min_advance_hours`, `allow_telehealth`, `allow_presential`)
SELECT `id`, 1, 60, 2, 60, 15, 4, 4, 1, 1
FROM `clinics`
WHERE `status` = 'active';

SET FOREIGN_KEY_CHECKS = 1;

-- Verificação final
SELECT COUNT(*) AS total_booking_settings FROM `booking_settings`;
SELECT COUNT(*) AS total_booking_holds FROM `booking_holds`;
