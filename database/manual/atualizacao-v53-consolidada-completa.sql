-- =============================================================================
-- ClyoNutri II — ATUALIZAÇÃO CONSOLIDADA COMPLETA v53 (NI2-567)
-- Cobre todas as evoluções do ciclo v50 a v53:
--   - PARTE 1: Migração 69 — Calendário, Feriados, Bloqueios, Buffers e Lista de Espera (v50 Agenda)
--   - PARTE 2: Migração 70 — Worker Delimitado e Resumível da Fila de Espera (v50 Fila)
--   - PARTE 3: Migração 71 — Prescrições Nutricionais, Manipulados e Assinatura Digital (v51 Prescrições / NI2-554)
--   - PARTE 4: Migração 72 — Autoagendamento Completo e Gestão do Paciente (v52 Autoagendamento / NI2-433)
--   - PARTE 5: Migração 73 — Anamnese Estruturada com 12 Blocos e Campos Condicionais (NI2-223)
-- Banco padrão: maic6824_Clyonutri2 (ajuste na variável @db se o banco tiver outro nome)
-- Compatível com: MySQL 5.7+, MySQL 8.0+, MariaDB 10.3+ e phpMyAdmin (HostGator)
-- =============================================================================

SET @db = 'maic6824_Clyonutri2';

-- ----------------------------------------------------------------------------
-- PARTE 1: Migração 69 — Calendário, Feriados e Lista de Espera
-- ----------------------------------------------------------------------------

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_calendar_policies` (
  `clinic_id` INT PRIMARY KEY,
  `buffer_before_minutes` INT NOT NULL DEFAULT 0,
  `buffer_after_minutes` INT NOT NULL DEFAULT 0,
  `waitlist_enabled` INT NOT NULL DEFAULT 0,
  `offer_ttl_minutes` INT NOT NULL DEFAULT 15,
  `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000069_create_booking_calendar_and_waitlist''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000069_create_booking_calendar_and_waitlist'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- PARTE 2: Migração 70 — Worker Delimitado e Resumível da Fila de Espera
-- ----------------------------------------------------------------------------

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_waitlist_worker` (
  `id` INT PRIMARY KEY,
  `cursor_created` VARCHAR(19) NOT NULL DEFAULT ''1000-01-01 00:00:00'',
  `cursor_id` INT NOT NULL DEFAULT 0,
  `pass_max_id` INT NOT NULL DEFAULT 0,
  `resume_id` INT NULL,
  `resume_date` VARCHAR(10) NULL,
  `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('INSERT IGNORE INTO `', @db, '`.`booking_waitlist_worker` (id, updated_at) VALUES (1, CURRENT_TIMESTAMP)');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @waitlist_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA = BINARY @db AND TABLE_NAME = 'booking_waitlist' AND INDEX_NAME = 'idx_waitlist_matching_fifo') = 0,
  CONCAT('CREATE INDEX idx_waitlist_matching_fifo ON `', @db, '`.`booking_waitlist` (clinic_id, professional_id, status, created_at, id)'),
  'SELECT 1');
PREPARE waitlist_index_stmt FROM @waitlist_index_sql;
EXECUTE waitlist_index_stmt;
DEALLOCATE PREPARE waitlist_index_stmt;

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000070_bound_waitlist_processing''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000070_bound_waitlist_processing'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- PARTE 3: Migração 71 — Prescrições Clínicas, Manipulados e Assinaturas (NI2-554)
-- ----------------------------------------------------------------------------

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_policy_versions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `version` VARCHAR(80) NOT NULL,
  `effective_from` DATE NOT NULL,
  `effective_until` DATE NOT NULL,
  `clinical_reviewer` VARCHAR(160) NOT NULL,
  `legal_reviewer` VARCHAR(160) NOT NULL,
  `evidence` TEXT NOT NULL,
  `signature_profile` VARCHAR(40) NOT NULL,
  `retention_days` INT NOT NULL,
  `retention_basis` TEXT NOT NULL,
  `enabled` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  UNIQUE (`clinic_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_ingredients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `code` VARCHAR(80) NOT NULL,
  `name` VARCHAR(180) NOT NULL,
  `category` VARCHAR(40) NOT NULL,
  `enabled` INT NOT NULL DEFAULT 0,
  UNIQUE (`clinic_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_ingredient_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `policy_id` INT NOT NULL,
  `ingredient_id` INT NOT NULL,
  `decision` VARCHAR(16) NOT NULL,
  `constraints_json` TEXT NOT NULL,
  `justification` TEXT NOT NULL,
  `source_url` VARCHAR(1000) NOT NULL,
  UNIQUE (`policy_id`, `ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_qualifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `professional_id` INT NOT NULL,
  `crn` VARCHAR(40) NOT NULL,
  `crn_region` VARCHAR(20) NOT NULL,
  `registration_status` VARCHAR(20) NOT NULL,
  `phytotherapy` INT NOT NULL DEFAULT 0,
  `certificate_sha256` VARCHAR(64) NOT NULL,
  `verified_by` VARCHAR(160) NOT NULL,
  `evidence` TEXT NOT NULL,
  `valid_until` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_prescriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `patient_id` INT NOT NULL,
  `professional_id` INT NOT NULL,
  `appointment_id` INT NULL,
  `meal_plan_id` INT NULL,
  `root_id` INT NULL,
  `previous_id` INT NULL,
  `version_number` INT NOT NULL DEFAULT 1,
  `status` VARCHAR(20) NOT NULL DEFAULT ''draft'',
  `revision` INT NOT NULL DEFAULT 1,
  `payload_json` TEXT NOT NULL,
  `snapshot_json` TEXT NULL,
  `document_pdf` LONGBLOB NULL,
  `pdf_sha256` VARCHAR(64) NULL,
  `policy_id` INT NULL,
  `prepared_at` DATETIME NULL,
  `issued_at` DATETIME NULL,
  `retain_until` DATE NULL,
  `cancelled_at` DATETIME NULL,
  `cancellation_reason` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE (`previous_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_signatures` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `prescription_id` INT NOT NULL UNIQUE,
  `signature_der` LONGBLOB NOT NULL,
  `certificate_sha256` VARCHAR(64) NOT NULL,
  `pdf_sha256` VARCHAR(64) NOT NULL,
  `qualification_id` INT NOT NULL,
  `verified_at` DATETIME NOT NULL,
  `profile` VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_audit_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `prescription_id` INT NULL,
  `actor_type` VARCHAR(20) NOT NULL,
  `actor_id` INT NULL,
  `action` VARCHAR(40) NOT NULL,
  `metadata_json` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_deliveries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `prescription_id` INT NOT NULL,
  `patient_id` INT NOT NULL,
  `channel` VARCHAR(20) NOT NULL,
  `action` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `professional_id` INT NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `payload_json` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE (`clinic_id`, `professional_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`rx_followups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `prescription_id` INT NOT NULL,
  `professional_id` INT NOT NULL,
  `note` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índices de prescrições
SET @rx_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA = BINARY @db AND TABLE_NAME = 'rx_prescriptions' AND INDEX_NAME = 'idx_rx_author') = 0,
  CONCAT('CREATE INDEX idx_rx_author ON `', @db, '`.`rx_prescriptions` (clinic_id, professional_id, patient_id, id)'),
  'SELECT 1');
PREPARE rx_stmt FROM @rx_index_sql; EXECUTE rx_stmt; DEALLOCATE PREPARE rx_stmt;

SET @rx_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA = BINARY @db AND TABLE_NAME = 'rx_prescriptions' AND INDEX_NAME = 'idx_rx_patient') = 0,
  CONCAT('CREATE INDEX idx_rx_patient ON `', @db, '`.`rx_prescriptions` (clinic_id, patient_id, status, id)'),
  'SELECT 1');
PREPARE rx_stmt FROM @rx_index_sql; EXECUTE rx_stmt; DEALLOCATE PREPARE rx_stmt;

SET @rx_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA = BINARY @db AND TABLE_NAME = 'rx_audit_events' AND INDEX_NAME = 'idx_rx_audit') = 0,
  CONCAT('CREATE INDEX idx_rx_audit ON `', @db, '`.`rx_audit_events` (clinic_id, prescription_id, id)'),
  'SELECT 1');
PREPARE rx_stmt FROM @rx_index_sql; EXECUTE rx_stmt; DEALLOCATE PREPARE rx_stmt;

SET @rx_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE BINARY TABLE_SCHEMA = BINARY @db AND TABLE_NAME = 'rx_qualifications' AND INDEX_NAME = 'idx_rx_qualification') = 0,
  CONCAT('CREATE INDEX idx_rx_qualification ON `', @db, '`.`rx_qualifications` (clinic_id, professional_id, id)'),
  'SELECT 1');
PREPARE rx_stmt FROM @rx_index_sql; EXECUTE rx_stmt; DEALLOCATE PREPARE rx_stmt;

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000071_create_nutritional_prescriptions''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000071_create_nutritional_prescriptions'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- PARTE 4: Migração 72 — Autoagendamento Completo e Gestão do Paciente (NI2-433)
-- ----------------------------------------------------------------------------

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_self_service_policies` (
  `clinic_id` INT PRIMARY KEY,
  `direct_reschedule` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_self_service_operations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `patient_id` INT NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `appointment_id` INT NOT NULL,
  `from_scheduled_at` VARCHAR(19) NOT NULL,
  `to_scheduled_at` VARCHAR(19) NOT NULL,
  `result_json` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE (`clinic_id`, `patient_id`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.`booking_daily_metrics` (
  `clinic_id` INT NOT NULL,
  `metric_date` DATE NOT NULL,
  `event_name` VARCHAR(40) NOT NULL,
  `event_count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`clinic_id`, `metric_date`, `event_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000072_complete_patient_self_service''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000072_complete_patient_self_service'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- PARTE 5: Migração 73 — Anamnese Estruturada e Campos Condicionais (NI2-223)
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

DROP PROCEDURE IF EXISTS `_clyo_add_col_if_missing`;

SET @c = CONCAT('INSERT INTO `', @db, '`.`migrations` (migration)
SELECT ''2026_11_01_000073_add_structured_conditional_fields_to_anamneses''
WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE migration = ''2026_11_01_000073_add_structured_conditional_fields_to_anamneses'')');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- Resumo final da execução
-- ----------------------------------------------------------------------------
SELECT 'Atualização consolidada v53 (Migrações 69, 70, 71, 72 e 73) concluída com sucesso!' AS resultado, @db AS banco;
