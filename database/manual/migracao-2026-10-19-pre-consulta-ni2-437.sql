-- ============================================================================
-- ClyoNutri — Migração Manual 45 (NI2-437 · Pré-consulta e anamnese adaptativa)
-- Equivale a database/migrations/2026_10_19_000045_create_preconsultation_tables.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-19-pre-consulta-ni2-437.sql)
-- e das migrações 43 e 44. Idempotente.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','appointments','consent_terms','patient_consents','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 7);

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `preconsultation_questionnaires` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `patient_id` INT NOT NULL,
  `professional_id` INT NOT NULL,
  `appointment_id` INT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''draft'',
  `objectives_json` TEXT NOT NULL,
  `suggestion_json` TEXT NOT NULL,
  `suggestion_ignored` INT NOT NULL DEFAULT 0,
  `questions_json` TEXT NOT NULL,
  `bank_version` VARCHAR(20) NOT NULL,
  `token_hash` VARCHAR(64) NULL,
  `token_generation` INT NOT NULL DEFAULT 0,
  `token_expires_at` DATETIME NULL,
  `sent_at` DATETIME NULL,
  `opened_at` DATETIME NULL,
  `verified_at` DATETIME NULL,
  `failed_attempts` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `answers_json` TEXT NULL,
  `draft_saved_at` DATETIME NULL,
  `submitted_at` DATETIME NULL,
  `consent_id` INT NULL,
  `reviewed_at` DATETIME NULL,
  `reviewed_by_user_id` INT NULL,
  `review_notes` TEXT NULL,
  `imported_anamnesis_id` INT NULL,
  `imported_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `created_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_precons_token` (`token_hash`),
  KEY `idx_precons_patient` (`clinic_id`, `patient_id`, `status`),
  CONSTRAINT `fk_precons_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_precons_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_precons_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`),
  CONSTRAINT `fk_precons_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `preconsultation_answer_snapshots` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` INT NOT NULL,
  `questionnaire_id` INT NOT NULL,
  `kind` VARCHAR(20) NOT NULL,
  `answers_json` TEXT NOT NULL,
  `answers_sha256` VARCHAR(64) NOT NULL,
  `saved_at` DATETIME NOT NULL,
  KEY `idx_precons_snap` (`questionnaire_id`, `saved_at`),
  CONSTRAINT `fk_precons_snap_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_precons_snap_q` FOREIGN KEY (`questionnaire_id`) REFERENCES `preconsultation_questionnaires`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Termo de consentimento (mesmo texto da migração PHP)
SET @q = IF(@ok_base = 1, CONCAT('INSERT INTO `consent_terms` (`version`, `title`, `purpose_code`, `term_text`, `is_active`) ',
  'SELECT ''2026.10-precons-r1'', ''Uso das respostas da pré-consulta'', ''pre_consultation'', ',
  '''Autorizo a clínica e o(a) nutricionista indicado(a) a tratar as informações de saúde e de hábitos que informei neste questionário, exclusivamente para preparar e realizar o meu atendimento nutricional. As respostas ficam no meu prontuário na clínica e não são compartilhadas com terceiros. Elas não substituem a consulta e não geram diagnóstico automático. Posso pedir acesso, correção ou exclusão dos meus dados à clínica, nos termos da LGPD.'', 1 ',
  'FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `consent_terms` WHERE `purpose_code` = ''pre_consultation'' AND `version` = ''2026.10-precons-r1'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('preconsultation_questionnaires','preconsultation_answer_snapshots')) = 2
  AND (SELECT COUNT(*) FROM `consent_terms` WHERE `purpose_code` = 'pre_consultation') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_19_000045_create_preconsultation_tables'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_19_000045_create_preconsultation_tables'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 45 aplicada (pré-consulta)', 'FALHA — ver preflight') AS resultado;
