-- ============================================================================
-- ClyoNutri v27: ATUALIZAÇÃO DO BANCO (migrações 56 e 57)
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do atualizacao-v27-preflight.sql,
-- e ANTES de subir os arquivos da v27. Idempotente: pode rodar de novo sem efeito. MySQL 5.7.
-- Contém, na ordem e sem alteração, os dois scripts por migração:
--   56 · migracao-2026-10-26-aceite-termos-ni2-449.sql (tabela user_legal_acceptances)
--   57 · migracao-2026-10-26-trial-ni2-450.sql (coluna trial_ends_at)
-- Só cria estrutura; não apaga nem altera dados existentes.
-- ============================================================================

-- ============================================================================
-- ClyoNutri — Migração Manual 56 (NI2-449 · Aceite versionado dos Termos de Uso e da Política de Privacidade)
-- Equivale a database/migrations/2026_10_26_000056_create_user_legal_acceptances.php.
-- Rodar no phpMyAdmin DEPOIS do backup e do preflight (preflight-2026-10-26-aceite-termos-ni2-449.sql).
-- Idempotente: pode rodar de novo sem efeito. Compatível com MySQL 5.7.
-- Efeito nos dados: só cria a tabela vazia user_legal_acceptances e um índice nela. Nada existente é alterado.
--  * Profissionais e equipe já cadastrados ficam sem aceite registrado e veem a tela /aceite-termos na
--    próxima entrada (não são bloqueados para sair do sistema nem para exportar dados).
--  * O aceite dos pacientes vai para patient_consents, que já existe; a primeira versão de cada texto entra em
--    consent_terms sozinha, quando o primeiro paciente aceita.
-- IMPORTANTE: rode este SQL ANTES de subir o pacote. Sem a tabela, o cadastro de profissional falha
-- (nada é gravado) e a tela de aceite fica desligada.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('users', 'migrations')) = 2);

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `user_legal_acceptances` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `clinic_id` INT NULL,
  `document` VARCHAR(20) NOT NULL,
  `version` VARCHAR(20) NOT NULL,
  `accepted_at` DATETIME NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `source` VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances' AND INDEX_NAME = 'idx_user_legal_acceptances_lookup');
SET @tab = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances');
SET @q = IF(@tab = 1 AND @has = 0, 'CREATE INDEX `idx_user_legal_acceptances_lookup` ON `user_legal_acceptances` (`user_id`, `document`, `version`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances' AND UPPER(ENGINE) = 'INNODB') = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances'
       AND COLUMN_NAME IN ('id','user_id','clinic_id','document','version','accepted_at','ip_address','user_agent','source')) = 9
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances' AND INDEX_NAME = 'idx_user_legal_acceptances_lookup') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_26_000056_create_user_legal_acceptances'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_26_000056_create_user_legal_acceptances'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 56 aplicada (aceite dos Termos e da Política)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_26_000056_create_user_legal_acceptances') AS migracao_56_registrada;

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
