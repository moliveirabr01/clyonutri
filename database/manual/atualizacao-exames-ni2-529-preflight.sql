-- ============================================================================
-- v46: substituído por atualizacao-v46-preflight.sql e atualizacao-v46-verificacao.sql (migrações 64 e 65). Use aqueles.
-- ClyoNutri: PREFLIGHT (somente leitura), migração 64 (NI2-529, NI2-530 a NI2-533)
-- Solicitação de Exames Laboratoriais pelo Nutricionista.
-- Rode ANTES do atualizacao-exames-ni2-529-completa.sql. Não altera nada. MySQL 5.7+.
-- ============================================================================
SET @db = DATABASE();

SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','users','patients','professionals','migrations')) < 5
      THEN 'FALHA: faltam tabelas base do sistema; aplique as migrações anteriores primeiro'
    WHEN (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets') = 0
      THEN 'ATENÇÃO: migração 63 (v43) ainda não registrada; execute a v43 antes'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) = 5
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000064_create_laboratory_exam_requests_and_catalog') = 1
      THEN 'OK: migração 64 já aplicada (rodar de novo não altera nada)'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) > 0
      THEN 'OK: ESTADO PARCIAL (algumas tabelas de exames já existem); o script completa criará apenas o que falta'
    ELSE 'OK: PRONTO PARA MIGRAR (migração 64: Módulo de Exames Laboratoriais)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('exam_regulatory_policies','exam_catalog','exam_requests','exam_request_items','exam_audit_logs')) AS tabelas_exames_existentes_de_5,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets') AS migracao_63_registrada,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000064_create_laboratory_exam_requests_and_catalog') AS migracao_64_registrada;
