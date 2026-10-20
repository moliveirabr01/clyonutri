-- ============================================================================
-- ClyoNutri — Migração manual MySQL 5.7+ / 8.0 (v20 — Autoagendamento NI2-433 / NI2-434)
-- Equivale à migration 2026_10_16_000042_create_booking_holds_and_self_service_tables.
--
-- Rode ANTES: preflight-2026-10-16-autoagendamento-v20.sql (somente leitura).
-- Idempotente: se as tabelas já existirem com a estrutura correta, nada muda.
-- Fail-closed: se existirem com a estrutura antiga (versão anterior deste arquivo),
-- nada é criado nem apagado e o resultado diz FALHA.
-- Não contém DELETE, DROP nem TRUNCATE.
--
-- Revisão 2026-10-20: a versão anterior deste arquivo usava INT UNSIGNED (o MySQL 8
-- recusava as FKs para clinics.id, que é INT) e colunas diferentes das que o código
-- usa (max_advance_days no lugar de max_future_days, sem require_cpf, payment_status,
-- notification_status). O DDL abaixo é o da migration 42, conferido em MySQL 8.0.46.
-- ============================================================================

SET NAMES utf8mb4;
SET @db = DATABASE();

-- Estrutura antiga (arquivo v20 anterior)? Então não segue.
SET @bs_exists  = (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings');
SET @bh_exists  = (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds');
SET @bs_ok_cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME IN ('max_future_days', 'require_cpf'));
SET @bh_ok_cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds' AND COLUMN_NAME IN ('payment_status', 'notification_status'));
SET @estrutura_antiga = (@bs_exists = 1 AND @bs_ok_cols < 2) OR (@bh_exists = 1 AND @bh_ok_cols < 2);

SET @q = IF(@estrutura_antiga, 'DO 0', '
CREATE TABLE IF NOT EXISTS `booking_holds` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `professional_id` INT NOT NULL,
  `patient_id` INT NULL,
  `hold_token` VARCHAR(64) NOT NULL UNIQUE,
  `idempotency_key` VARCHAR(64) NULL,
  `scheduled_at` DATETIME NOT NULL,
  `duration_minutes` INT NOT NULL DEFAULT 60,
  `service_type` VARCHAR(50) NOT NULL DEFAULT ''first_visit'',
  `modality` VARCHAR(20) NOT NULL DEFAULT ''presential'',
  `status` VARCHAR(30) NOT NULL DEFAULT ''held'',
  `expires_at_utc` DATETIME NOT NULL,
  `appointment_id` INT NULL,
  `patient_name` VARCHAR(150) NULL,
  `patient_email` VARCHAR(150) NULL,
  `patient_phone` VARCHAR(50) NULL,
  `patient_cpf` VARCHAR(20) NULL,
  `patient_notes` TEXT NULL,
  `payment_status` VARCHAR(30) NOT NULL DEFAULT ''not_required'',
  `payment_reference` VARCHAR(100) NULL,
  `teleconsultation_session_id` INT NULL,
  `notification_status` VARCHAR(30) NOT NULL DEFAULT ''pending'',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_booking_holds_slot` (`clinic_id`, `professional_id`, `scheduled_at`),
  INDEX `idx_booking_holds_status_exp` (`status`, `expires_at_utc`),
  INDEX `idx_booking_holds_idempotency` (`clinic_id`, `idempotency_key`),
  CONSTRAINT `fk_booking_holds_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_prof` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@estrutura_antiga, 'DO 0', '
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Registra a migration 42 (assim o php bin/migrate.php não tenta criar de novo).
SET @ok = NOT @estrutura_antiga
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME IN ('max_future_days', 'require_cpf')) = 2
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds' AND COLUMN_NAME IN ('payment_status', 'notification_status')) = 2;
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_16_000042_create_booking_holds_and_self_service_tables'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_16_000042_create_booking_holds_and_self_service_tables'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT
  CASE
    WHEN @estrutura_antiga THEN 'FALHA — booking_settings/booking_holds existem com a estrutura antiga do v20. Nada foi alterado; fale com o suporte técnico antes de seguir.'
    WHEN @ok THEN 'OK — migração 42 aplicada (autoagendamento)'
    ELSE 'FALHA — ver preflight'
  END AS resultado,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_16_000042_create_booking_holds_and_self_service_tables') AS migracao_42_registrada;
