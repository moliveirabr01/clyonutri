-- ============================================================================
-- ClyoNutri v41: VERIFICAÇÃO (somente leitura) — meta de energia, medidas da clínica e plano sem paciente (migração 61)
-- Rode DEPOIS do atualizacao-v41-completa.sql (ou quando quiser conferir). Não altera nada. Compatível com MySQL 5.7.
-- Resultado esperado: situacao = OK, tabelas = 1 e 1, patient_id aceita vazio = 1, migração 61 = 1, colunas 35 e 11, índices únicos 1 e 1.
-- ============================================================================
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('meal_plan_energy_targets','clinic_household_measures')) = 2
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id' AND IS_NULLABLE = 'YES') = 1
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans') = 1
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_energy_targets') = 35
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinic_household_measures') = 11
         AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_energy_targets' AND INDEX_NAME = 'uq_energy_targets_plan' AND NON_UNIQUE = 0) = 1
         AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinic_household_measures' AND INDEX_NAME = 'uq_clinic_measure' AND NON_UNIQUE = 0) = 1
      THEN 'OK — migração 61 aplicada'
    ELSE 'ATENÇÃO — migração 61 incompleta (rode o preflight e o completa v41)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_energy_targets') AS tabela_metas_de_energia,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinic_household_measures') AS tabela_medidas_da_clinica,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id' AND IS_NULLABLE = 'YES') AS patient_id_aceita_vazio,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans') AS migracao_61_registrada,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_energy_targets') AS colunas_metas_esperado_35,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinic_household_measures') AS colunas_medidas_esperado_11,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_energy_targets' AND INDEX_NAME = 'uq_energy_targets_plan' AND NON_UNIQUE = 0) AS indice_unico_metas_esperado_1,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinic_household_measures' AND INDEX_NAME = 'uq_clinic_measure' AND NON_UNIQUE = 0) AS indice_unico_medidas_esperado_1;
