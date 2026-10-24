-- ============================================================================
-- ClyoNutri — Migração Manual 54 (NI2-132 · Diário alimentar: conteúdo da chave de idempotência e fuso)
-- Equivale a database/migrations/2026_10_24_000054_harden_food_diary_entries.php.
-- Rodar no phpMyAdmin DEPOIS do backup e do preflight (preflight-2026-10-24-diario-ni2-132.sql). Idempotente.
-- Efeito nos dados: food_diary_entries ganha request_hash (SHA-256 do conteúdo, para devolver 409 quando a mesma
-- chave chega com outro conteúdo) e consumed_tz (fuso do aparelho da paciente), mais dois índices para a paginação.
-- Nenhum registro muda de valor: os antigos ficam com request_hash e consumed_tz nulos e continuam funcionando.
-- O código novo tolera o banco sem esta migração (ele só não grava hash/fuso até ela rodar).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND UPPER(ENGINE) = 'INNODB') = 1);

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME = 'request_hash');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `food_diary_entries` ADD COLUMN `request_hash` CHAR(64) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME = 'consumed_tz');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `food_diary_entries` ADD COLUMN `consumed_tz` VARCHAR(64) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_patient_consumed');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_food_diary_patient_consumed` ON `food_diary_entries` (`patient_id`, `clinic_id`, `consumed_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_clinic_consumed');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_food_diary_clinic_consumed` ON `food_diary_entries` (`clinic_id`, `consumed_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME IN ('request_hash', 'consumed_tz')) = 2
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_patient_consumed') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_clinic_consumed') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_24_000054_harden_food_diary_entries'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_24_000054_harden_food_diary_entries'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 54 aplicada (diário alimentar)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `food_diary_entries`) AS registros_no_diario,
  (SELECT COUNT(*) FROM `food_diary_entries` WHERE `request_hash` IS NULL) AS registros_antigos_sem_hash;
