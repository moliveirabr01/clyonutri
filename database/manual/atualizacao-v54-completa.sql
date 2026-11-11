-- =============================================================================
-- ClyoNutri II — ATUALIZAÇÃO v54.1 (NI2-583, histórias NI2-584 a NI2-600) — COMPLETA E IDEMPOTENTE
-- Pré-requisito: v53 em produção (migrações até a 72 aplicadas).
--   - PARTE 1: Migração 73 — Anamnese estruturada (NI2-223). Mesmo SQL da v53 consolidada; se já aplicada, não faz nada.
--   - PARTE 2: Migração 75 — Alergia no cadastro do paciente (NI2-585 / UX-002).
--   - PARTE 3: Migração 77 — Apelido único do link de agendamento e opção de endereço público (NI2-596 / UX-013).
-- Não há migração 74 nem 76 nesta versão (foram retiradas na revisão: horários e medidas caseiras).
-- Banco padrão: maic6824_Clyonutri2 (ajuste @db se o nome for outro).
-- Compatível com MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+ e phpMyAdmin (HostGator).
-- Pode rodar duas vezes: a segunda execução não altera nada.
-- =============================================================================

SET @db = 'maic6824_Clyonutri2';

-- ----------------------------------------------------------------------------
-- PARTE 1: Migração 73 — Anamnese estruturada (NI2-223)
-- ----------------------------------------------------------------------------

DELIMITER ;;
DROP PROCEDURE IF EXISTS `_clyo_add_col_if_missing`;;
CREATE PROCEDURE `_clyo_add_col_if_missing`(
  IN p_db VARCHAR(64),
  IN p_table VARCHAR(64),
  IN p_col VARCHAR(64),
  IN p_def VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    -- BINARY: compara nomes sem collation (evita erro #1267 quando o banco usa utf8_unicode_ci e o information_schema utf8_general_ci)
    WHERE BINARY TABLE_SCHEMA = BINARY p_db AND BINARY TABLE_NAME = BINARY p_table AND BINARY COLUMN_NAME = BINARY p_col
  ) THEN
    SET @sql_dyn = CONCAT('ALTER TABLE `', p_db, '`.`', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE s FROM @sql_dyn;
    EXECUTE s;
    DEALLOCATE PREPARE s;
  END IF;
END;;
DELIMITER ;

CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'chief_complaint', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'family_history', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'pathologies', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'has_surgeries', 'VARCHAR(10) NOT NULL DEFAULT \'no\'');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'surgeries_details', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'medication_usage', 'VARCHAR(20) NOT NULL DEFAULT \'none\'');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'medications_details', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'supplement_usage', 'VARCHAR(20) NOT NULL DEFAULT \'none\'');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'supplements_details', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'intolerance_assessment', 'VARCHAR(20) NOT NULL DEFAULT \'none\'');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'alcohol_consumption', 'VARCHAR(20) NOT NULL DEFAULT \'none\'');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'alcohol_details', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'smoking_status', 'VARCHAR(20) NOT NULL DEFAULT \'non_smoker\'');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'smoking_details', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'sleep_hours', 'DECIMAL(3,1) NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'sleep_quality', 'VARCHAR(20) NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'sleep_notes', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'physical_activity', 'VARCHAR(20) NOT NULL DEFAULT \'sedentary\'');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'activity_modality', 'VARCHAR(150) NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'activity_frequency_weekly', 'INT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'activity_duration_minutes', 'INT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'referenced_exams', 'LONGTEXT NULL');
CALL `_clyo_add_col_if_missing`(@db, 'anamneses', 'structured_data_json', 'LONGTEXT NULL');

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000073_add_structured_conditional_fields_to_anamneses''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000073_add_structured_conditional_fields_to_anamneses'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- PARTE 2: Migração 75 — Alergia no cadastro do paciente (NI2-585)
-- ----------------------------------------------------------------------------

CALL `_clyo_add_col_if_missing`(@db, 'patients', 'allergies', 'VARCHAR(500) NULL');
CALL `_clyo_add_col_if_missing`(@db, 'patients', 'allergy_assessment', 'VARCHAR(30) NULL');

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000075_add_allergies_to_patients''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000075_add_allergies_to_patients'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- PARTE 3: Migração 77 — Link de agendamento com apelido único (NI2-596)
-- O apelido é gerado pela aplicação na primeira vez que o link aparece em Configurações
-- (ou quando um link antigo com número é aberto). Nenhum dado é preenchido por este SQL.
-- ----------------------------------------------------------------------------

CALL `_clyo_add_col_if_missing`(@db, 'professionals', 'booking_slug', 'VARCHAR(80) NULL');
CALL `_clyo_add_col_if_missing`(@db, 'booking_settings', 'public_show_address', 'INT NOT NULL DEFAULT 0');

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE BINARY TABLE_SCHEMA = BINARY @db AND TABLE_NAME = 'professionals' AND INDEX_NAME = 'uq_professionals_booking_slug');
SET @c = IF(@idx = 0,
  CONCAT('CREATE UNIQUE INDEX `uq_professionals_booking_slug` ON `', @db, '`.`professionals` (`booking_slug`)'),
  'SELECT ''índice uq_professionals_booking_slug já existe'' AS aviso');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_02_000077_add_booking_slug_and_public_address''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_02_000077_add_booking_slug_and_public_address'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS `_clyo_add_col_if_missing`;

SELECT 'Atualização v54.1 (migrações 73, 75 e 77) concluída.' AS resultado, @db AS banco;
