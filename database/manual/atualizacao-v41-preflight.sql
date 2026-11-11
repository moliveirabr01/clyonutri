-- ============================================================================
-- ClyoNutri v41: PREFLIGHT (somente leitura) — meta de energia, medidas da clínica e plano sem paciente (migração 61)
-- Rode ANTES do atualizacao-v41-completa.sql. Não altera nada. Compatível com MySQL 5.7.
-- Mostra dois resultados: (1) a situação, em uma linha; (2) tipo da coluna meal_plans.patient_id e das tabelas-base
-- (informativo: se algo falhar, copie e mande esse segundo resultado).
-- ============================================================================
SET @db = DATABASE();
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('meal_plans','foods','clinics','migrations')) < 4
      THEN 'FALHA — faltam tabelas base (meal_plans/foods/clinics/migrations): aplique as atualizações anteriores primeiro'
    WHEN (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests') = 0
      THEN 'ATENÇÃO — migração 60 (v39) ainda não registrada: rode o atualizacao-v39-completa.sql antes, só para manter a ordem'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('meal_plan_energy_targets','clinic_household_measures')) = 2
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id' AND IS_NULLABLE = 'YES') = 1
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans') = 1
      THEN 'OK — migração 61 já aplicada (rodar de novo não muda nada)'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('meal_plan_energy_targets','clinic_household_measures')) = 2
      THEN 'OK — as duas tabelas já existem; o completa confere patient_id e registra a migração 61'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('meal_plan_energy_targets','clinic_household_measures')) = 1
      THEN 'OK — ESTADO PARCIAL (só uma das duas tabelas existe): o completa cria a que falta'
    ELSE 'OK — PRONTO PARA MIGRAR (migração 61)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_energy_targets') AS tabela_metas_ja_existe,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinic_household_measures') AS tabela_medidas_ja_existe,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id' AND IS_NULLABLE = 'YES') AS patient_id_ja_aceita_vazio,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests') AS migracao_60_registrada,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans') AS migracao_61_registrada,
  (SELECT COUNT(*) FROM `meal_plans`) AS planos_existentes;

-- Informativo: tipo da coluna patient_id e do id das tabelas-base (a v37 falhou por diferença de tipo).
SELECT c.TABLE_NAME AS tabela, c.COLUMN_NAME AS coluna, c.COLUMN_TYPE AS tipo, c.IS_NULLABLE AS aceita_vazio
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = @db
  AND ((c.TABLE_NAME = 'meal_plans' AND c.COLUMN_NAME IN ('id','clinic_id','patient_id'))
    OR (c.TABLE_NAME IN ('foods','clinics') AND c.COLUMN_NAME = 'id'))
ORDER BY c.TABLE_NAME, c.COLUMN_NAME;
