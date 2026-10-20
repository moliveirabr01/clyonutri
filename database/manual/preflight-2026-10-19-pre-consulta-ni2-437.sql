-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-437 · Pré-consulta). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','appointments','consent_terms','patient_consents','audit_logs') AND UPPER(ENGINE) = 'INNODB');
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'preconsultation_questionnaires');
SET @m44 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skinfold_measurements');
SELECT
  CASE WHEN @exists = 1 THEN 'OK — migração 45 já aplicada'
       WHEN @base = 7 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB' END AS preflight_status,
  (@base = 7) AS pronto_para_migrar, @exists AS migracao_ja_aplicada, @m44 AS migracao_44_aplicada;
