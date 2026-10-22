-- ============================================================================
-- ClyoNutri — Migração Manual 47 (NI2-137 · Próxima consulta: ações da paciente e pedidos de remarcação)
-- Equivale a database/migrations/2026_10_21_000047_create_patient_appointment_actions.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-21-consulta-app-ni2-137.sql)
-- e da migração 46. Idempotente.
-- Efeito nos dados: cada clínica passa a ter "confirmar presença" LIGADO e "cancelar" e "pedir remarcação"
-- DESLIGADOS pelo app (mudam em Configurações). Prazos de 0 hora sobem para 1 hora (mínimo novo).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_appointments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','appointments','appointment_status_history','booking_settings','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 7);

-- 1. Regras da clínica para a paciente (booking_settings).
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_can_confirm');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_can_confirm` INT NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_can_cancel');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_can_cancel` INT NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_can_request_reschedule');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_can_request_reschedule` INT NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_show_address');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_show_address` INT NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@ok_base = 1, 'UPDATE `booking_settings` SET `cancel_min_advance_hours` = GREATEST(1, `cancel_min_advance_hours`), `reschedule_min_advance_hours` = GREATEST(1, `reschedule_min_advance_hours`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Histórico: autoria da paciente.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_status_history' AND COLUMN_NAME = 'changed_by_patient_id');
SET @q = IF(@ok_base = 1 AND @has = 0, CONCAT('ALTER TABLE `appointment_status_history` ADD COLUMN `changed_by_patient_id` ', IFNULL(@t_patients, 'INT'), ' NULL'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Pedidos de remarcação.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `appointment_change_requests` (
  `id` {T_APPOINTMENTS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `appointment_id` {T_APPOINTMENTS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `request_type` VARCHAR(20) NOT NULL DEFAULT ''reschedule'',
  `status` VARCHAR(20) NOT NULL DEFAULT ''pending'',
  `from_scheduled_at` DATETIME NOT NULL,
  `requested_scheduled_at` DATETIME NOT NULL,
  `duration_minutes` INT NOT NULL,
  `reason` VARCHAR(300) NULL,
  `decided_by_user_id` INT NULL,
  `decided_at` DATETIME NULL,
  `decision_note` VARCHAR(300) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  KEY `idx_change_requests_clinic_status` (`clinic_id`, `status`, `requested_scheduled_at`),
  KEY `idx_change_requests_appointment` (`appointment_id`, `status`),
  CONSTRAINT `fk_change_requests_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_change_requests_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_change_requests_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_change_requests_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings'
    AND COLUMN_NAME IN ('patient_can_confirm','patient_can_cancel','patient_can_request_reschedule','patient_show_address')) = 4
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_status_history' AND COLUMN_NAME = 'changed_by_patient_id') = 1
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_change_requests') = 1);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_21_000047_create_patient_appointment_actions'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_21_000047_create_patient_appointment_actions'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 47 aplicada (ações da paciente na consulta e pedidos de remarcação)', 'FALHA — ver preflight') AS resultado;
