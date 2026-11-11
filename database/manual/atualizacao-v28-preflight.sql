-- ============================================================================
-- ClyoNutri v28: PREFLIGHT (somente leitura) — rode ANTES do atualizacao-v28-completa.sql
-- Não altera nada. Serve para confirmar que o banco está pronto para a migração 58 (NI2-481).
-- Compatível com MySQL 5.7.
-- ============================================================================
SET @db = DATABASE();
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('patients','clinics','migrations')) < 3
      THEN 'FALHA — faltam tabelas base (patients/clinics/migrations): rode as atualizações anteriores primeiro'
    WHEN (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_24_000055_update_help_center_copy_v21') = 0
      THEN 'ATENÇÃO — migração 55 (v24) não registrada; aplique a v24/v27 antes'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_password_resets') = 1
      THEN 'OK — patient_password_resets já existe (a v28 já foi aplicada; rodar de novo não muda nada)'
    ELSE 'OK — PRONTO PARA MIGRAR (v28 / migração 58)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_password_resets') AS tabela_58_ja_existe,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_26_000057_add_trial_ends_at_to_clinic_subscriptions') AS migracao_57_registrada,
  (SELECT COUNT(*) FROM `patients`) AS pacientes,
  (SELECT COUNT(*) FROM `clinics` WHERE status = 'active') AS clinicas_ativas;
