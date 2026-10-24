-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-132 · Diário alimentar). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND UPPER(ENGINE) = 'INNODB');
SET @mig = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'migrations');
SET @c_hash = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME = 'request_hash');
SET @c_tz = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME = 'consumed_tz');
SET @i_p = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_patient_consumed');
SET @i_c = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_clinic_consumed');
SELECT
  CASE WHEN @base = 0 OR @mig = 0 THEN 'FALHA — food_diary_entries ausente/fora do InnoDB ou tabela migrations ausente'
       WHEN @c_hash = 1 AND @c_tz = 1 AND @i_p > 0 AND @i_c > 0 THEN 'OK — migração 54 já aplicada'
       WHEN @c_hash + @c_tz + (@i_p > 0) + (@i_c > 0) > 0 THEN 'OK — migração 54 parcial; rodar o script completa o que falta'
       ELSE 'OK — PRONTO PARA MIGRAR' END AS preflight_status,
  (@base = 1 AND @mig = 1) AS pronto_para_migrar,
  @c_hash AS tem_request_hash, @c_tz AS tem_consumed_tz, (@i_p > 0) AS tem_indice_paciente, (@i_c > 0) AS tem_indice_clinica,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_24_000054_harden_food_diary_entries') AS migracao_53_registrada,
  (SELECT COUNT(*) FROM `food_diary_entries`) AS registros_no_diario,
  (SELECT COUNT(*) FROM `food_diary_entries` WHERE `idempotency_key` IS NOT NULL) AS registros_com_chave;
