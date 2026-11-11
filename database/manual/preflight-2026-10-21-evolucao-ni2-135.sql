-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-135 · Evolução, autorrelatos e metas). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET SESSION group_concat_max_len = 1048576; -- MySQL 5.7 corta GROUP_CONCAT em 1024 caracteres por padrão
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','patient_self_metrics','anthropometric_assessments','audit_logs') AND UPPER(ENGINE) = 'INNODB');
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('patient_self_metric_permissions','patient_goals'));
SET @m45 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'preconsultation_questionnaires');
SELECT
  CASE WHEN @exists = 2 THEN 'OK — migração 46 já aplicada'
       WHEN @base = 6 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB' END AS preflight_status,
  (@base = 6) AS pronto_para_migrar, (@exists = 2) AS migracao_ja_aplicada, @m45 AS migracao_45_aplicada,
  (SELECT COUNT(*) FROM `patient_self_metrics`) AS autorrelatos_existentes,
  (SELECT GROUP_CONCAT(DISTINCT `metric_type`) FROM `patient_self_metrics`) AS tipos_de_autorrelato_existentes,
  (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id') AS tipo_das_chaves;
