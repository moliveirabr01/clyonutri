-- ============================================================================
-- ClyoNutri — Migração 2026-10-15 (v6): login_attempts com bucket_key como chave primária
-- Rodar no phpMyAdmin, com o banco do sistema selecionado (aba SQL ou Importar).
--
-- Para quem: bancos em que login_attempts foi criada com a coluna `id` (SQL manual ou dump
-- anteriores à v6). No staging atual a tabela JÁ está no formato novo: o script só registra a
-- migração e não altera nada.
--
-- Por quê: o limitador tenta INSERT e ignora a duplicidade; com `id` AUTO_INCREMENT cada INSERT
-- recusado consome um número. Esgotado o número, nenhum login novo é aceito.
--
-- Seguro: idempotente (pode rodar de novo); preserva as linhas existentes; se encontrar
-- bucket_key repetido, NÃO altera nada e mostra o aviso. Compatível com MySQL 5.7/8 e MariaDB.
-- Todas as referências usam o nome do banco capturado no início (evita o phpMyAdmin
-- procurar a tabela em information_schema ao rodar várias consultas juntas).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t = CONCAT('`', @db, '`.`login_attempts`');

-- Diagnóstico do estado atual
SET @has_table = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts');
SET @has_id = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'id');
SET @has_uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'bucket_key');
SET @bk_ascii = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'bucket_key' AND COLLATION_NAME = 'ascii_bin');
SET @sc_ascii = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'scope' AND COLLATION_NAME = 'ascii_bin');
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_attempts_last');

-- Repetidos em bucket_key (deve ser 0)
SET @dups = 0;
SET @q = IF(@has_table = 1, CONCAT('SELECT COUNT(*) FROM (SELECT bucket_key FROM ', @t, ' GROUP BY bucket_key HAVING COUNT(*) > 1) d INTO @dups'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @ok = (@has_table = 1 AND @dups = 0);

-- Conversão em um único ALTER (só o que falta). Remover `id` remove também a chave primária antiga.
SET @parts = CONCAT_WS(', ',
  IF(@has_id = 1, 'DROP COLUMN `id`', NULL),
  IF(@has_uq = 1, 'DROP INDEX `bucket_key`', NULL),
  IF(@bk_ascii = 0, 'MODIFY `bucket_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', NULL),
  IF(@sc_ascii = 0, 'MODIFY `scope` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', NULL),
  IF(@has_id = 1 OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'PRIMARY') = 0,
     'ADD PRIMARY KEY (`bucket_key`)', NULL),
  IF(@has_idx = 0, 'ADD INDEX `idx_login_attempts_last` (`last_attempt_at`)', NULL)
);
SET @q = IF(@ok AND @parts <> '', CONCAT('ALTER TABLE ', @t, ' ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Registro da migração (somente se a tabela está correta)
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_15_000033_convert_login_attempts_to_bucket_key_primary'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_15_000033_convert_login_attempts_to_bucket_key_primary'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência: deve mostrar resultado = 'OK'
SELECT CASE
  WHEN @has_table = 0 THEN 'ERRO: tabela login_attempts não existe. Rode antes migracao-2026-10-14-login-attempts.sql.'
  WHEN @dups > 0 THEN CONCAT('ATENÇÃO: ', @dups, ' bucket_key repetido(s). Nada foi alterado; envie este resultado para análise.')
  WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'id') = 0
   AND (SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'PRIMARY' LIMIT 1) = 'bucket_key'
   AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_login_attempts_last') = 1
  THEN 'OK'
  ELSE 'ERRO: estrutura inesperada. Envie o resultado de SHOW CREATE TABLE login_attempts.'
END AS resultado,
@db AS banco;
