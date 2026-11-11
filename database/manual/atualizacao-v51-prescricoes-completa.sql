-- NI2-554 / migração 71. Requer base v49/68. Não libera ativos ou profissionais automaticamente.

-- Banco explicitamente qualificado para execução no phpMyAdmin.

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_policy_versions` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, version VARCHAR(80) NOT NULL, effective_from DATE NOT NULL, effective_until DATE NOT NULL, clinical_reviewer VARCHAR(160) NOT NULL, legal_reviewer VARCHAR(160) NOT NULL, evidence TEXT NOT NULL, signature_profile VARCHAR(40) NOT NULL, retention_days INT NOT NULL, retention_basis TEXT NOT NULL, enabled INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, UNIQUE(clinic_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_ingredients` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(180) NOT NULL, category VARCHAR(40) NOT NULL, enabled INT NOT NULL DEFAULT 0, UNIQUE(clinic_id,code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_ingredient_rules` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, policy_id INT NOT NULL, ingredient_id INT NOT NULL, decision VARCHAR(16) NOT NULL, constraints_json TEXT NOT NULL, justification TEXT NOT NULL, source_url VARCHAR(1000) NOT NULL, UNIQUE(policy_id,ingredient_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_qualifications` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, professional_id INT NOT NULL, crn VARCHAR(40) NOT NULL, crn_region VARCHAR(20) NOT NULL, registration_status VARCHAR(20) NOT NULL, phytotherapy INT NOT NULL DEFAULT 0, certificate_sha256 VARCHAR(64) NOT NULL, verified_by VARCHAR(160) NOT NULL, evidence TEXT NOT NULL, valid_until DATETIME NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_prescriptions` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, patient_id INT NOT NULL, professional_id INT NOT NULL, appointment_id INT NULL, meal_plan_id INT NULL, root_id INT NULL, previous_id INT NULL, version_number INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'draft', revision INT NOT NULL DEFAULT 1, payload_json TEXT NOT NULL, snapshot_json TEXT NULL, document_pdf LONGBLOB NULL, pdf_sha256 VARCHAR(64) NULL, policy_id INT NULL, prepared_at DATETIME NULL, issued_at DATETIME NULL, retain_until DATE NULL, cancelled_at DATETIME NULL, cancellation_reason TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE(previous_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_signatures` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, prescription_id INT NOT NULL UNIQUE, signature_der LONGBLOB NOT NULL, certificate_sha256 VARCHAR(64) NOT NULL, pdf_sha256 VARCHAR(64) NOT NULL, qualification_id INT NOT NULL, verified_at DATETIME NOT NULL, profile VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_audit_events` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, prescription_id INT NULL, actor_type VARCHAR(20) NOT NULL, actor_id INT NULL, action VARCHAR(40) NOT NULL, metadata_json TEXT NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_deliveries` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, prescription_id INT NOT NULL, patient_id INT NOT NULL, channel VARCHAR(20) NOT NULL, action VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_templates` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, professional_id INT NOT NULL, name VARCHAR(160) NOT NULL, payload_json TEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,professional_id,name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`rx_followups` (id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INT NOT NULL, prescription_id INT NOT NULL, professional_id INT NOT NULL, note TEXT NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @rx_index_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='rx_prescriptions' AND INDEX_NAME='idx_rx_author');

SET @rx_sql=IF(@rx_index_exists=0,'CREATE INDEX idx_rx_author ON `maic6824_Clyonutri2`.`rx_prescriptions` (clinic_id,professional_id,patient_id,id)','SELECT 1');

PREPARE rx_stmt FROM @rx_sql;

EXECUTE rx_stmt;

DEALLOCATE PREPARE rx_stmt;

SET @rx_index_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='rx_prescriptions' AND INDEX_NAME='idx_rx_patient');

SET @rx_sql=IF(@rx_index_exists=0,'CREATE INDEX idx_rx_patient ON `maic6824_Clyonutri2`.`rx_prescriptions` (clinic_id,patient_id,status,id)','SELECT 1');

PREPARE rx_stmt FROM @rx_sql;

EXECUTE rx_stmt;

DEALLOCATE PREPARE rx_stmt;

SET @rx_index_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='rx_audit_events' AND INDEX_NAME='idx_rx_audit');

SET @rx_sql=IF(@rx_index_exists=0,'CREATE INDEX idx_rx_audit ON `maic6824_Clyonutri2`.`rx_audit_events` (clinic_id,prescription_id,id)','SELECT 1');

PREPARE rx_stmt FROM @rx_sql;

EXECUTE rx_stmt;

DEALLOCATE PREPARE rx_stmt;

SET @rx_index_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='rx_qualifications' AND INDEX_NAME='idx_rx_qualification');

SET @rx_sql=IF(@rx_index_exists=0,'CREATE INDEX idx_rx_qualification ON `maic6824_Clyonutri2`.`rx_qualifications` (clinic_id,professional_id,id)','SELECT 1');

PREPARE rx_stmt FROM @rx_sql;

EXECUTE rx_stmt;

DEALLOCATE PREPARE rx_stmt;

SET @rx_index_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME='rx_deliveries' AND INDEX_NAME='idx_rx_delivery');

SET @rx_sql=IF(@rx_index_exists=0,'CREATE INDEX idx_rx_delivery ON `maic6824_Clyonutri2`.`rx_deliveries` (clinic_id,prescription_id,id)','SELECT 1');

PREPARE rx_stmt FROM @rx_sql;

EXECUTE rx_stmt;

DEALLOCATE PREPARE rx_stmt;

INSERT INTO `maic6824_Clyonutri2`.`migrations` (migration) SELECT '2026_11_01_000071_create_nutritional_prescriptions' WHERE NOT EXISTS (SELECT 1 FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000071_create_nutritional_prescriptions');
