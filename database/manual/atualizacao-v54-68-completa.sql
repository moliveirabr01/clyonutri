-- ==============================================================================
-- ClyoNutri v54.68 · ATUALIZAÇÃO DO BANCO (migration 096)
-- ==============================================================================
SET NAMES utf8mb4;
-- Para produção na v54.65 (banco já com as migrations até a 095). Rode DEPOIS do backup e do preflight,
-- no phpMyAdmin (aba SQL ou Importar), de uma vez. Idempotente: pode rodar de novo sem duplicar nada.
-- Compatível com MySQL 5.7.44: sem ADD COLUMN IF NOT EXISTS; cada coluna é guardada por information_schema
-- via variável (no phpMyAdmin, SELECT direto no information_schema muda o banco das consultas seguintes).
-- Só ACRESCENTA colunas anuláveis ou com padrão: o código v54.65 continua funcionando com o banco novo.
--   · NI2-858 (SEC-001): OTP, IP do solicitante e alerta de mesmo IP no aceite de transcrição
--   · NI2-859 (SEC-004): MFA/TOTP em users
--   · NI2-861 (F-03): appointment_id em anamneses, avaliações, planos e versões de plano
-- ==============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests' AND COLUMN_NAME = 'otp_hash');
SET @s := IF(@c = 0, 'ALTER TABLE `consultation_ai_consent_requests` ADD COLUMN `otp_hash` VARCHAR(255) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests' AND COLUMN_NAME = 'otp_expires_at_utc');
SET @s := IF(@c = 0, 'ALTER TABLE `consultation_ai_consent_requests` ADD COLUMN `otp_expires_at_utc` DATETIME NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests' AND COLUMN_NAME = 'otp_attempts');
SET @s := IF(@c = 0, 'ALTER TABLE `consultation_ai_consent_requests` ADD COLUMN `otp_attempts` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests' AND COLUMN_NAME = 'requested_ip_hash');
SET @s := IF(@c = 0, 'ALTER TABLE `consultation_ai_consent_requests` ADD COLUMN `requested_ip_hash` VARCHAR(64) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests' AND COLUMN_NAME = 'same_ip_alert');
SET @s := IF(@c = 0, 'ALTER TABLE `consultation_ai_consent_requests` ADD COLUMN `same_ip_alert` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_enabled');
SET @s := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_secret_enc');
SET @s := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_secret_enc` TEXT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_recovery_codes_json');
SET @s := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_recovery_codes_json` TEXT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_enrolled_at');
SET @s := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_enrolled_at` DATETIME NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mfa_last_step');
SET @s := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `mfa_last_step` BIGINT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'anamneses' AND COLUMN_NAME = 'appointment_id');
SET @s := IF(@c = 0, 'ALTER TABLE `anamneses` ADD COLUMN `appointment_id` INT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'appointment_id');
SET @s := IF(@c = 0, 'ALTER TABLE `anthropometric_assessments` ADD COLUMN `appointment_id` INT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'appointment_id');
SET @s := IF(@c = 0, 'ALTER TABLE `meal_plans` ADD COLUMN `appointment_id` INT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_versions' AND COLUMN_NAME = 'appointment_id');
SET @s := IF(@c = 0, 'ALTER TABLE `meal_plan_versions` ADD COLUMN `appointment_id` INT NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Registro da migration (o bin/migrate.php não a repete).
INSERT IGNORE INTO migrations (migration, executed_at) VALUES ('2026_11_15_000096_ni2_856_security_clinical_core', NOW());
