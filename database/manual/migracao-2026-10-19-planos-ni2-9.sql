-- ============================================================================
-- ClyoNutri — Migração Manual 43 (code review NI2-9 · Plano alimentar e prescrição)
-- Equivale a database/migrations/2026_10_19_000043_harden_meal_plan_prescriptions.php.
-- Rodar no phpMyAdmin com o banco do sistema selecionado, DEPOIS do backup e do preflight
-- (preflight-2026-10-19-planos-ni2-9.sql). Idempotente: pode ser executada mais de uma vez.
--
-- 1. weight_grams / weight_is_estimated em meal_plan_items e meal_plan_substitutions
--    + preenchimento das linhas existentes com a mesma regra do HouseholdMeasureConverter.
-- 2. published_by_user_id / allergen_override_reason em meal_plan_versions.
-- 3. Índice de leitura da versão vigente e índice único (clinic_id, patient_id, version_number)
--    — o único só é criado se NÃO houver números repetidos.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();

SET @has_items = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items');
SET @has_subs = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions');
SET @has_versions = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions');
SET @has_measures = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_household_measures');
SET @preflight_ok = (@has_items = 1 AND @has_subs = 1 AND @has_versions = 1 AND @has_measures = 1);

-- ---------------------------------------------------------------------------- colunas
SET @a1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME = 'weight_grams');
SET @a2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME = 'weight_is_estimated');
SET @parts = CONCAT_WS(', ',
  IF(@a1 = 0, 'ADD COLUMN `weight_grams` DECIMAL(9,2) NULL', NULL),
  IF(@a2 = 0, 'ADD COLUMN `weight_is_estimated` TINYINT(1) NOT NULL DEFAULT 0', NULL));
SET @q = IF(@preflight_ok = 1 AND @parts <> '', CONCAT('ALTER TABLE `meal_plan_items` ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @b1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions' AND COLUMN_NAME = 'weight_grams');
SET @b2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions' AND COLUMN_NAME = 'weight_is_estimated');
SET @parts = CONCAT_WS(', ',
  IF(@b1 = 0, 'ADD COLUMN `weight_grams` DECIMAL(9,2) NULL', NULL),
  IF(@b2 = 0, 'ADD COLUMN `weight_is_estimated` TINYINT(1) NOT NULL DEFAULT 0', NULL));
SET @q = IF(@preflight_ok = 1 AND @parts <> '', CONCAT('ALTER TABLE `meal_plan_substitutions` ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions' AND COLUMN_NAME = 'published_by_user_id');
SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions' AND COLUMN_NAME = 'allergen_override_reason');
SET @parts = CONCAT_WS(', ',
  IF(@c1 = 0, 'ADD COLUMN `published_by_user_id` INT NULL', NULL),
  IF(@c2 = 0, 'ADD COLUMN `allergen_override_reason` TEXT NULL', NULL));
SET @q = IF(@preflight_ok = 1 AND @parts <> '', CONCAT('ALTER TABLE `meal_plan_versions` ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- peso das linhas existentes
-- Mesma ordem do HouseholdMeasureConverter: (1) medida cadastrada para o alimento; (2) unidade exata
-- (g, ml, kg...); (3) medida caseira genérica, marcada como estimada. Unidade desconhecida fica NULL.
-- Uma tabela por vez, só linhas ainda sem peso.
SET @std = 'CASE LOWER(TRIM(t.measure_unit))
    WHEN ''g'' THEN 1 WHEN ''grama'' THEN 1 WHEN ''gramas'' THEN 1 WHEN ''mg'' THEN 0.001 WHEN ''kg'' THEN 1000
    WHEN ''ml'' THEN 1 WHEN ''mililitro'' THEN 1 WHEN ''mililitros'' THEN 1 WHEN ''l'' THEN 1000 WHEN ''litro'' THEN 1000 WHEN ''litros'' THEN 1000
    WHEN ''colher de sopa'' THEN 15 WHEN ''colher sopa'' THEN 15 WHEN ''colher de sobremesa'' THEN 10
    WHEN ''colher de chá'' THEN 5 WHEN ''colher cha'' THEN 5 WHEN ''colher de café'' THEN 2 WHEN ''colher cafe'' THEN 2
    WHEN ''xícara de chá'' THEN 150 WHEN ''xicara de cha'' THEN 150 WHEN ''xícara'' THEN 150 WHEN ''xicara'' THEN 150
    WHEN ''copo americano'' THEN 200 WHEN ''copo'' THEN 200 WHEN ''concha'' THEN 130 WHEN ''concha média'' THEN 130
    WHEN ''fatia'' THEN 30 WHEN ''fatia média'' THEN 30 WHEN ''unidade'' THEN 100 WHEN ''unidade média'' THEN 100 WHEN ''porção'' THEN 100
    ELSE NULL END';
SET @exact = 'LOWER(TRIM(t.measure_unit)) IN (''g'',''grama'',''gramas'',''mg'',''kg'',''ml'',''mililitro'',''mililitros'',''l'',''litro'',''litros'')';

SET @tbl = 'meal_plan_items';
SET @q = IF(@preflight_ok = 1, CONCAT('UPDATE `', @tbl, '` t SET t.weight_grams = ROUND(t.amount * (SELECT fhm.grams_equivalent FROM `food_household_measures` fhm WHERE fhm.food_id = t.food_id AND LOWER(fhm.measure_name) = LOWER(TRIM(t.measure_unit)) ORDER BY fhm.id LIMIT 1), 2), t.weight_is_estimated = 0 ',
  'WHERE t.weight_grams IS NULL AND t.amount > 0 AND EXISTS (SELECT 1 FROM `food_household_measures` fhm WHERE fhm.food_id = t.food_id AND LOWER(fhm.measure_name) = LOWER(TRIM(t.measure_unit)))'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@preflight_ok = 1, CONCAT('UPDATE `', @tbl, '` t SET t.weight_is_estimated = IF(', @exact, ', 0, 1), t.weight_grams = ROUND(t.amount * (', @std, '), 2) ',
  'WHERE t.weight_grams IS NULL AND t.amount > 0 AND (', @std, ') IS NOT NULL'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @tbl = 'meal_plan_substitutions';
SET @q = IF(@preflight_ok = 1, CONCAT('UPDATE `', @tbl, '` t SET t.weight_grams = ROUND(t.amount * (SELECT fhm.grams_equivalent FROM `food_household_measures` fhm WHERE fhm.food_id = t.food_id AND LOWER(fhm.measure_name) = LOWER(TRIM(t.measure_unit)) ORDER BY fhm.id LIMIT 1), 2), t.weight_is_estimated = 0 ',
  'WHERE t.weight_grams IS NULL AND t.amount > 0 AND EXISTS (SELECT 1 FROM `food_household_measures` fhm WHERE fhm.food_id = t.food_id AND LOWER(fhm.measure_name) = LOWER(TRIM(t.measure_unit)))'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@preflight_ok = 1, CONCAT('UPDATE `', @tbl, '` t SET t.weight_is_estimated = IF(', @exact, ', 0, 1), t.weight_grams = ROUND(t.amount * (', @std, '), 2) ',
  'WHERE t.weight_grams IS NULL AND t.amount > 0 AND (', @std, ') IS NOT NULL'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- índices
SET @i1 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions' AND INDEX_NAME = 'idx_meal_plan_versions_current');
SET @q = IF(@preflight_ok = 1 AND @i1 = 0, 'CREATE INDEX `idx_meal_plan_versions_current` ON `meal_plan_versions` (`clinic_id`, `patient_id`, `status`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @dups = 0;
SET @q = IF(@preflight_ok = 1, 'SELECT COUNT(*) FROM (SELECT clinic_id, patient_id, version_number FROM `meal_plan_versions` GROUP BY clinic_id, patient_id, version_number HAVING COUNT(*) > 1) d INTO @dups', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @i2 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions' AND INDEX_NAME = 'uq_meal_plan_versions_number');
SET @q = IF(@preflight_ok = 1 AND @i2 = 0 AND @dups = 0, 'CREATE UNIQUE INDEX `uq_meal_plan_versions_number` ON `meal_plan_versions` (`clinic_id`, `patient_id`, `version_number`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- registro da migração
SET @ok = (@preflight_ok = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('meal_plan_items','meal_plan_substitutions') AND COLUMN_NAME IN ('weight_grams','weight_is_estimated')) = 4
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions' AND COLUMN_NAME IN ('published_by_user_id','allergen_override_reason')) = 2
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_versions' AND INDEX_NAME = 'uq_meal_plan_versions_number') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_19_000043_harden_meal_plan_prescriptions'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_19_000043_harden_meal_plan_prescriptions'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT
  IF(@ok, 'OK — migração 43 aplicada', IF(@dups > 0, 'PARCIAL — colunas criadas, índice único NÃO criado (versões repetidas; ver preflight)', 'FALHA — ver preflight')) AS resultado,
  (SELECT COUNT(*) FROM `meal_plan_items` WHERE weight_grams IS NULL) AS itens_sem_peso,
  (SELECT COUNT(*) FROM `meal_plan_items` WHERE weight_is_estimated = 1) AS itens_peso_estimado;
