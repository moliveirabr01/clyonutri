-- ============================================================================
-- Clyo Nutri v49: ATUALIZAÇÃO COMPLETA DO BANCO, migração 68 (NI2-514 Fase 3: NI2-524 e NI2-525)
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou.
--  1. anthropometric_assessments: mais 7 circunferências (abdômen, ombro, braço contraído, antebraço, punho,
--     coxa proximal, panturrilha esquerda) e os campos de bioimpedância (origem do percentual de gordura,
--     aparelho, gordura visceral, água corporal, massa óssea, ângulo de fase). Avaliações antigas ficam vazias nesses campos.
--  2. meal_plan_items e meal_plan_substitutions: alerta de alérgeno e justificativa registrados quando o item foi
--     adicionado. Itens antigos ficam vazios.
--  3. Registra a migração 68 uma vez só.
-- Substitui o "php bin/migrate.php" para a 68: com este arquivo não precisa rodar o migrate.php.
-- Idempotente (pode rodar de novo), sem chave estrangeira, sem DROP, sem DELETE e sem UPDATE. MySQL 5.7 e 8.
-- Rode ANTES o atualizacao-v49-preflight.sql e DEPOIS o atualizacao-v49-verificacao.sql.
-- ============================================================================
SET NAMES utf8mb4;
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());

-- 1 e 2. Colunas (só as que faltarem)
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `abdomen_cm` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'abdomen_cm');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `shoulder_cm` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'shoulder_cm');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `arm_contracted_cm` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'arm_contracted_cm');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `forearm_cm` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'forearm_cm');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `wrist_cm` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'wrist_cm');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `thigh_proximal_cm` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'thigh_proximal_cm');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `calf_left_cm` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'calf_left_cm');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `body_fat_source` VARCHAR(40) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'body_fat_source');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `bioimpedance_device` VARCHAR(120) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'bioimpedance_device');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `visceral_fat_level` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'visceral_fat_level');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `total_body_water_pct` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'total_body_water_pct');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `bone_mass_kg` DECIMAL(5,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'bone_mass_kg');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`anthropometric_assessments` ADD COLUMN `phase_angle_deg` DECIMAL(4,2) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'phase_angle_deg');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`meal_plan_items` ADD COLUMN `allergen_alert_json` LONGTEXT NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME = 'allergen_alert_json');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`meal_plan_items` ADD COLUMN `allergen_ack_reason` VARCHAR(500) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME = 'allergen_ack_reason');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`meal_plan_substitutions` ADD COLUMN `allergen_alert_json` LONGTEXT NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions' AND COLUMN_NAME = 'allergen_alert_json');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @db, '`.`meal_plan_substitutions` ADD COLUMN `allergen_ack_reason` VARCHAR(500) NULL'), 'DO 0')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions' AND COLUMN_NAME = 'allergen_ack_reason');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

-- 3. Registro da migração, só se as 17 colunas estão no lugar
SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`)
SELECT ''2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda'' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda'')
  AND ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''anthropometric_assessments'' AND COLUMN_NAME IN (''abdomen_cm'', ''shoulder_cm'', ''arm_contracted_cm'', ''forearm_cm'', ''wrist_cm'', ''thigh_proximal_cm'', ''calf_left_cm'', ''body_fat_source'', ''bioimpedance_device'', ''visceral_fat_level'', ''total_body_water_pct'', ''bone_mass_kg'', ''phase_angle_deg'')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''meal_plan_items'' AND COLUMN_NAME IN (''allergen_alert_json'', ''allergen_ack_reason'')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ''meal_plan_substitutions'' AND COLUMN_NAME IN (''allergen_alert_json'', ''allergen_ack_reason''))) = 17');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

SET @m68 = 0;
SET @c = CONCAT('SELECT COUNT(*) INTO @m68 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda''');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SELECT
  @db AS banco,
  ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME IN ('abdomen_cm', 'shoulder_cm', 'arm_contracted_cm', 'forearm_cm', 'wrist_cm', 'thigh_proximal_cm', 'calf_left_cm', 'body_fat_source', 'bioimpedance_device', 'visceral_fat_level', 'total_body_water_pct', 'bone_mass_kg', 'phase_angle_deg')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME IN ('allergen_alert_json', 'allergen_ack_reason')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions' AND COLUMN_NAME IN ('allergen_alert_json', 'allergen_ack_reason'))) AS colunas_da_68_de_17,
  @m68 AS migracao_68_registrada;
