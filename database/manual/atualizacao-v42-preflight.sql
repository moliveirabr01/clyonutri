-- ============================================================================
-- ClyoNutri v42: PREFLIGHT (somente leitura) — modelos de plano, métrica de busca e importação de pacientes (migração 62)
-- Rode ANTES do atualizacao-v42-completa.sql. Não altera nada. Compatível com MySQL 5.7.
-- Mostra dois resultados: (1) a situação, em uma linha; (2) as colunas atuais de meal_plan_templates (informativo:
-- se algo falhar, copie e mande esse segundo resultado).
-- ============================================================================
SET @db = DATABASE();
SELECT
  CASE
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('meal_plan_templates','patients','foods','clinics','migrations')) < 5
      THEN 'FALHA — faltam tabelas base (meal_plan_templates/patients/foods/clinics/migrations): aplique as atualizações anteriores primeiro'
    WHEN (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans') = 0
      THEN 'ATENÇÃO — migração 61 (v41) ainda não registrada: rode o atualizacao-v41-completa.sql antes (e o da v39, se a 60 também faltar)'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('food_search_metrics','patient_import_batches','patient_import_rows')) = 3
         AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('status','source_plan_id','created_by_user_id','is_clinic_default','archived_at','updated_at')) = 6
         AND (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import') = 1
      THEN 'OK — migração 62 já aplicada (rodar de novo não muda nada)'
    WHEN (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('food_search_metrics','patient_import_batches','patient_import_rows')) > 0
         OR (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('status','source_plan_id','created_by_user_id','is_clinic_default','archived_at','updated_at')) > 0
      THEN 'OK — ESTADO PARCIAL (parte da 62 já existe): o completa cria só o que falta'
    ELSE 'OK — PRONTO PARA MIGRAR (migração 62)'
  END AS situacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_search_metrics') AS tabela_metricas_busca_ja_existe,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_import_batches') AS tabela_lotes_importacao_ja_existe,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_import_rows') AS tabela_linhas_importacao_ja_existe,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('status','source_plan_id','created_by_user_id','is_clinic_default','archived_at','updated_at')) AS colunas_novas_modelos_ja_existem_de_6,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans') AS migracao_61_registrada,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import') AS migracao_62_registrada,
  (SELECT COUNT(*) FROM `meal_plan_templates`) AS modelos_existentes;

-- Informativo: colunas atuais de meal_plan_templates (a 62 só acrescenta colunas; nenhuma existente muda).
SELECT c.COLUMN_NAME AS coluna, c.COLUMN_TYPE AS tipo, c.IS_NULLABLE AS aceita_vazio
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = @db AND c.TABLE_NAME = 'meal_plan_templates'
ORDER BY c.ORDINAL_POSITION;
