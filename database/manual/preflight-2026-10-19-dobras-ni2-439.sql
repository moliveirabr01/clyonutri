-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-439 · Dobras cutâneas)
-- Execute ANTES de migracao-2026-10-19-dobras-ni2-439.sql. NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();

SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','patients','professionals','anthropometric_assessments'));
SET @innodb = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','patients','professionals','anthropometric_assessments') AND UPPER(ENGINE) = 'INNODB');
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skinfold_measurements');
SET @m43 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME = 'weight_grams');

SELECT
  CASE
    WHEN @exists = 1 THEN 'OK — tabela já existe (migração 44 já aplicada)'
    WHEN @base = 4 AND @innodb = 4 THEN 'OK — PRONTO PARA MIGRAR'
    ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB'
  END AS preflight_status,
  (@base = 4 AND @innodb = 4) AS pronto_para_migrar,
  @exists AS migracao_ja_aplicada,
  @m43 AS migracao_43_aplicada;
