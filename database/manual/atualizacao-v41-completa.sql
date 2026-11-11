-- ============================================================================
-- ClyoNutri v41: ATUALIZAÇÃO DO BANCO — meta de energia, medidas caseiras da clínica e plano sem paciente (migração 61)
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do atualizacao-v41-preflight.sql, e ANTES de subir os arquivos novos.
-- Se a v39 (migração 60) ainda não foi aplicada, rode primeiro o atualizacao-v39-completa.sql: esta atualização não depende
-- dela, mas a ordem das migrações fica certa.
-- Idempotente (pode rodar de novo sem efeito) e sem chave estrangeira nas tabelas novas, pelo mesmo motivo da v39 (#1215):
-- em produção os id são bigint(20), então as colunas de id novas também são bigint(20) e o app valida clínica, plano e alimento em PHP.
--   61 · meal_plan_energy_targets   (TMB/GET/VET e metas de macros de cada plano, com fórmula e dados de entrada)
--      · clinic_household_measures  (medidas caseiras da clínica, com fonte obrigatória; começa vazia)
--      · meal_plans.patient_id passa a aceitar vazio (prescrição sem paciente, atribuída depois)
-- Não apaga nem altera nenhum dado: cria duas tabelas vazias e retira só a obrigatoriedade de uma coluna. MySQL 5.7.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `meal_plan_energy_targets` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `meal_plan_id` BIGINT(20) NOT NULL,
  `patient_id` BIGINT(20) NULL,
  `formula` VARCHAR(40) NOT NULL,
  `formula_version` VARCHAR(20) NOT NULL,
  `sex` VARCHAR(10) NULL,
  `age_years` DECIMAL(5,1) NULL,
  `weight_kg` DECIMAL(6,2) NOT NULL,
  `height_cm` DECIMAL(5,1) NULL,
  `ffm_kg` DECIMAL(6,2) NULL,
  `inputs_source` VARCHAR(255) NULL,
  `activity_factor` DECIMAL(4,2) NOT NULL,
  `activity_label` VARCHAR(80) NULL,
  `goal_type` VARCHAR(20) NOT NULL DEFAULT 'maintenance',
  `goal_note` VARCHAR(255) NULL,
  `adjust_mode` VARCHAR(10) NOT NULL DEFAULT 'kcal',
  `adjust_value` DECIMAL(7,2) NOT NULL DEFAULT 0,
  `tmb_kcal` DECIMAL(7,1) NOT NULL,
  `get_kcal` DECIMAL(7,1) NOT NULL,
  `vet_kcal` DECIMAL(7,1) NOT NULL,
  `macro_mode` VARCHAR(10) NOT NULL,
  `protein_g` DECIMAL(7,1) NOT NULL,
  `carbohydrate_g` DECIMAL(7,1) NOT NULL,
  `lipid_g` DECIMAL(7,1) NOT NULL,
  `protein_pct` DECIMAL(5,1) NOT NULL,
  `carbohydrate_pct` DECIMAL(5,1) NOT NULL,
  `lipid_pct` DECIMAL(5,1) NOT NULL,
  `protein_g_kg` DECIMAL(5,2) NULL,
  `carbohydrate_g_kg` DECIMAL(5,2) NULL,
  `lipid_g_kg` DECIMAL(5,2) NULL,
  `warnings_json` TEXT NULL,
  `calculated_by_user_id` BIGINT(20) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_energy_targets_plan` (`meal_plan_id`),
  KEY `idx_energy_targets_clinic` (`clinic_id`, `patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clinic_household_measures` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `food_id` BIGINT(20) NOT NULL,
  `measure_name` VARCHAR(100) NOT NULL,
  `grams_equivalent` DECIMAL(7,2) NOT NULL,
  `source` VARCHAR(255) NOT NULL,
  `source_ref` VARCHAR(255) NULL,
  `created_by_user_id` BIGINT(20) NULL,
  `updated_by_user_id` BIGINT(20) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_clinic_measure` (`clinic_id`, `food_id`, `measure_name`),
  KEY `idx_clinic_measure_food` (`food_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- meal_plans.patient_id passa a aceitar vazio. Mantém exatamente o tipo que a coluna já tem (bigint(20) em produção), então a
-- chave estrangeira existente continua válida. Só altera se a coluna ainda for obrigatória; se já aceita vazio, não faz nada.
SET @alter_patient = (
  SELECT IF(IS_NULLABLE = 'NO', CONCAT('ALTER TABLE `meal_plans` MODIFY `patient_id` ', COLUMN_TYPE, ' NULL'), 'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id'
);
SET @alter_patient = IFNULL(@alter_patient, 'SELECT 1');
PREPARE stmt_patient FROM @alter_patient;
EXECUTE stmt_patient;
DEALLOCATE PREPARE stmt_patient;

-- Registra a migração 61 (para o instalador não tentar rodá-la de novo), só se tudo acima está no lugar.
INSERT INTO `migrations` (`migration`, `executed_at`)
SELECT '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans', CURRENT_TIMESTAMP FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans')
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('meal_plan_energy_targets','clinic_household_measures')) = 2
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id' AND IS_NULLABLE = 'YES') = 1;

-- Conferência (resultado esperado: tabelas = 1 e 1, patient_id_aceita_vazio = 1, migração 61 registrada = 1).
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_energy_targets') AS tabela_metas_de_energia,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clinic_household_measures') AS tabela_medidas_da_clinica,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'patient_id' AND IS_NULLABLE = 'YES') AS patient_id_aceita_vazio,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000061_plan_energy_clinic_measures_and_planless_plans') AS migracao_61_registrada;
