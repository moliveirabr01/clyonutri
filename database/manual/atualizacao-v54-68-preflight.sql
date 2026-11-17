-- ==============================================================================
-- ClyoNutri v54.68 · PREFLIGHT (SOMENTE LEITURA)
-- Rode ANTES de atualizacao-v54-68-completa.sql, no phpMyAdmin, com o banco de produção selecionado.
-- Não altera nada. Compatível com MySQL 5.7.44.
-- ==============================================================================
SET NAMES utf8mb4;

-- P1. Versão e banco selecionado (esperado: 5.7.x e o banco do ClyoNutri)
SELECT VERSION() AS versao_mysql, DATABASE() AS banco;

-- P2. Últimas migrations (esperado: a 095 registrada e a 096 ausente). Se a 095 faltar, PARE:
--     aplique antes a atualização da v54.63 (database/manual/atualizacao-v54-63-completa.sql).
SELECT migration FROM migrations WHERE migration >= '2026_11_13_000093' ORDER BY migration;

-- P3. Tabelas que recebem colunas (esperado: 6)
SET @t := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('consultation_ai_consent_requests','users','anamneses','anthropometric_assessments','meal_plans','meal_plan_versions'));
SELECT @t AS tabelas_presentes;

-- P4. Colunas da 096 já existentes (esperado: 0; se vier 14, a 096 já foi aplicada e a atualização só registra)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (
     (TABLE_NAME = 'consultation_ai_consent_requests' AND COLUMN_NAME IN ('otp_hash','otp_expires_at_utc','otp_attempts','requested_ip_hash','same_ip_alert'))
  OR (TABLE_NAME = 'users' AND COLUMN_NAME IN ('mfa_enabled','mfa_secret_enc','mfa_recovery_codes_json','mfa_enrolled_at','mfa_last_step'))
  OR (TABLE_NAME IN ('anamneses','anthropometric_assessments','meal_plans','meal_plan_versions') AND COLUMN_NAME = 'appointment_id')));
SELECT @c AS colunas_096_existentes;

-- P5. Usuários de plataforma que vão precisar cadastrar MFA no próximo acesso ao /admin (anote)
SELECT role, COUNT(*) AS usuarios FROM users WHERE role IN ('superadmin','operator','support_agent','security_auditor') GROUP BY role;
