-- ============================================================================
-- ClyoNutri v42: ATUALIZAÇÃO DO BANCO — modelos de plano, métrica de busca e importação de pacientes (migração 62)
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do atualizacao-v42-preflight.sql, e ANTES de subir os arquivos novos.
-- Ordem: v39 (migração 60) -> v41 (migração 61) -> esta (62). Esta atualização não depende das anteriores, mas a ordem das
-- migrações fica certa.
-- Idempotente (pode rodar de novo sem efeito) e sem chave estrangeira nas tabelas novas, pelo mesmo motivo da v39 (#1215):
-- as colunas de id novas são bigint(20) e o app valida clínica, lote e paciente em PHP.
--   62 · meal_plan_templates ganha 6 colunas (status, source_plan_id, created_by_user_id, is_clinic_default, archived_at,
--        updated_at) e o índice idx_plan_templates_clinic_status. As linhas existentes ficam com status 'active'.
--      · food_search_metrics     (buscas por dia e por termo, por clínica; sem dado de paciente; começa vazia)
--      · patient_import_batches  (lotes de importação de pacientes por CSV; começa vazia)
--      · patient_import_rows     (resultado por linha de cada lote; começa vazia)
-- Não apaga nem altera nenhum dado: acrescenta colunas com valor padrão e cria três tabelas vazias. MySQL 5.7.
-- ============================================================================
SET NAMES utf8mb4;

-- Colunas novas de meal_plan_templates: cada uma só é criada se ainda não existe.
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''active''', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'status');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `source_plan_id` BIGINT(20) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'source_plan_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `created_by_user_id` BIGINT(20) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'created_by_user_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `is_clinic_default` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'is_clinic_default');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `archived_at` DATETIME NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'archived_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `updated_at` DATETIME NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'updated_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD INDEX `idx_plan_templates_clinic_status` (`clinic_id`, `status`)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND INDEX_NAME = 'idx_plan_templates_clinic_status');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

CREATE TABLE IF NOT EXISTS `food_search_metrics` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `metric_date` DATE NOT NULL,
  `term` VARCHAR(80) NOT NULL,
  `searches` INT(11) NOT NULL DEFAULT 0,
  `zero_results` INT(11) NOT NULL DEFAULT 0,
  `last_result_count` INT(11) NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_food_search_metric` (`clinic_id`, `metric_date`, `term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `patient_import_batches` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `file_sha256` CHAR(64) NOT NULL,
  `file_name` VARCHAR(255) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'preview',
  `mapping_json` TEXT NULL,
  `total_rows` INT(11) NOT NULL DEFAULT 0,
  `valid_rows` INT(11) NOT NULL DEFAULT 0,
  `created_rows` INT(11) NOT NULL DEFAULT 0,
  `skipped_rows` INT(11) NOT NULL DEFAULT 0,
  `error_rows` INT(11) NOT NULL DEFAULT 0,
  `created_by_user_id` BIGINT(20) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at` DATETIME NULL,
  `rolled_back_at` DATETIME NULL,
  `rolled_back_rows` INT(11) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_patient_import_file` (`clinic_id`, `file_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `patient_import_rows` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `batch_id` BIGINT(20) NOT NULL,
  `clinic_id` BIGINT(20) NOT NULL,
  `line_no` INT(11) NOT NULL,
  `raw_json` TEXT NULL,
  `outcome` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `message` VARCHAR(255) NULL,
  `patient_id` BIGINT(20) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_patient_import_rows_batch` (`batch_id`, `line_no`),
  KEY `idx_patient_import_rows_clinic` (`clinic_id`, `patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra a migração 62 (para o instalador não tentar rodá-la de novo), só se tudo acima está no lugar.
INSERT INTO `migrations` (`migration`, `executed_at`)
SELECT '2026_10_29_000062_plan_templates_food_search_and_patient_import', CURRENT_TIMESTAMP FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import')
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('food_search_metrics','patient_import_batches','patient_import_rows')) = 3
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('status','source_plan_id','created_by_user_id','is_clinic_default','archived_at','updated_at')) = 6;

-- Conferência (resultado esperado: tabelas = 1, 1 e 1, colunas novas de modelos = 6, migração 62 registrada = 1).
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_metrics') AS tabela_metricas_busca,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_batches') AS tabela_lotes_importacao,
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_import_rows') AS tabela_linhas_importacao,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('status','source_plan_id','created_by_user_id','is_clinic_default','archived_at','updated_at')) AS colunas_novas_modelos,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000062_plan_templates_food_search_and_patient_import') AS migracao_62_registrada;
