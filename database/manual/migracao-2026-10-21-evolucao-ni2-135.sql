-- ============================================================================
-- ClyoNutri — Migração Manual 46 (NI2-135 · Evolução: métricas liberadas por paciente e metas)
-- Equivale a database/migrations/2026_10_21_000046_create_self_metric_permissions_and_goals.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-21-evolucao-ni2-135.sql)
-- e da migração 45. Idempotente. Não altera dados existentes: nenhuma medida fica liberada até o
-- nutricionista liberar no perfil da paciente.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
-- Tipo real das chaves primárias (a produção da HostGator usa BIGINT; as FKs exigem o mesmo tipo).
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','patient_self_metrics','anthropometric_assessments','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 6);

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `patient_self_metric_permissions` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `metric_type` VARCHAR(40) NOT NULL,
  `enabled` INT NOT NULL DEFAULT 1,
  `updated_by_user_id` INT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_self_metric_perm` (`clinic_id`, `patient_id`, `metric_type`),
  CONSTRAINT `fk_self_metric_perm_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_self_metric_perm_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `patient_goals` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `metric_type` VARCHAR(40) NOT NULL,
  `target_value` DECIMAL(7,2) NOT NULL,
  `unit` VARCHAR(10) NOT NULL,
  `direction` VARCHAR(10) NOT NULL,
  `target_date` DATE NULL,
  `message_for_patient` VARCHAR(500) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''active'',
  `created_by_user_id` INT NULL,
  `closed_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `closed_at` DATETIME NULL,
  KEY `idx_patient_goals` (`clinic_id`, `patient_id`, `status`),
  CONSTRAINT `fk_patient_goals_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_patient_goals_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_patient_goals_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice para o filtro de período dos autorrelatos (só se ainda não existir).
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_self_metrics' AND INDEX_NAME = 'idx_self_metrics_patient_date');
SET @q = IF(@ok_base = 1 AND @has_idx = 0, 'CREATE INDEX `idx_self_metrics_patient_date` ON `patient_self_metrics` (`clinic_id`, `patient_id`, `recorded_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('patient_self_metric_permissions','patient_goals')) = 2);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_21_000046_create_self_metric_permissions_and_goals'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_21_000046_create_self_metric_permissions_and_goals'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 46 aplicada (evolução: métricas liberadas e metas)', 'FALHA — ver preflight') AS resultado;
