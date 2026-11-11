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
