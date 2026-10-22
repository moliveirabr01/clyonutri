-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-92 · Hidratação). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('hydration_logs','patients','clinics','audit_logs') AND UPPER(ENGINE) = 'INNODB');
SET @cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
  AND COLUMN_NAME IN ('idempotency_key','source','updated_at','deleted_at'));
SET @idx = (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
  AND INDEX_NAME IN ('uq_hydration_operation','idx_hydration_patient_day'));
SET @m47 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_change_requests');
SELECT
  CASE WHEN @cols = 4 AND @idx = 2 THEN 'OK — migração 50 já aplicada'
       WHEN @base = 4 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB' END AS preflight_status,
  (@base = 4) AS pronto_para_migrar, (@cols = 4 AND @idx = 2) AS migracao_ja_aplicada, @m47 AS migracao_47_aplicada,
  (SELECT COUNT(*) FROM `hydration_logs`) AS registros_de_agua,
  (SELECT COUNT(*) FROM `hydration_logs` WHERE `amount_ml` < 1 OR `amount_ml` > 5000) AS registros_fora_de_1_a_5000_ml,
  (SELECT COUNT(*) FROM `hydration_logs` WHERE `logged_at` > NOW() + INTERVAL 5 MINUTE) AS registros_no_futuro,
  (SELECT COUNT(*) FROM (SELECT `patient_id`, DATE(`logged_at`) AS d FROM `hydration_logs` GROUP BY `patient_id`, DATE(`logged_at`) HAVING SUM(`amount_ml`) > 10000) x) AS dias_acima_de_10_litros,
  @@session.time_zone AS fuso_da_sessao;
