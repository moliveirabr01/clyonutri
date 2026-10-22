-- ============================================================================
-- ClyoNutri — Migração Manual 49 (NI2-96 · Check-in diário e métricas de adesão com privacidade)
-- Equivale a database/migrations/2026_10_22_000049_create_adherence_analytics.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-22-metricas-adesao-ni2-96.sql)
-- e da migração 48. Idempotente.
-- Efeito nos dados: nenhum dado existente muda. checkins ganha a coluna updated_at e um índice; três tabelas novas
-- (contadores diários por clínica e pseudônimos do dia, sem id de paciente). A coleta já começa ligada
-- (Admin > Configurações globais > "Métricas de adesão agregadas" desliga).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','checkins','hydration_logs','platform_settings') AND UPPER(ENGINE) = 'INNODB') = 5);

-- 1. Check-in: um por dia, corrigível no mesmo dia.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'checkins' AND COLUMN_NAME = 'updated_at');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `checkins` ADD COLUMN `updated_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'checkins' AND INDEX_NAME = 'idx_checkins_patient_date');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_checkins_patient_date` ON `checkins` (`clinic_id`, `patient_id`, `checkin_date`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Contadores diários por clínica e métrica (sem id de paciente).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `analytics_daily_metrics` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `metric_date` DATE NOT NULL,
  `clinic_id` {T_CLINICS} NOT NULL,
  `metric` VARCHAR(40) NOT NULL,
  `event_count` INT NOT NULL DEFAULT 0,
  `unique_patients` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_analytics_daily_metric` (`metric_date`, `clinic_id`, `metric`),
  CONSTRAINT `fk_analytics_metrics_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Pseudônimo do dia (HMAC com sal aleatório), apagado no dia seguinte pelo agendador.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `analytics_daily_subjects` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `metric_date` DATE NOT NULL,
  `clinic_id` {T_CLINICS} NOT NULL,
  `metric` VARCHAR(40) NOT NULL,
  `subject_hash` CHAR(64) NOT NULL,
  UNIQUE KEY `uq_analytics_daily_subject` (`metric_date`, `clinic_id`, `metric`, `subject_hash`),
  CONSTRAINT `fk_analytics_subjects_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `analytics_daily_salts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `metric_date` DATE NOT NULL,
  `salt` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_analytics_daily_salt` (`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('analytics_daily_metrics','analytics_daily_subjects','analytics_daily_salts')) = 3
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'checkins' AND COLUMN_NAME = 'updated_at') = 1);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000049_create_adherence_analytics'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000049_create_adherence_analytics'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 49 aplicada (check-in diário e métricas de adesão agregadas)', 'FALHA — ver preflight') AS resultado;
