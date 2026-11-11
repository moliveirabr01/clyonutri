-- ============================================================================
-- ClyoNutri v39: PREFLIGHT (somente leitura) — "Plano e conta" no perfil (migração 60, corrigida)
-- Rode ANTES do atualizacao-v39-completa.sql. Não altera nada. Compatível com MySQL 5.7.
-- Substitui o atualizacao-v37-preflight.sql. Se a v37 falhou com #1215, é este conjunto (v39) que você deve usar.
-- Mostra dois resultados: (1) a situação, em uma linha; (2) engine, collation e tipo da coluna id das tabelas-base
-- (informativo: se algo falhar, copie e mande esse segundo resultado).
-- ============================================================================
SET @db = DATABASE();
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','clinic_subscriptions','saas_plans','migrations')) < 4
      THEN 'FALHA — faltam tabelas base (clinics/clinic_subscriptions/saas_plans/migrations): aplique as atualizações anteriores primeiro'
    WHEN (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_27_000059_add_last_login_at_to_users') = 0
      THEN 'ATENÇÃO — migração 59 (último login) não registrada; aplique antes'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('subscription_plan_changes','account_deletion_requests')) = 2
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests') = 1
      THEN 'OK — migração 60 já aplicada (rodar de novo não muda nada)'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('subscription_plan_changes','account_deletion_requests')) = 2
      THEN 'OK — as duas tabelas já existem; o completa só registra a migração 60'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('subscription_plan_changes','account_deletion_requests')) = 1
      THEN 'OK — ESTADO PARCIAL (só uma das duas tabelas existe): o completa cria a que falta e registra a migração 60'
    ELSE 'OK — PRONTO PARA MIGRAR (migração 60)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_plan_changes') AS tabela_trocas_ja_existe,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'account_deletion_requests') AS tabela_exclusao_ja_existe,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_27_000059_add_last_login_at_to_users') AS migracao_59_registrada,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests') AS migracao_60_registrada,
  (SELECT COUNT(*) FROM `clinic_subscriptions`) AS assinaturas,
  (SELECT COUNT(*) FROM `saas_plans`) AS planos;

-- Informativo: engine, collation e tipo do id das tabelas-base (a v37 falhou por causa desse tipo de diferença).
SELECT t.TABLE_NAME AS tabela, t.ENGINE AS engine, t.TABLE_COLLATION AS collation_da_tabela,
       c.COLUMN_TYPE AS tipo_do_id, c.COLUMN_KEY AS chave_do_id
FROM information_schema.TABLES t
LEFT JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.COLUMN_NAME = 'id'
WHERE t.TABLE_SCHEMA = @db
  AND t.TABLE_NAME IN ('clinics','clinic_subscriptions','saas_plans','subscription_plan_changes','account_deletion_requests')
ORDER BY t.TABLE_NAME;
