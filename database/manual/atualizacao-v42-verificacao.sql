-- ============================================================================
-- ClyoNutri v42: VERIFICAÇÃO (somente leitura) — modelos de plano, métrica de busca e importação de pacientes (migração 62)
-- Rode DEPOIS do atualizacao-v42-completa.sql (ou quando quiser conferir). Não altera nada. Compatível com MySQL 5.7.
-- Resultado esperado: situacao = OK, tabelas = 1, 1 e 1, colunas novas de modelos = 6, migração 62 = 1, colunas 8, 16 e 9,
-- índices únicos 1 e 1.
-- ============================================================================
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('food_search_metrics','patient_import_batches','patient_import_rows')) = 3
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('status','source_plan_id','created_by_user_id','is_clinic_default','archived_at','updated_at')) = 6
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import') = 1
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_metrics') = 8
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_batches') = 16
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_rows') = 9
         AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_metrics' AND INDEX_NAME = 'uq_food_search_metric' AND NON_UNIQUE = 0) = 1
         AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_batches' AND INDEX_NAME = 'uq_patient_import_file' AND NON_UNIQUE = 0) = 1
      THEN 'OK — migração 62 aplicada'
    ELSE 'ATENÇÃO — migração 62 incompleta (rode o preflight e o completa v42)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_metrics') AS tabela_metricas_busca,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_batches') AS tabela_lotes_importacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_rows') AS tabela_linhas_importacao,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('status','source_plan_id','created_by_user_id','is_clinic_default','archived_at','updated_at')) AS colunas_novas_modelos_esperado_6,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import') AS migracao_62_registrada,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_metrics') AS colunas_metricas_esperado_8,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_batches') AS colunas_lotes_esperado_16,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_rows') AS colunas_linhas_esperado_9,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_metrics' AND INDEX_NAME = 'uq_food_search_metric' AND NON_UNIQUE = 0) AS indice_unico_metricas_esperado_1,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_batches' AND INDEX_NAME = 'uq_patient_import_file' AND NON_UNIQUE = 0) AS indice_unico_lotes_esperado_1;
