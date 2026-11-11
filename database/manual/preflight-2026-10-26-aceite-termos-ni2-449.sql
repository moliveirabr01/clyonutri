-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-449 · Aceite dos Termos e da Política). NÃO altera dados.
-- Rodar antes de migracao-2026-10-26-aceite-termos-ni2-449.sql. Compatível com MySQL 5.7.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('users', 'migrations', 'patient_consents', 'consent_terms'));
SET @tab = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances');
SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_legal_acceptances' AND INDEX_NAME = 'idx_user_legal_acceptances_lookup');
SET @m55 = 0;
SET @m56 = 0;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'migrations') = 1,
  'SELECT SUM(`migration` = ''2026_10_24_000055_update_help_center_copy_v21''), SUM(`migration` = ''2026_10_26_000056_create_user_legal_acceptances'') INTO @m55, @m56 FROM `migrations`', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SELECT CAST(
  CASE WHEN @base < 4 THEN 'FALHA — faltam tabelas base (users, migrations, patient_consents ou consent_terms)'
       WHEN COALESCE(@m55, 0) = 0 THEN 'ATENÇÃO — a migração 55 não está registrada; aplique a v24 antes desta'
       WHEN @tab = 1 AND @idx > 0 AND COALESCE(@m56, 0) > 0 THEN 'OK — migração 56 já aplicada'
       WHEN @tab = 1 THEN 'OK — migração 56 parcial; rodar o script completa o que falta'
       ELSE 'OK — PRONTO PARA MIGRAR' END AS CHAR(120) CHARACTER SET utf8mb4) AS preflight_status,
  @tab AS tem_tabela_aceites, (@idx > 0) AS tem_indice, CAST(COALESCE(@m56, 0) AS UNSIGNED) AS migracao_56_registrada,
  (SELECT COUNT(*) FROM `users`) AS usuarios_cadastrados,
  (SELECT COUNT(*) FROM `consent_terms` WHERE `purpose_code` IN ('platform_terms', 'platform_privacy')) AS termos_de_paciente_ja_criados;
