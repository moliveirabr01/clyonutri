-- ============================================================================
-- ClyoNutri — Migração Manual 57 (NI2-450 · Período de teste de 14 dias)
-- Equivale a database/migrations/2026_10_26_000057_add_trial_ends_at_to_clinic_subscriptions.php.
-- Rodar no phpMyAdmin DEPOIS do backup e do preflight (atualizacao-v27-preflight.sql). Idempotente. MySQL 5.7.
-- Efeito nos dados: clinic_subscriptions ganha a coluna trial_ends_at (vazia) e um índice. Nenhum registro muda.
-- O saneamento de assinaturas e faturas (database/scripts/saneamento_assinaturas_trial_ni2_450.sql) é OUTRA
-- coisa, altera dados e só deve rodar por decisão explícita. Ver LEIA-ME.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinic_subscriptions', 'migrations')) = 2);

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_subscriptions' AND COLUMN_NAME = 'trial_ends_at');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `clinic_subscriptions` ADD COLUMN `trial_ends_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_subscriptions' AND INDEX_NAME = 'idx_clinic_sub_trial');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_clinic_sub_trial` ON `clinic_subscriptions` (`status`, `trial_ends_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_subscriptions' AND COLUMN_NAME = 'trial_ends_at') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_subscriptions' AND INDEX_NAME = 'idx_clinic_sub_trial') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_26_000057_add_trial_ends_at_to_clinic_subscriptions'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_26_000057_add_trial_ends_at_to_clinic_subscriptions'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 57 aplicada (período de teste)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_26_000057_add_trial_ends_at_to_clinic_subscriptions') AS migracao_57_registrada;
