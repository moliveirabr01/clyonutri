-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-96 · Check-in diário e métricas de adesão). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','checkins','hydration_logs','platform_settings') AND UPPER(ENGINE) = 'INNODB');
SET @tabs = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('analytics_daily_metrics','analytics_daily_subjects','analytics_daily_salts'));
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'checkins' AND COLUMN_NAME = 'updated_at');
SET @m48 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_professional_links');
SELECT
  CASE WHEN @tabs = 3 AND @col = 1 THEN 'OK — migração 49 já aplicada'
       WHEN @base = 5 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB' END AS preflight_status,
  (@base = 5) AS pronto_para_migrar, (@tabs = 3 AND @col = 1) AS migracao_ja_aplicada, @m48 AS migracao_48_aplicada,
  (SELECT COUNT(*) FROM `checkins`) AS checkins_existentes,
  (SELECT COUNT(*) FROM (SELECT 1 FROM `checkins` GROUP BY `clinic_id`, `patient_id`, `checkin_date` HAVING COUNT(*) > 1) d) AS dias_com_mais_de_um_checkin,
  (SELECT COUNT(*) FROM `patients` WHERE `status` = 'active' AND `password_hash` IS NOT NULL AND `password_hash` <> '') AS pacientes_com_app,
  (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id') AS tipo_das_chaves;
