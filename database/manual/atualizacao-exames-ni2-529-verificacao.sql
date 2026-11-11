-- ============================================================================
-- v46: substituído por atualizacao-v46-preflight.sql e atualizacao-v46-verificacao.sql (migrações 64 e 65). Use aqueles.
-- ClyoNutri: VERIFICAÇÃO PÓS-MIGRAÇÃO 64 (somente leitura) — Módulo de Exames (NI2-529).
-- Rode APÓS o atualizacao-exames-ni2-529-completa.sql no phpMyAdmin.
-- ============================================================================
SET @db = DATABASE();

SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) = 5
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000064_create_laboratory_exam_requests_and_catalog') = 1
         AND (SELECT COUNT(*) FROM `exam_regulatory_policies` WHERE `policy_version` = '2026.1') >= 1
      THEN 'SUCESSO: migração 64 aplicada e verificada com sucesso!'
    ELSE 'FALHA: confira se todas as 5 tabelas e a política v2026.1 foram criadas.'
  END AS resultado_verificacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) AS tabelas_criadas_de_5,
  (SELECT COUNT(*) FROM `exam_regulatory_policies`) AS politicas_regulatórias_ativas,
  (SELECT COUNT(*) FROM `exam_catalog`) AS exames_no_catalogo;
