-- ============================================================================
-- ClyoNutri — Migração Manual 48 (NI2-92 · Hidratação: histórico, correção, exclusão e idempotência)
-- Equivale a database/migrations/2026_10_22_000048_harden_hydration_logs.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-22-hidratacao-ni2-92.sql)
-- e da migração 47. Idempotente: pode rodar de novo sem efeito.
-- Efeito nos dados: nenhum registro é alterado ou apagado. Registros antigos ficam com
-- idempotency_key NULL, source 'app', updated_at/deleted_at NULL.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('hydration_logs','patients','clinics','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 4);

-- 1. Colunas novas.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'idempotency_key');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `idempotency_key` VARCHAR(64) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'source');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT ''app''', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'updated_at');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `updated_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'deleted_at');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `deleted_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Índices: único da operação (idempotência) e por dia (histórico/totais).
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND INDEX_NAME = 'uq_hydration_operation');
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'idempotency_key');
SET @q = IF(@ok_base = 1 AND @has = 0 AND @col = 1, 'CREATE UNIQUE INDEX `uq_hydration_operation` ON `hydration_logs` (`clinic_id`, `patient_id`, `idempotency_key`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND INDEX_NAME = 'idx_hydration_patient_day');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_hydration_patient_day` ON `hydration_logs` (`clinic_id`, `patient_id`, `logged_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Conferência e registro na tabela migrations.
SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
    AND COLUMN_NAME IN ('idempotency_key','source','updated_at','deleted_at')) = 4
  AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
    AND INDEX_NAME IN ('uq_hydration_operation','idx_hydration_patient_day')) = 2
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
    AND INDEX_NAME = 'uq_hydration_operation' AND NON_UNIQUE = 0) = 3);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000048_harden_hydration_logs'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000048_harden_hydration_logs'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 48 aplicada (hidratação: histórico, correção, exclusão lógica e idempotência)', 'FALHA — ver preflight') AS resultado;
