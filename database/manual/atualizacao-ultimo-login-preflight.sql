-- ============================================================================
-- ClyoNutri: PREFLIGHT (somente leitura) — "Último login" no backoffice (migração 59)
-- Rode ANTES do atualizacao-ultimo-login-completa.sql. Não altera nada.
-- Compatível com MySQL 5.7.
-- ============================================================================
SET @db = DATABASE();
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('users','clinic_members','migrations')) < 3
      THEN 'FALHA — faltam tabelas base (users/clinic_members/migrations): rode as atualizações anteriores primeiro'
    WHEN (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_26_000058_create_patient_password_resets') = 0
      THEN 'ATENÇÃO — migração 58 (v28) não registrada; aplique a v28 antes'
    WHEN (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at') = 1
      THEN 'OK — users.last_login_at já existe (rodar de novo não muda nada)'
    ELSE 'OK — PRONTO PARA MIGRAR (migração 59)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at') AS coluna_59_ja_existe,
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_last_login') AS indice_59_ja_existe,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_26_000058_create_patient_password_resets') AS migracao_58_registrada,
  (SELECT COUNT(*) FROM `users`) AS usuarios,
  (SELECT COUNT(*) FROM `clinics` WHERE status = 'active') AS clinicas_ativas;
