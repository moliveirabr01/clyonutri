-- ============================================================================
-- ClyoNutri — Migração 2026-10-14: tabela login_attempts (auditoria de segurança, F03)
-- Rodar UMA vez no phpMyAdmin, com o banco do sistema selecionado (aba SQL ou Importar).
-- Idempotente: pode rodar de novo sem erro e sem duplicar nada. Não altera nem apaga dados.
-- Compatível com MySQL 5.7, MySQL 8 e MariaDB.
--
-- v6: a tabela nasce com bucket_key como CHAVE PRIMÁRIA (sem coluna id), igual ao staging.
-- Se a tabela já existir, este arquivo não a altera; para converter uma tabela antiga (com id),
-- rode em seguida migracao-2026-10-15-login-attempts-chave-primaria.sql.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `bucket_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `failures` int NOT NULL DEFAULT '0',
  `window_started_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `last_attempt_at` datetime NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_login_attempts_last` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `executed_at`)
SELECT '2026_10_14_000032_create_login_attempts_table', CURRENT_TIMESTAMP FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_14_000032_create_login_attempts_table');

-- Conferência: deve mostrar a tabela e 1 linha de migração
SHOW TABLES LIKE 'login_attempts';
SELECT `migration` FROM `migrations` WHERE `migration` = '2026_10_14_000032_create_login_attempts_table';
