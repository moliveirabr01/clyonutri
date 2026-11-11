-- ============================================================================
-- ClyoNutri v39: VERIFICAÇÃO (somente leitura) — "Plano e conta" no perfil (migração 60, corrigida)
-- Rode DEPOIS do atualizacao-v39-completa.sql (ou quando quiser conferir). Não altera nada.
-- Compatível com MySQL 5.7.
-- Resultado esperado: situacao = OK, as duas tabelas = 1, migração 60 = 1, colunas 18 e 22, índices únicos 2 e 2.
-- ============================================================================
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('subscription_plan_changes','account_deletion_requests')) = 2
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests') = 1
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plan_changes') = 18
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_deletion_requests') = 22
         AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plan_changes' AND INDEX_NAME IN ('uq_plan_changes_protocol','uq_plan_changes_idem')) = 2
         AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_deletion_requests' AND INDEX_NAME IN ('uq_account_deletion_protocol','uq_account_deletion_open')) = 2
      THEN 'OK — migração 60 aplicada'
    ELSE 'ATENÇÃO — migração 60 incompleta (rode o preflight e o completa v39)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plan_changes') AS tabela_trocas_de_plano,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_deletion_requests') AS tabela_pedidos_de_exclusao,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests') AS migracao_60_registrada,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plan_changes') AS colunas_trocas_esperado_18,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_deletion_requests') AS colunas_exclusao_esperado_22,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plan_changes' AND INDEX_NAME IN ('uq_plan_changes_protocol','uq_plan_changes_idem')) AS indices_unicos_trocas_esperado_2,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_deletion_requests' AND INDEX_NAME IN ('uq_account_deletion_protocol','uq_account_deletion_open')) AS indices_unicos_exclusao_esperado_2;
