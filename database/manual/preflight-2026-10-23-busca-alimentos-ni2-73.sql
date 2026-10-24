-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-73 · Busca no catálogo de alimentos). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND UPPER(ENGINE) = 'INNODB');
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'search_text');
SET @m49 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'analytics_daily_metrics');
SELECT
  CASE WHEN @col = 1 THEN 'OK — migração 50 já aplicada'
       WHEN @base = 1 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabela foods ausente ou fora do InnoDB' END AS preflight_status,
  (@base = 1) AS pronto_para_migrar, (@col = 1) AS migracao_ja_aplicada, @m49 AS migracao_49_aplicada,
  (SELECT COUNT(*) FROM `foods`) AS alimentos,
  (SELECT COUNT(*) FROM `foods` WHERE `taco_code` IS NOT NULL) AS alimentos_taco,
  (SELECT COUNT(*) FROM `foods` WHERE `clinic_id` IS NOT NULL) AS alimentos_das_clinicas,
  (SELECT MAX(CHAR_LENGTH(`name`)) FROM `foods`) AS maior_nome;
