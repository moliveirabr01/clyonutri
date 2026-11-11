-- ============================================================================
-- ClyoNutri: ATUALIZAÇÃO DO BANCO — "Último login" no backoffice (migração 59)
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do
-- atualizacao-ultimo-login-preflight.sql, e ANTES de subir os arquivos novos.
-- Idempotente: pode rodar de novo sem efeito. Compatível com MySQL 5.7 (sem ADD COLUMN IF NOT EXISTS).
--   59 · users.last_login_at (DATETIME NULL, gravado em UTC pelo login) + índice idx_users_last_login
-- Só adiciona uma coluna vazia e um índice; não apaga nem altera nenhum dado existente.
-- A tela mostra "Sem registro" para quem ainda não entrou depois deste SQL.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('users', 'clinic_members', 'migrations')) = 3);

-- 1) Coluna (criada só se ainda não existir).
SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at');
SET @q = IF(@ok_base = 1 AND @has_col = 0, 'ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Índice (criado só se ainda não existir; CREATE INDEX não é idempotente no 5.7).
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_last_login');
SET @q = IF(@ok_base = 1 AND @has_idx = 0, 'CREATE INDEX `idx_users_last_login` ON `users` (`last_login_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Registra a migração 59 (o código usa a data deste registro como "início do registro de logins").
SET @q = IF(@ok_base = 1, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_27_000059_add_last_login_at_to_users'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_27_000059_add_last_login_at_to_users'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência (resultado esperado: coluna e índice = 1, migração registrada = 1).
SELECT
  CASE WHEN @ok_base = 1 THEN 'OK — base presente' ELSE 'FALHA — faltam tabelas base (users/clinic_members/migrations)' END AS situacao,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at') AS coluna_59,
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_last_login') AS indice_59,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_27_000059_add_last_login_at_to_users') AS migracao_59_registrada;
