-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (code review NI2-9 · Plano alimentar e prescrição)
-- Execute no phpMyAdmin / MySQL CLI ANTES de migracao-2026-10-19-planos-ni2-9.sql.
-- NÃO altera dados (zero DDL, zero UPDATE/DELETE/INSERT).
-- Verifica as tabelas de plano e se já existem números de versão repetidos por paciente
-- (o que impediria o índice único uq_meal_plan_versions_number).
-- ============================================================================
SET NAMES utf8mb4;
SET SESSION group_concat_max_len = 1048576; -- MySQL 5.7 corta GROUP_CONCAT em 1024 caracteres por padrão
SET @db = DATABASE();

SET @has_plans = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plans');
SET @has_items = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items');
SET @has_subs = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions');
SET @has_versions = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions');
SET @has_measures = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_household_measures');

SET @dup_versions = 0;
SET @q = IF(@has_versions = 1, 'SELECT COUNT(*) FROM (SELECT clinic_id, patient_id, version_number FROM `meal_plan_versions` GROUP BY clinic_id, patient_id, version_number HAVING COUNT(*) > 1) d INTO @dup_versions', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @already = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME = 'weight_grams');

SELECT
  CASE
    WHEN @has_plans = 1 AND @has_items = 1 AND @has_subs = 1 AND @has_versions = 1 AND @has_measures = 1 AND @dup_versions = 0
      THEN 'OK — PRONTO PARA MIGRAR'
    WHEN @dup_versions > 0
      THEN 'ATENÇÃO — há números de versão repetidos; a migração adiciona as colunas mas NÃO cria o índice único. Veja a consulta abaixo.'
    ELSE 'FALHA — tabelas de plano ausentes'
  END AS preflight_status,
  (@has_plans = 1 AND @has_items = 1 AND @has_subs = 1 AND @has_versions = 1 AND @has_measures = 1) AS pronto_para_migrar,
  @dup_versions AS versoes_repetidas,
  @already AS migracao_ja_aplicada;

-- Detalhe das versões repetidas (se houver). Corrija manualmente, com backup, antes de criar o índice.
SET @q = IF(@has_versions = 1, 'SELECT clinic_id, patient_id, version_number, COUNT(*) AS qtd, GROUP_CONCAT(id ORDER BY id) AS ids FROM `meal_plan_versions` GROUP BY clinic_id, patient_id, version_number HAVING COUNT(*) > 1', 'SELECT ''sem tabela meal_plan_versions'' AS info');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
