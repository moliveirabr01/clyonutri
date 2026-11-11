-- ============================================================================
-- Clyo Nutri v50 Consolidado: ATUALIZAÇÃO COMPLETA DO BANCO DE DADOS
-- Contempla:
--   1. Migração 69: Políticas de Calendário, Feriados/Exceções, Buffers e Lista de Espera
--   2. Migração 70: Anamnese Estruturada em 12 Blocos com Campos Clínicos Condicionais (NI2-223)
--
-- BANCO: o script usa o banco selecionado no phpMyAdmin. Se o phpMyAdmin trocar o banco ativo
-- para o information_schema ou se nenhum banco estiver selecionado, usa o banco reserva.
-- ============================================================================
SET NAMES utf8mb4;
SET @db_reserva = 'maic6824_Clyonutri2';
SET @db = IF(DATABASE() IS NULL OR DATABASE() IN ('information_schema','mysql','performance_schema','sys'), @db_reserva, DATABASE());

-- ----------------------------------------------------------------------------
-- PARTE 1: Migração 69 — Calendário, Feriados e Lista de Espera
-- ----------------------------------------------------------------------------

-- 1.1 Tabela de Políticas de Calendário
SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_calendar_policies` (
  `clinic_id` INT PRIMARY KEY,
  `buffer_before_minutes` INT NOT NULL DEFAULT 0,
  `buffer_after_minutes` INT NOT NULL DEFAULT 0,
  `waitlist_enabled` INT NOT NULL DEFAULT 0,
  `offer_ttl_minutes` INT NOT NULL DEFAULT 15,
  `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.2 Tabela de Exceções e Feriados da Agenda
SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_calendar_exceptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `professional_id` INT NULL,
  `rule_date` DATE NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `start_time` VARCHAR(5) NULL,
  `end_time` VARCHAR(5) NULL,
  `label` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_booking_calendar_day` (`clinic_id`, `rule_date`, `professional_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.3 Tabela da Lista de Espera de Autoagendamento
SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_waitlist` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `patient_id` INT NOT NULL,
  `professional_id` INT NOT NULL,
  `date_from` DATE NOT NULL,
  `date_to` DATE NOT NULL,
  `time_from` VARCHAR(5) NOT NULL,
  `time_to` VARCHAR(5) NOT NULL,
  `modality` VARCHAR(16) NOT NULL,
  `service_type` VARCHAR(20) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT ''waiting'',
  `active_key` VARCHAR(80) NULL,
  `hold_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_booking_waitlist_active` (`clinic_id`, `active_key`),
  KEY `idx_booking_waitlist_fifo` (`status`, `created_at`, `id`),
  KEY `idx_booking_waitlist_patient` (`clinic_id`, `patient_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.4 Registro da migração 69
SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000069_create_booking_calendar_and_waitlist''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000069_create_booking_calendar_and_waitlist'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- PARTE 2: Migração 70 — Anamnese Estruturada e Campos Condicionais (NI2-223)
-- ----------------------------------------------------------------------------

-- Helper macro para adição idempotente de colunas na tabela anamneses
SET @add_col = CONCAT('
  ALTER TABLE `', @db, '`.`anamneses`
    ADD COLUMN IF NOT EXISTS `chief_complaint` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `family_history` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `pathologies` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `has_surgeries` VARCHAR(10) NOT NULL DEFAULT ''no'',
    ADD COLUMN IF NOT EXISTS `surgeries_details` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `medication_usage` VARCHAR(20) NOT NULL DEFAULT ''none'',
    ADD COLUMN IF NOT EXISTS `medications_details` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `supplement_usage` VARCHAR(20) NOT NULL DEFAULT ''none'',
    ADD COLUMN IF NOT EXISTS `supplements_details` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `intolerance_assessment` VARCHAR(20) NOT NULL DEFAULT ''none'',
    ADD COLUMN IF NOT EXISTS `alcohol_consumption` VARCHAR(20) NOT NULL DEFAULT ''none'',
    ADD COLUMN IF NOT EXISTS `alcohol_details` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `smoking_status` VARCHAR(20) NOT NULL DEFAULT ''non_smoker'',
    ADD COLUMN IF NOT EXISTS `smoking_details` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `sleep_hours` DECIMAL(3,1) NULL,
    ADD COLUMN IF NOT EXISTS `sleep_quality` VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS `sleep_notes` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `physical_activity` VARCHAR(20) NOT NULL DEFAULT ''sedentary'',
    ADD COLUMN IF NOT EXISTS `activity_modality` VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS `activity_frequency_weekly` INT NULL,
    ADD COLUMN IF NOT EXISTS `activity_duration_minutes` INT NULL,
    ADD COLUMN IF NOT EXISTS `referenced_exams` LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS `structured_data_json` LONGTEXT NULL
');

-- Compatibilidade MySQL 5.7+ / MariaDB / MySQL 8:
-- Se o servidor suportar ADD COLUMN IF NOT EXISTS executa direto; caso contrário, executa via procedure segura.
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
    WHERE TABLE_SCHEMA = p_db AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
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

DROP PROCEDURE IF EXISTS `_clyo_add_col_if_missing`;

-- 2.2 Registro da migração 70
SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000070_add_structured_conditional_fields_to_anamneses''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000070_add_structured_conditional_fields_to_anamneses'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- Resumo final
-- ----------------------------------------------------------------------------
SELECT 'Atualização consolidada v50 (Migrações 69 e 70) concluída com sucesso!' AS resultado, @db AS banco;
