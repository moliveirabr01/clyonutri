-- ============================================================================
-- Clyo Nutri v49: VERIFICAÇÃO (somente leitura), depois do atualizacao-v49-completa.sql
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou.
-- Cada linha deve dar OK. Não altera nada. MySQL 5.7 e 8.
-- ============================================================================
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());
SET @m68 = 0;
SET @c = CONCAT('SELECT COUNT(*) INTO @m68 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda''');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SELECT 'banco' AS item, @db AS resultado
UNION ALL SELECT 'colunas da migração 68 (17)', IF(((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME IN ('abdomen_cm', 'shoulder_cm', 'arm_contracted_cm', 'forearm_cm', 'wrist_cm', 'thigh_proximal_cm', 'calf_left_cm', 'body_fat_source', 'bioimpedance_device', 'visceral_fat_level', 'total_body_water_pct', 'bone_mass_kg', 'phase_angle_deg')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME IN ('allergen_alert_json', 'allergen_ack_reason')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions' AND COLUMN_NAME IN ('allergen_alert_json', 'allergen_ack_reason'))) = 17, 'OK', 'FALHA')
UNION ALL SELECT 'antropometria: 7 circunferências novas', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME IN ('abdomen_cm','shoulder_cm','arm_contracted_cm','forearm_cm','wrist_cm','thigh_proximal_cm','calf_left_cm') AND DATA_TYPE = 'decimal' AND IS_NULLABLE = 'YES') = 7, 'OK', 'FALHA')
UNION ALL SELECT 'antropometria: 6 campos de bioimpedância', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME IN ('body_fat_source','bioimpedance_device','visceral_fat_level','total_body_water_pct','bone_mass_kg','phase_angle_deg') AND IS_NULLABLE = 'YES') = 6, 'OK', 'FALHA')
UNION ALL SELECT 'alérgeno nos itens e substituições (4)', IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('meal_plan_items','meal_plan_substitutions') AND COLUMN_NAME IN ('allergen_alert_json','allergen_ack_reason') AND IS_NULLABLE = 'YES') = 4, 'OK', 'FALHA')
UNION ALL SELECT 'migração 68 registrada uma vez', IF(@m68 = 1, 'OK', 'FALHA')
UNION ALL SELECT 'colunas novas sem chave estrangeira', IF((SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('anthropometric_assessments','meal_plan_items','meal_plan_substitutions') AND REFERENCED_TABLE_NAME IS NOT NULL AND COLUMN_NAME IN ('abdomen_cm','shoulder_cm','arm_contracted_cm','forearm_cm','wrist_cm','thigh_proximal_cm','calf_left_cm','body_fat_source','bioimpedance_device','visceral_fat_level','total_body_water_pct','bone_mass_kg','phase_angle_deg','allergen_alert_json','allergen_ack_reason')) = 0, 'OK', 'FALHA');
