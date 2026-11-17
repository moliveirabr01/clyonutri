-- ==============================================================================
-- ClyoNutri v54.68 · VERIFICAÇÃO (SOMENTE LEITURA) — rode depois de atualizacao-v54-68-completa.sql
-- ==============================================================================
SET NAMES utf8mb4;

-- V1. Colunas da 096 (esperado: 14)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (
     (TABLE_NAME = 'consultation_ai_consent_requests' AND COLUMN_NAME IN ('otp_hash','otp_expires_at_utc','otp_attempts','requested_ip_hash','same_ip_alert'))
  OR (TABLE_NAME = 'users' AND COLUMN_NAME IN ('mfa_enabled','mfa_secret_enc','mfa_recovery_codes_json','mfa_enrolled_at','mfa_last_step'))
  OR (TABLE_NAME IN ('anamneses','anthropometric_assessments','meal_plans','meal_plan_versions') AND COLUMN_NAME = 'appointment_id')));
SELECT @c AS colunas_096;

-- V2. Migration registrada (esperado: 1)
SELECT COUNT(*) AS migration_096 FROM migrations WHERE migration = '2026_11_15_000096_ni2_856_security_clinical_core';

-- V3. Nenhum MFA ativado ainda e nenhum atendimento ligado (esperado: 0 e 0 logo após a atualização)
SELECT (SELECT COUNT(*) FROM users WHERE mfa_enabled = 1) AS mfa_ativos,
       (SELECT COUNT(*) FROM meal_plans WHERE appointment_id IS NOT NULL) AS planos_com_atendimento;
