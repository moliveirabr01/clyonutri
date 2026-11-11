-- ============================================================================
-- ClyoNutri v43: ATUALIZAÇÃO DO BANCO (migração 63): procedência dos alimentos, favoritos, métrica de seleção,
-- regras de equivalência da clínica e modelos de plano pessoais/aprovados com meta de referência (NI2-519, NI2-520).
-- Rode no phpMyAdmin, no banco de produção, DEPOIS do backup e do atualizacao-v43-preflight.sql, e ANTES de subir os
-- arquivos novos. Ordem: v39 (60), v41 (61), v42 (62) e esta (63).
-- Idempotente (pode rodar de novo sem efeito), sem chave estrangeira, ids BIGINT(20). MySQL 5.7.
--   63 · foods ganha source_version, source_code, source_imported_at, preparation e is_current (padrão 1), o índice
--        único uq_foods_source_version_code e o índice idx_foods_source_code. Os alimentos TACO com número recebem
--        a edição '4ª ed. rev. e ampl. (2011)' e o código TACO (uma linha por número; repetidos ficam sem versão).
--      · food_household_measures ganha source e source_version.
--      · meal_plan_templates ganha visibility, approval_status, approved_by_user_id, approved_at, plan_method, target_json.
--      · meal_plans ganha template_id, plan_method e reference_target_json.
--      · tabelas novas e vazias: food_favorites, food_search_selections, food_equivalence_rules.
-- Não apaga dado e não muda valor de nutriente. A forma de preparo é preenchida pelo app na primeira busca.
-- ============================================================================
SET NAMES utf8mb4;


-- Colunas novas de foods: cada uma só é criada se ainda não existe.
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `foods` ADD COLUMN `source_version` VARCHAR(60) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'source_version');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `foods` ADD COLUMN `source_code` VARCHAR(40) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'source_code');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `foods` ADD COLUMN `source_imported_at` DATETIME NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'source_imported_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `foods` ADD COLUMN `preparation` VARCHAR(30) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'preparation');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `foods` ADD COLUMN `is_current` TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'is_current');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Colunas novas de food_household_measures: cada uma só é criada se ainda não existe.
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `food_household_measures` ADD COLUMN `source` VARCHAR(80) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME = 'source');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `food_household_measures` ADD COLUMN `source_version` VARCHAR(60) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME = 'source_version');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Colunas novas de meal_plan_templates: cada uma só é criada se ainda não existe.
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `visibility` VARCHAR(20) NOT NULL DEFAULT ''clinica''', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'visibility');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `approval_status` VARCHAR(20) NOT NULL DEFAULT ''nao_aprovado''', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'approval_status');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `approved_by_user_id` BIGINT(20) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'approved_by_user_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `approved_at` DATETIME NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'approved_at');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `plan_method` VARCHAR(20) NOT NULL DEFAULT ''alimentos''', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'plan_method');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD COLUMN `target_json` LONGTEXT NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME = 'target_json');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Colunas novas de meal_plans: cada uma só é criada se ainda não existe.
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plans` ADD COLUMN `template_id` BIGINT(20) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'template_id');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plans` ADD COLUMN `plan_method` VARCHAR(20) NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'plan_method');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plans` ADD COLUMN `reference_target_json` LONGTEXT NULL', 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME = 'reference_target_json');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Índices (só se ainda não existem).
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `foods` ADD UNIQUE INDEX `uq_foods_source_version_code` (`source`, `source_version`, `source_code`)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'uq_foods_source_version_code');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `foods` ADD INDEX `idx_foods_source_code` (`source`, `source_code`)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_source_code');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `meal_plan_templates` ADD INDEX `idx_plan_templates_visibility` (`clinic_id`, `visibility`, `created_by_user_id`)', 'SELECT 1')
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND INDEX_NAME = 'idx_plan_templates_visibility');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- Procedência da TACO: a linha de menor id de cada número TACO recebe a edição e o código, se ainda não houver uma
-- linha com esse código e essa edição. As tabelas derivadas são materializadas antes do UPDATE (sem erro 1093).
UPDATE `foods` f
  JOIN (SELECT MIN(id) AS id FROM `foods` WHERE `source` = 'TACO' AND `taco_code` IS NOT NULL AND `clinic_id` IS NULL GROUP BY `taco_code`) m ON m.id = f.id
  LEFT JOIN (SELECT DISTINCT `source_code` FROM `foods` WHERE `source` = 'TACO' AND `source_version` = '4ª ed. rev. e ampl. (2011)' AND `source_code` IS NOT NULL LIMIT 1000000) x
    ON x.source_code = CAST(f.taco_code AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
SET f.`source_version` = '4ª ed. rev. e ampl. (2011)', f.`source_code` = CAST(f.taco_code AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
WHERE f.`source_version` IS NULL AND f.`source_code` IS NULL AND x.source_code IS NULL;

CREATE TABLE IF NOT EXISTS `food_favorites` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `food_id` BIGINT(20) NOT NULL,
  `created_by_user_id` BIGINT(20) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_food_favorites_clinic_food` (`clinic_id`, `food_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `food_search_selections` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `metric_date` DATE NOT NULL,
  `term` VARCHAR(80) NOT NULL,
  `food_id` BIGINT(20) NOT NULL,
  `selections` INT(11) NOT NULL DEFAULT 0,
  `corrections` INT(11) NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_food_search_selection` (`clinic_id`, `metric_date`, `term`, `food_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `food_equivalence_rules` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` BIGINT(20) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `food_groups` LONGTEXT NOT NULL,
  `energy_tolerance_pct` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  `macro_tolerance_pct` DECIMAL(5,2) NOT NULL DEFAULT 20.00,
  `source_note` VARCHAR(500) NOT NULL,
  `review_status` VARCHAR(20) NOT NULL DEFAULT 'pendente',
  `reviewed_by_user_id` BIGINT(20) NULL,
  `reviewed_at` DATETIME NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_by_user_id` BIGINT(20) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  `archived_at` DATETIME NULL,
  KEY `idx_food_equivalence_rules_clinic` (`clinic_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra a migração 63 (para o instalador não tentar rodá-la de novo), só se tudo acima está no lugar.
INSERT INTO `migrations` (`migration`, `executed_at`)
SELECT '2026_10_29_000063_food_provenance_equivalence_and_template_targets', CURRENT_TIMESTAMP FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets')
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('food_favorites','food_search_selections','food_equivalence_rules')) = 3
  AND ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME IN ('source_version','source_code','source_imported_at','preparation','is_current'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source','source_version'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('visibility','approval_status','approved_by_user_id','approved_at','plan_method','target_json'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME IN ('template_id','plan_method','reference_target_json'))) = 16
  AND ((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'uq_foods_source_version_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_source_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND INDEX_NAME = 'idx_plan_templates_visibility') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_favorites' AND INDEX_NAME = 'uq_food_favorites_clinic_food') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_selections' AND INDEX_NAME = 'uq_food_search_selection') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_equivalence_rules' AND INDEX_NAME = 'idx_food_equivalence_rules_clinic')) = 6;

-- Conferência (esperado: tabelas novas = 3, colunas novas = 16, índices = 6, migração 63 registrada = 1).
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('food_favorites','food_search_selections','food_equivalence_rules')) AS tabelas_novas_esperado_3,
  ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME IN ('source_version','source_code','source_imported_at','preparation','is_current'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME IN ('source','source_version'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND COLUMN_NAME IN ('visibility','approval_status','approved_by_user_id','approved_at','plan_method','target_json'))
    + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plans' AND COLUMN_NAME IN ('template_id','plan_method','reference_target_json'))) AS colunas_novas_esperado_16,
  ((SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'uq_foods_source_version_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_source_code') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meal_plan_templates' AND INDEX_NAME = 'idx_plan_templates_visibility') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_favorites' AND INDEX_NAME = 'uq_food_favorites_clinic_food') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_search_selections' AND INDEX_NAME = 'uq_food_search_selection') + (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_equivalence_rules' AND INDEX_NAME = 'idx_food_equivalence_rules_clinic')) AS indices_esperado_6,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_29_000063_food_provenance_equivalence_and_template_targets') AS migracao_63_registrada,
  (SELECT COUNT(*) FROM `foods` WHERE `source` = 'TACO' AND `source_version` = '4ª ed. rev. e ampl. (2011)') AS alimentos_taco_com_versao;
