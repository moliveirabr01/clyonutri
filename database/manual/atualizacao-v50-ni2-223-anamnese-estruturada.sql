-- =============================================================================
-- ClyoNutri II — Atualização de Banco Manual HostGator / phpMyAdmin
-- História: NI2-223 — [P0] Anamnese estruturada completa com campos clínicos condicionais
-- Release candidata: v50
-- =============================================================================

ALTER TABLE anamneses
  ADD COLUMN chief_complaint LONGTEXT NULL,
  ADD COLUMN family_history LONGTEXT NULL,
  ADD COLUMN pathologies LONGTEXT NULL,
  ADD COLUMN has_surgeries VARCHAR(10) NOT NULL DEFAULT 'no',
  ADD COLUMN surgeries_details LONGTEXT NULL,
  ADD COLUMN medication_usage VARCHAR(20) NOT NULL DEFAULT 'none',
  ADD COLUMN medications_details LONGTEXT NULL,
  ADD COLUMN supplement_usage VARCHAR(20) NOT NULL DEFAULT 'none',
  ADD COLUMN supplements_details LONGTEXT NULL,
  ADD COLUMN intolerance_assessment VARCHAR(20) NOT NULL DEFAULT 'none',
  ADD COLUMN alcohol_consumption VARCHAR(20) NOT NULL DEFAULT 'none',
  ADD COLUMN alcohol_details LONGTEXT NULL,
  ADD COLUMN smoking_status VARCHAR(20) NOT NULL DEFAULT 'non_smoker',
  ADD COLUMN smoking_details LONGTEXT NULL,
  ADD COLUMN sleep_hours DECIMAL(3,1) NULL,
  ADD COLUMN sleep_quality VARCHAR(20) NULL,
  ADD COLUMN sleep_notes LONGTEXT NULL,
  ADD COLUMN physical_activity VARCHAR(20) NOT NULL DEFAULT 'sedentary',
  ADD COLUMN activity_modality VARCHAR(150) NULL,
  ADD COLUMN activity_frequency_weekly INT NULL,
  ADD COLUMN activity_duration_minutes INT NULL,
  ADD COLUMN referenced_exams LONGTEXT NULL,
  ADD COLUMN structured_data_json LONGTEXT NULL;
