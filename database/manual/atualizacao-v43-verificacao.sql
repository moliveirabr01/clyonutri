-- ============================================================================
-- ClyoNutri v43: VERIFICAÇÃO (somente leitura), migração 63 (NI2-519, NI2-520)
-- Rode DEPOIS do atualizacao-v43-completa.sql (ou quando quiser conferir). Não altera nada. Compatível com MySQL 5.7.
-- Esperado: situacao = OK, tabelas novas = 3, colunas novas = 16, índices = 6, migração 63 = 1, colunas das tabelas
-- novas 5, 8 e 15, nenhum código TACO com versão repetido.
-- ============================================================================
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('food_favorites','food_search_selections','food_equivalence_rules')) = 3
         AND ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME IN ('source_version','source_code','source_imported_at','preparation','is_current'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source','source_version'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('visibility','approval_status','approved_by_user_id','approved_at','plan_method','target_json'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME IN ('template_id','plan_method','reference_target_json'))) = 16
         AND ((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'uq_foods_source_version_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_source_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND INDEX_NAME = 'idx_plan_templates_visibility') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_favorites' AND INDEX_NAME = 'uq_food_favorites_clinic_food') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_selections' AND INDEX_NAME = 'uq_food_search_selection') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_equivalence_rules' AND INDEX_NAME = 'idx_food_equivalence_rules_clinic')) = 6
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets') = 1
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_favorites') = 5
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_selections') = 8
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_equivalence_rules') = 15
      THEN 'OK: migração 63 aplicada'
    ELSE 'ATENÇÃO: migração 63 incompleta (rode o preflight e o completa v43)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('food_favorites','food_search_selections','food_equivalence_rules')) AS tabelas_novas_esperado_3,
  ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME IN ('source_version','source_code','source_imported_at','preparation','is_current'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source','source_version'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('visibility','approval_status','approved_by_user_id','approved_at','plan_method','target_json'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME IN ('template_id','plan_method','reference_target_json'))) AS colunas_novas_esperado_16,
  ((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'uq_foods_source_version_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_source_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND INDEX_NAME = 'idx_plan_templates_visibility') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_favorites' AND INDEX_NAME = 'uq_food_favorites_clinic_food') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_selections' AND INDEX_NAME = 'uq_food_search_selection') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_equivalence_rules' AND INDEX_NAME = 'idx_food_equivalence_rules_clinic')) AS indices_esperado_6,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets') AS migracao_63_registrada,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_favorites') AS colunas_favoritos_esperado_5,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_selections') AS colunas_selecoes_esperado_8,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_equivalence_rules') AS colunas_regras_esperado_15,
  (SELECT COUNT(*) FROM `foods` WHERE `source` = 'TACO' AND `source_version` = '4ª ed. rev. e ampl. (2011)') AS alimentos_taco_com_versao,
  (SELECT COUNT(*) FROM (SELECT `source_code` FROM `foods` WHERE `source_version` IS NOT NULL GROUP BY `source`, `source_version`, `source_code` HAVING COUNT(*) > 1) d) AS codigos_repetidos_esperado_0;
