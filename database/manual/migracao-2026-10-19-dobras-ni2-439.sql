-- ============================================================================
-- ClyoNutri — Migração Manual 44 (NI2-439 · Dobras cutâneas por protocolo)
-- Equivale a database/migrations/2026_10_19_000044_create_skinfold_measurements.php.
-- Rodar no phpMyAdmin com o banco do sistema selecionado, DEPOIS do backup, do preflight
-- (preflight-2026-10-19-dobras-ni2-439.sql) e da migração 43 (migracao-2026-10-19-planos-ni2-9.sql).
-- Idempotente: só cria a tabela se ela não existir. Não altera nenhuma tabela existente.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
-- Tipo real das chaves primárias deste banco. A produção da HostGator usa BIGINT; uma instalação
-- pelas migrations usa INT. As FKs só são aceitas pelo MySQL quando os tipos são iguais.
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_appointments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id');
SET @t_anthro = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'id');


SET @ok_base = (
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','patients','professionals','anthropometric_assessments') AND UPPER(ENGINE) = 'INNODB') = 4
);

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `skinfold_measurements` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `assessment_id` {T_ANTHRO} NULL,
  `record_group_id` INT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `status` VARCHAR(20) NOT NULL DEFAULT ''active'',
  `supersedes_id` INT NULL,
  `correction_reason` TEXT NULL,
  `protocol_code` VARCHAR(40) NOT NULL,
  `catalog_version` VARCHAR(20) NOT NULL,
  `equation_sex` VARCHAR(10) NOT NULL,
  `age_years` INT NOT NULL,
  `assessed_at` DATETIME NOT NULL,
  `sites_json` TEXT NOT NULL,
  `sum_mm` DECIMAL(7,2) NOT NULL,
  `body_density` DECIMAL(7,5) NOT NULL,
  `body_fat_pct` DECIMAL(5,2) NOT NULL,
  `weight_kg` DECIMAL(6,2) NULL,
  `fat_mass_kg` DECIMAL(6,2) NULL,
  `lean_mass_kg` DECIMAL(6,2) NULL,
  `warnings_json` TEXT NULL,
  `provenance_json` TEXT NOT NULL,
  `notes` TEXT NULL,
  `created_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_skinfold_patient` (`clinic_id`, `patient_id`, `status`, `assessed_at`),
  INDEX `idx_skinfold_group` (`record_group_id`, `version`),
  CONSTRAINT `fk_skinfold_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skinfold_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skinfold_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`),
  CONSTRAINT `fk_skinfold_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `anthropometric_assessments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skinfold_measurements') = 1;
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_19_000044_create_skinfold_measurements'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_19_000044_create_skinfold_measurements'')'), 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 44 aplicada (skinfold_measurements)', 'FALHA — ver preflight') AS resultado;
