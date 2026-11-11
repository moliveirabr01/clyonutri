-- ============================================================================
-- Clyo Nutri v49: PREFLIGHT (somente leitura), migração 68 (NI2-514 Fase 3: antropometria com 14 circunferências
-- e bioimpedância, alerta de alérgeno no plano alimentar)
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo para o
-- information_schema (era o que causava o erro #1109) ou se nenhum banco estiver selecionado, ele usa o banco
-- reserva da linha SET @db_reserva logo abaixo. Para outro banco, troque só essa linha. A coluna "banco" do
-- resultado mostra em qual banco o script rodou.
-- Não altera nada. Rode ANTES do atualizacao-v49-completa.sql. MySQL 5.7 e 8.
-- Em produção o esperado é: "OK: PRONTO PARA MIGRAR (migração 68)".
-- ============================================================================
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());
SET @tb = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('anthropometric_assessments','meal_plan_items','meal_plan_substitutions'));
SET @tm = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'migrations');
SET @c68 = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME IN ('abdomen_cm', 'shoulder_cm', 'arm_contracted_cm', 'forearm_cm', 'wrist_cm', 'thigh_proximal_cm', 'calf_left_cm', 'body_fat_source', 'bioimpedance_device', 'visceral_fat_level', 'total_body_water_pct', 'bone_mass_kg', 'phase_angle_deg')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_items' AND COLUMN_NAME IN ('allergen_alert_json', 'allergen_ack_reason')) + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'meal_plan_substitutions' AND COLUMN_NAME IN ('allergen_alert_json', 'allergen_ack_reason')));
SET @m67 = 0;
SET @m68 = 0;
SET @c = IF(@tm = 1, CONCAT('SELECT COUNT(*) INTO @m67 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_29_000067_exam_result_documents'''), 'DO 0');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;
SET @c = IF(@tm = 1, CONCAT('SELECT COUNT(*) INTO @m68 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda'''), 'DO 0');
PREPARE stmt_c FROM @c; EXECUTE stmt_c; DEALLOCATE PREPARE stmt_c;

SELECT
  @db AS banco,
  CASE
    WHEN @tm = 0 OR @tb < 3 THEN 'FALHA: este banco não é o do Clyo Nutri (faltam tabelas); selecione o banco certo ou ajuste @db_reserva'
    WHEN @m67 = 0 THEN 'FALHA: migração 67 (v48) não registrada; rode antes o SQL da v47 (se faltar) e o da v48'
    WHEN @c68 = 17 AND @m68 = 1 THEN 'OK: v49 já aplicada (rodar a completa de novo não muda nada)'
    WHEN @c68 > 0 THEN 'OK: ESTADO PARCIAL (parte da 68 já existe); a completa cria só o que falta'
    ELSE 'OK: PRONTO PARA MIGRAR (migração 68)'
  END AS situacao,
  @c68 AS colunas_da_68_de_17,
  @m67 AS migracao_67, @m68 AS migracao_68;
