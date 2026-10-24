-- ============================================================================
-- ClyoNutri — Migração Manual 50 (NI2-73 · Busca eficiente no catálogo de alimentos)
-- Equivale a database/migrations/2026_10_23_000050_add_food_search_text.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-23-busca-alimentos-ni2-73.sql)
-- e da migração 49. Idempotente.
-- Efeito nos dados: foods ganha a coluna search_text (nome sem acento, para a busca por termos) e dois índices
-- (search_text e name).
-- Nenhum alimento muda. O próprio sistema preenche search_text na primeira busca depois do deploy
-- (e preenche sozinho qualquer alimento novo, inclusive os do SQL de importação da TACO).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND UPPER(ENGINE) = 'INNODB') = 1);

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'search_text');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `foods` ADD COLUMN `search_text` VARCHAR(255) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_search_text');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_foods_search_text` ON `foods` (`search_text`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_name');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_foods_name` ON `foods` (`name`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'search_text') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_search_text') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_name') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_23_000050_add_food_search_text'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_23_000050_add_food_search_text'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 50 aplicada (busca de alimentos por termos)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `foods`) AS alimentos,
  (SELECT COUNT(*) FROM `foods` WHERE `search_text` IS NULL) AS a_indexar_na_primeira_busca;
