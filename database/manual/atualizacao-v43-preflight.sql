-- ============================================================================
-- ClyoNutri v43: PREFLIGHT (somente leitura), migração 63 (NI2-519, NI2-520)
-- Rode ANTES do atualizacao-v43-completa.sql. Não altera nada. Compatível com MySQL 5.7.
-- Mostra dois resultados: (1) a situação, em uma linha; (2) números TACO repetidos (informativo: a linha de menor id
-- recebe a versão; as outras ficam sem versão e continuam funcionando).
-- ============================================================================
SET @db = DATABASE();
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('foods','food_household_measures','meal_plan_templates','meal_plans','migrations')) < 5
      THEN 'FALHA: faltam tabelas base (foods, food_household_measures, meal_plan_templates, meal_plans, migrations); aplique as atualizações anteriores primeiro'
    WHEN (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import') = 0
      THEN 'ATENÇÃO: migração 62 (v42) ainda não registrada; rode o atualizacao-v42-completa.sql antes'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('food_favorites','food_search_selections','food_equivalence_rules')) = 3
         AND ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME IN ('source_version','source_code','source_imported_at','preparation','is_current'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source','source_version'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('visibility','approval_status','approved_by_user_id','approved_at','plan_method','target_json'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME IN ('template_id','plan_method','reference_target_json'))) = 16
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets') = 1
      THEN 'OK: migração 63 já aplicada (rodar de novo não muda nada)'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('food_favorites','food_search_selections','food_equivalence_rules')) > 0
         OR ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME IN ('source_version','source_code','source_imported_at','preparation','is_current'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source','source_version'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('visibility','approval_status','approved_by_user_id','approved_at','plan_method','target_json'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME IN ('template_id','plan_method','reference_target_json'))) > 0
      THEN 'OK: ESTADO PARCIAL (parte da 63 já existe); o completa cria só o que falta'
    ELSE 'OK: PRONTO PARA MIGRAR (migração 63)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('food_favorites','food_search_selections','food_equivalence_rules')) AS tabelas_novas_ja_existem_de_3,
  ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME IN ('source_version','source_code','source_imported_at','preparation','is_current'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source','source_version'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('visibility','approval_status','approved_by_user_id','approved_at','plan_method','target_json'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME IN ('template_id','plan_method','reference_target_json'))) AS colunas_novas_ja_existem_de_16,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import') AS migracao_62_registrada,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets') AS migracao_63_registrada,
  (SELECT COUNT(*) FROM `foods` WHERE `source` = 'TACO' AND `taco_code` IS NOT NULL AND `clinic_id` IS NULL) AS alimentos_taco_com_numero,
  (SELECT COUNT(*) FROM `meal_plan_templates`) AS modelos_existentes;

-- Informativo: números TACO com mais de uma linha global.
SELECT `taco_code` AS numero_taco, COUNT(*) AS linhas, MIN(`id`) AS id_que_recebe_a_versao
FROM `foods` WHERE `source` = 'TACO' AND `taco_code` IS NOT NULL AND `clinic_id` IS NULL
GROUP BY `taco_code` HAVING COUNT(*) > 1 ORDER BY `taco_code`;
