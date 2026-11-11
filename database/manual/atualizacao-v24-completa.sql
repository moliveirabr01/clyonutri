-- ============================================================================
-- ClyoNutri v24: ATUALIZAÇÃO COMPLETA do banco (v22/v23 -> v24), migrações 42 a 55, em ordem.
-- Rode no phpMyAdmin, no banco maic6824_Clyonutri2, DEPOIS do backup e do atualizacao-v24-preflight.sql (veredito OK).
-- Idempotente: pode rodar de novo; o que já existe é pulado e o registro na tabela migrations não duplica.
-- Cada etapa abaixo é o conteúdo literal do script individual de database/manual (citado no cabeçalho da etapa).
-- Se o phpMyAdmin parar em algum erro, corrija a causa, role de novo este arquivo inteiro.
-- ============================================================================

-- >>>>>>>>>> ETAPA migração 42  (origem: database/manual/migracao-2026-10-16-autoagendamento-v20.sql)
-- ============================================================================
-- ClyoNutri — Migração manual MySQL 5.7+ / 8.0 (v20 — Autoagendamento NI2-433 / NI2-434)
-- Equivale à migration 2026_10_16_000042_create_booking_holds_and_self_service_tables.
--
-- Rode ANTES: preflight-2026-10-16-autoagendamento-v20.sql (somente leitura).
-- Idempotente: se as tabelas já existirem com a estrutura correta, nada muda.
-- Fail-closed: se existirem com a estrutura antiga (versão anterior deste arquivo),
-- nada é criado nem apagado e o resultado diz FALHA.
-- Não contém DELETE, DROP nem TRUNCATE.
--
-- Revisão 2026-10-20: a versão anterior deste arquivo usava INT UNSIGNED (o MySQL 8
-- recusava as FKs para clinics.id, que é INT) e colunas diferentes das que o código
-- usa (max_advance_days no lugar de max_future_days, sem require_cpf, payment_status,
-- notification_status). O DDL abaixo é o da migration 42, conferido em MySQL 8.0.46.
-- ============================================================================

SET NAMES utf8mb4;
SET @db = DATABASE();
-- Tipo real das chaves primárias deste banco. A produção da HostGator usa BIGINT; uma instalação
-- pelas migrations usa INT. As FKs só são aceitas pelo MySQL quando os tipos são iguais.
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_appointments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id');
SET @t_anthro = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'id');


-- Estrutura antiga (arquivo v20 anterior)? Então não segue.
SET @bs_exists  = (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings');
SET @bh_exists  = (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds');
SET @bs_ok_cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME IN ('max_future_days', 'require_cpf'));
SET @bh_ok_cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds' AND COLUMN_NAME IN ('payment_status', 'notification_status'));
SET @estrutura_antiga = (@bs_exists = 1 AND @bs_ok_cols < 2) OR (@bh_exists = 1 AND @bh_ok_cols < 2);

SET @q = IF(@estrutura_antiga, 'DO 0', '
CREATE TABLE IF NOT EXISTS `booking_holds` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `patient_id` {T_PATIENTS} NULL,
  `hold_token` VARCHAR(64) NOT NULL UNIQUE,
  `idempotency_key` VARCHAR(64) NULL,
  `scheduled_at` DATETIME NOT NULL,
  `duration_minutes` INT NOT NULL DEFAULT 60,
  `service_type` VARCHAR(50) NOT NULL DEFAULT ''first_visit'',
  `modality` VARCHAR(20) NOT NULL DEFAULT ''presential'',
  `status` VARCHAR(30) NOT NULL DEFAULT ''held'',
  `expires_at_utc` DATETIME NOT NULL,
  `appointment_id` {T_APPOINTMENTS} NULL,
  `patient_name` VARCHAR(150) NULL,
  `patient_email` VARCHAR(150) NULL,
  `patient_phone` VARCHAR(50) NULL,
  `patient_cpf` VARCHAR(20) NULL,
  `patient_notes` TEXT NULL,
  `payment_status` VARCHAR(30) NOT NULL DEFAULT ''not_required'',
  `payment_reference` VARCHAR(100) NULL,
  `teleconsultation_session_id` INT NULL,
  `notification_status` VARCHAR(30) NOT NULL DEFAULT ''pending'',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_booking_holds_slot` (`clinic_id`, `professional_id`, `scheduled_at`),
  INDEX `idx_booking_holds_status_exp` (`status`, `expires_at_utc`),
  INDEX `idx_booking_holds_idempotency` (`clinic_id`, `idempotency_key`),
  CONSTRAINT `fk_booking_holds_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_prof` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_holds_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@estrutura_antiga, 'DO 0', '
CREATE TABLE IF NOT EXISTS `booking_settings` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL UNIQUE,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `min_advance_hours` INT NOT NULL DEFAULT 2,
  `max_future_days` INT NOT NULL DEFAULT 60,
  `hold_ttl_minutes` INT NOT NULL DEFAULT 15,
  `cancel_min_advance_hours` INT NOT NULL DEFAULT 4,
  `reschedule_min_advance_hours` INT NOT NULL DEFAULT 4,
  `require_cpf` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_telehealth` TINYINT(1) NOT NULL DEFAULT 1,
  `allow_presential` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_booking_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Registra a migration 42 (assim o php bin/migrate.php não tenta criar de novo).
SET @ok = NOT @estrutura_antiga
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME IN ('max_future_days', 'require_cpf')) = 2
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_holds' AND COLUMN_NAME IN ('payment_status', 'notification_status')) = 2;
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_16_000042_create_booking_holds_and_self_service_tables'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_16_000042_create_booking_holds_and_self_service_tables'')'), 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT
  CASE
    WHEN @estrutura_antiga THEN 'FALHA — booking_settings/booking_holds existem com a estrutura antiga do v20. Nada foi alterado; fale com o suporte técnico antes de seguir.'
    WHEN @ok THEN 'OK — migração 42 aplicada (autoagendamento)'
    ELSE 'FALHA — ver preflight'
  END AS resultado,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_16_000042_create_booking_holds_and_self_service_tables') AS migracao_42_registrada;

-- >>>>>>>>>> ETAPA migração 43  (origem: database/manual/migracao-2026-10-19-planos-ni2-9.sql)
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

-- >>>>>>>>>> ETAPA migração 44  (origem: database/manual/migracao-2026-10-19-dobras-ni2-439.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 44 (NI2-439 · Dobras cutâneas por protocolo)
-- Equivale a database/migrations/2026_10_19_000044_create_skinfold_measurements.php.
-- Rodar no phpMyAdmin com o banco do sistema selecionado, DEPOIS do backup, do preflight
-- (preflight-2026-10-19-dobras-ni2-439.sql) e da migração 43 (migracao-2026-10-19-planos-ni2-9.sql).
-- Idempotente: só cria a tabela se ela não existir. Não altera nenhuma tabela existente.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
-- Tipo real das chaves primárias deste banco. A produção da HostGator usa BIGINT; uma instalação
-- pelas migrations usa INT. As FKs só são aceitas pelo MySQL quando os tipos são iguais.
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_appointments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id');
SET @t_anthro = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'id');


SET @ok_base = (
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('clinics','patients','professionals','anthropometric_assessments') AND UPPER(ENGINE) = 'INNODB') = 4
);

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `skinfold_measurements` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `assessment_id` {T_ANTHRO} NULL,
  `record_group_id` INT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `status` VARCHAR(20) NOT NULL DEFAULT ''active'',
  `supersedes_id` INT NULL,
  `correction_reason` TEXT NULL,
  `protocol_code` VARCHAR(40) NOT NULL,
  `catalog_version` VARCHAR(20) NOT NULL,
  `equation_sex` VARCHAR(10) NOT NULL,
  `age_years` INT NOT NULL,
  `assessed_at` DATETIME NOT NULL,
  `sites_json` TEXT NOT NULL,
  `sum_mm` DECIMAL(7,2) NOT NULL,
  `body_density` DECIMAL(7,5) NOT NULL,
  `body_fat_pct` DECIMAL(5,2) NOT NULL,
  `weight_kg` DECIMAL(6,2) NULL,
  `fat_mass_kg` DECIMAL(6,2) NULL,
  `lean_mass_kg` DECIMAL(6,2) NULL,
  `warnings_json` TEXT NULL,
  `provenance_json` TEXT NOT NULL,
  `notes` TEXT NULL,
  `created_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_skinfold_patient` (`clinic_id`, `patient_id`, `status`, `assessed_at`),
  INDEX `idx_skinfold_group` (`record_group_id`, `version`),
  CONSTRAINT `fk_skinfold_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skinfold_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skinfold_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`),
  CONSTRAINT `fk_skinfold_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `anthropometric_assessments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skinfold_measurements') = 1;
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_19_000044_create_skinfold_measurements'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_19_000044_create_skinfold_measurements'')'), 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 44 aplicada (skinfold_measurements)', 'FALHA — ver preflight') AS resultado;

-- >>>>>>>>>> ETAPA migração 45  (origem: database/manual/migracao-2026-10-19-pre-consulta-ni2-437.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 45 (NI2-437 · Pré-consulta e anamnese adaptativa)
-- Equivale a database/migrations/2026_10_19_000045_create_preconsultation_tables.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-19-pre-consulta-ni2-437.sql)
-- e das migrações 43 e 44. Idempotente.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
-- Tipo real das chaves primárias deste banco. A produção da HostGator usa BIGINT; uma instalação
-- pelas migrations usa INT. As FKs só são aceitas pelo MySQL quando os tipos são iguais.
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_appointments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id');
SET @t_anthro = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'anthropometric_assessments' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','appointments','consent_terms','patient_consents','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 7);

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `preconsultation_questionnaires` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `appointment_id` {T_APPOINTMENTS} NULL,
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
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `preconsultation_answer_snapshots` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `questionnaire_id` {T_CLINICS} NOT NULL,
  `kind` VARCHAR(20) NOT NULL,
  `answers_json` TEXT NOT NULL,
  `answers_sha256` VARCHAR(64) NOT NULL,
  `saved_at` DATETIME NOT NULL,
  KEY `idx_precons_snap` (`questionnaire_id`, `saved_at`),
  CONSTRAINT `fk_precons_snap_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_precons_snap_q` FOREIGN KEY (`questionnaire_id`) REFERENCES `preconsultation_questionnaires`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Termo de consentimento (mesmo texto da migração PHP)
SET @q = IF(@ok_base = 1, CONCAT('INSERT INTO `consent_terms` (`version`, `title`, `purpose_code`, `term_text`, `is_active`) ',
  'SELECT ''2026.10-precons-r1'', ''Uso das respostas da pré-consulta'', ''pre_consultation'', ',
  '''Autorizo a clínica e o(a) nutricionista indicado(a) a tratar as informações de saúde e de hábitos que informei neste questionário, exclusivamente para preparar e realizar o meu atendimento nutricional. As respostas ficam no meu prontuário na clínica e não são compartilhadas com terceiros. Elas não substituem a consulta e não geram diagnóstico automático. Posso pedir acesso, correção ou exclusão dos meus dados à clínica, nos termos da LGPD.'', 1 ',
  'FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `consent_terms` WHERE `purpose_code` = ''pre_consultation'' AND `version` = ''2026.10-precons-r1'')'), 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('preconsultation_questionnaires','preconsultation_answer_snapshots')) = 2
  AND (SELECT COUNT(*) FROM `consent_terms` WHERE `purpose_code` = 'pre_consultation') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_19_000045_create_preconsultation_tables'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_19_000045_create_preconsultation_tables'')'), 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT')), '{T_ANTHRO}', IFNULL(@t_anthro, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 45 aplicada (pré-consulta)', 'FALHA — ver preflight') AS resultado;

-- >>>>>>>>>> ETAPA migração 46  (origem: database/manual/migracao-2026-10-21-evolucao-ni2-135.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 46 (NI2-135 · Evolução: métricas liberadas por paciente e metas)
-- Equivale a database/migrations/2026_10_21_000046_create_self_metric_permissions_and_goals.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-21-evolucao-ni2-135.sql)
-- e da migração 45. Idempotente. Não altera dados existentes: nenhuma medida fica liberada até o
-- nutricionista liberar no perfil da paciente.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
-- Tipo real das chaves primárias (a produção da HostGator usa BIGINT; as FKs exigem o mesmo tipo).
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','patient_self_metrics','anthropometric_assessments','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 6);

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `patient_self_metric_permissions` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `metric_type` VARCHAR(40) NOT NULL,
  `enabled` INT NOT NULL DEFAULT 1,
  `updated_by_user_id` INT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_self_metric_perm` (`clinic_id`, `patient_id`, `metric_type`),
  CONSTRAINT `fk_self_metric_perm_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_self_metric_perm_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `patient_goals` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `metric_type` VARCHAR(40) NOT NULL,
  `target_value` DECIMAL(7,2) NOT NULL,
  `unit` VARCHAR(10) NOT NULL,
  `direction` VARCHAR(10) NOT NULL,
  `target_date` DATE NULL,
  `message_for_patient` VARCHAR(500) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''active'',
  `created_by_user_id` INT NULL,
  `closed_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `closed_at` DATETIME NULL,
  KEY `idx_patient_goals` (`clinic_id`, `patient_id`, `status`),
  CONSTRAINT `fk_patient_goals_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_patient_goals_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_patient_goals_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice para o filtro de período dos autorrelatos (só se ainda não existir).
SET @has_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_self_metrics' AND INDEX_NAME = 'idx_self_metrics_patient_date');
SET @q = IF(@ok_base = 1 AND @has_idx = 0, 'CREATE INDEX `idx_self_metrics_patient_date` ON `patient_self_metrics` (`clinic_id`, `patient_id`, `recorded_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('patient_self_metric_permissions','patient_goals')) = 2);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_21_000046_create_self_metric_permissions_and_goals'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_21_000046_create_self_metric_permissions_and_goals'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 46 aplicada (evolução: métricas liberadas e metas)', 'FALHA — ver preflight') AS resultado;

-- >>>>>>>>>> ETAPA migração 47  (origem: database/manual/migracao-2026-10-21-consulta-app-ni2-137.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 47 (NI2-137 · Próxima consulta: ações da paciente e pedidos de remarcação)
-- Equivale a database/migrations/2026_10_21_000047_create_patient_appointment_actions.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-21-consulta-app-ni2-137.sql)
-- e da migração 46. Idempotente.
-- Efeito nos dados: cada clínica passa a ter "confirmar presença" LIGADO e "cancelar" e "pedir remarcação"
-- DESLIGADOS pelo app (mudam em Configurações). Prazos de 0 hora sobem para 1 hora (mínimo novo).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_appointments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','appointments','appointment_status_history','booking_settings','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 7);

-- 1. Regras da clínica para a paciente (booking_settings).
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_can_confirm');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_can_confirm` INT NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_can_cancel');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_can_cancel` INT NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_can_request_reschedule');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_can_request_reschedule` INT NOT NULL DEFAULT 0', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings' AND COLUMN_NAME = 'patient_show_address');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `booking_settings` ADD COLUMN `patient_show_address` INT NOT NULL DEFAULT 1', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@ok_base = 1, 'UPDATE `booking_settings` SET `cancel_min_advance_hours` = GREATEST(1, `cancel_min_advance_hours`), `reschedule_min_advance_hours` = GREATEST(1, `reschedule_min_advance_hours`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Histórico: autoria da paciente.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_status_history' AND COLUMN_NAME = 'changed_by_patient_id');
SET @q = IF(@ok_base = 1 AND @has = 0, CONCAT('ALTER TABLE `appointment_status_history` ADD COLUMN `changed_by_patient_id` ', IFNULL(@t_patients, 'INT'), ' NULL'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Pedidos de remarcação.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `appointment_change_requests` (
  `id` {T_APPOINTMENTS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `appointment_id` {T_APPOINTMENTS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `request_type` VARCHAR(20) NOT NULL DEFAULT ''reschedule'',
  `status` VARCHAR(20) NOT NULL DEFAULT ''pending'',
  `from_scheduled_at` DATETIME NOT NULL,
  `requested_scheduled_at` DATETIME NOT NULL,
  `duration_minutes` INT NOT NULL,
  `reason` VARCHAR(300) NULL,
  `decided_by_user_id` INT NULL,
  `decided_at` DATETIME NULL,
  `decision_note` VARCHAR(300) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  KEY `idx_change_requests_clinic_status` (`clinic_id`, `status`, `requested_scheduled_at`),
  KEY `idx_change_requests_appointment` (`appointment_id`, `status`),
  CONSTRAINT `fk_change_requests_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_change_requests_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_change_requests_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_change_requests_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT')), '{T_APPOINTMENTS}', IFNULL(@t_appointments, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings'
    AND COLUMN_NAME IN ('patient_can_confirm','patient_can_cancel','patient_can_request_reschedule','patient_show_address')) = 4
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_status_history' AND COLUMN_NAME = 'changed_by_patient_id') = 1
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_change_requests') = 1);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_21_000047_create_patient_appointment_actions'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_21_000047_create_patient_appointment_actions'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 47 aplicada (ações da paciente na consulta e pedidos de remarcação)', 'FALHA — ver preflight') AS resultado;

-- >>>>>>>>>> ETAPA migração 48  (origem: database/manual/migracao-2026-10-22-chat-ni2-138.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 48 (NI2-138 · Canal seguro de mensagens: vínculo, guarda e notificações)
-- Equivale a database/migrations/2026_10_22_000048_create_chat_links_and_retention.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-22-chat-ni2-138.sql)
-- e da migração 47. Idempotente.
-- Efeito nos dados:
--  * cria o vínculo paciente–profissional ATIVO para cada par com consulta não cancelada, plano alimentar
--    ou resposta da profissional no chat (só paciente ativa e profissional ativa na clínica);
--    sem vínculo o chat fica somente leitura;
--  * mensagens repetidas com a mesma chave de envio na mesma conversa (se houver) recebem o sufixo
--    "-dup-<id>" na chave — o texto das mensagens não muda;
--  * prazo de guarda das mensagens: 20 anos (cada clínica muda em Configurações > Mensagens).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @t_professionals = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'professionals' AND COLUMN_NAME = 'id');
SET @t_attachments = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_attachments' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','clinic_members','appointments','meal_plans','chat_conversations','chat_messages','chat_attachments','chat_notifications_log')
  AND UPPER(ENGINE) = 'INNODB') = 10);

-- 1. Vínculo paciente–profissional.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `patient_professional_links` (
  `id` {T_PATIENTS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `professional_id` {T_PROFESSIONALS} NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''active'',
  `source` VARCHAR(30) NOT NULL DEFAULT ''manual'',
  `started_at` DATETIME NOT NULL,
  `ended_at` DATETIME NULL,
  `ended_reason` VARCHAR(200) NULL,
  `created_by_user_id` INT NULL,
  `ended_by_user_id` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  KEY `idx_links_patient` (`clinic_id`, `patient_id`, `status`),
  KEY `idx_links_professional` (`clinic_id`, `professional_id`, `status`),
  CONSTRAINT `fk_links_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_links_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT')), '{T_PROFESSIONALS}', IFNULL(@t_professionals, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Configurações de mensagens da clínica (tempo de resposta e prazo de guarda).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `chat_clinic_settings` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `response_time_label` VARCHAR(60) NULL,
  `retention_years` INT NOT NULL DEFAULT 20,
  `updated_by_user_id` INT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_chat_clinic_settings` (`clinic_id`),
  CONSTRAINT `fk_chat_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Colunas novas.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'attachment_id');
SET @q = IF(@ok_base = 1 AND @has = 0, CONCAT('ALTER TABLE `chat_messages` ADD COLUMN `attachment_id` ', IFNULL(@t_attachments, 'INT'), ' NULL'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_notifications_log' AND COLUMN_NAME = 'failure_reason');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `chat_notifications_log` ADD COLUMN `failure_reason` VARCHAR(60) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Anexo de cada mensagem (o link de download passa a usar o id do anexo).
SET @q = IF(@ok_base = 1, 'UPDATE `chat_messages` m JOIN (SELECT `message_id`, MIN(`id`) AS aid FROM `chat_attachments` WHERE `message_id` IS NOT NULL GROUP BY `message_id`) a
  ON a.`message_id` = m.`id` SET m.`attachment_id` = a.aid WHERE m.`attachment_id` IS NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Chave de envio única por conversa: repetições antigas ganham sufixo antes do índice único.
SET @q = IF(@ok_base = 1, 'UPDATE `chat_messages` m JOIN (SELECT `conversation_id`, `idempotency_key`, MIN(`id`) AS keep_id FROM `chat_messages`
  WHERE `idempotency_key` IS NOT NULL GROUP BY `conversation_id`, `idempotency_key` HAVING COUNT(*) > 1) d
  ON d.`conversation_id` = m.`conversation_id` AND d.`idempotency_key` = m.`idempotency_key`
  SET m.`idempotency_key` = CONCAT(LEFT(m.`idempotency_key`, 40), ''-dup-'', m.`id`) WHERE m.`id` <> d.keep_id', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'uq_chat_messages_idemp');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE UNIQUE INDEX `uq_chat_messages_idemp` ON `chat_messages` (`conversation_id`, `idempotency_key`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'idx_chat_messages_sender_time');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_chat_messages_sender_time` ON `chat_messages` (`conversation_id`, `sender_type`, `created_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
-- O índice antigo (não único) sai só depois que o único existe.
SET @uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'uq_chat_messages_idemp');
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'idx_chat_messages_idemp');
SET @q = IF(@uq > 0 AND @has > 0, 'ALTER TABLE `chat_messages` DROP INDEX `idx_chat_messages_idemp`', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. Vínculos iniciais pelo histórico de cuidado.
SET @links = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_professional_links');
SET @q = IF(@links = 1, 'INSERT INTO `patient_professional_links` (`clinic_id`, `patient_id`, `professional_id`, `status`, `source`, `started_at`, `created_at`, `updated_at`)
  SELECT DISTINCT x.clinic_id, x.patient_id, x.professional_id, ''active'', ''migration_care_history'', NOW(), NOW(), NOW() FROM (
      SELECT `clinic_id`, `patient_id`, `professional_id` FROM `appointments` WHERE `status` NOT IN (''cancelled'')
      UNION SELECT `clinic_id`, `patient_id`, `professional_id` FROM `meal_plans`
      UNION SELECT c.`clinic_id`, c.`patient_id`, c.`professional_id` FROM `chat_conversations` c
        WHERE EXISTS (SELECT 1 FROM `chat_messages` m WHERE m.`conversation_id` = c.`id` AND m.`sender_type` = ''professional'')
  ) x
  JOIN `patients` pa ON pa.`id` = x.patient_id AND pa.`clinic_id` = x.clinic_id AND pa.`status` = ''active''
  JOIN `professionals` pr ON pr.`id` = x.professional_id
  JOIN `clinic_members` cm ON cm.`user_id` = pr.`user_id` AND cm.`clinic_id` = x.clinic_id AND cm.`status` = ''active''
  WHERE NOT EXISTS (SELECT 1 FROM (SELECT `clinic_id`, `patient_id`, `professional_id` FROM `patient_professional_links` WHERE `status` = ''active'') l
      WHERE l.clinic_id = x.clinic_id AND l.patient_id = x.patient_id AND l.professional_id = x.professional_id)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = (@links = 1
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_clinic_settings') = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'attachment_id') = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_notifications_log' AND COLUMN_NAME = 'failure_reason') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'uq_chat_messages_idemp') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000048_create_chat_links_and_retention'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000048_create_chat_links_and_retention'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 48 aplicada (vínculo do chat, guarda das mensagens e notificações)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `patient_professional_links` WHERE `status` = 'active') AS vinculos_ativos;

-- >>>>>>>>>> ETAPA migração 49  (origem: database/manual/migracao-2026-10-22-metricas-adesao-ni2-96.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 49 (NI2-96 · Check-in diário e métricas de adesão com privacidade)
-- Equivale a database/migrations/2026_10_22_000049_create_adherence_analytics.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-22-metricas-adesao-ni2-96.sql)
-- e da migração 48. Idempotente.
-- Efeito nos dados: nenhum dado existente muda. checkins ganha a coluna updated_at e um índice; três tabelas novas
-- (contadores diários por clínica e pseudônimos do dia, sem id de paciente). A coleta já começa ligada
-- (Admin > Configurações globais > "Métricas de adesão agregadas" desliga).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');

SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','checkins','hydration_logs','platform_settings') AND UPPER(ENGINE) = 'INNODB') = 5);

-- 1. Check-in: um por dia, corrigível no mesmo dia.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'checkins' AND COLUMN_NAME = 'updated_at');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `checkins` ADD COLUMN `updated_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'checkins' AND INDEX_NAME = 'idx_checkins_patient_date');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_checkins_patient_date` ON `checkins` (`clinic_id`, `patient_id`, `checkin_date`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Contadores diários por clínica e métrica (sem id de paciente).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `analytics_daily_metrics` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `metric_date` DATE NOT NULL,
  `clinic_id` {T_CLINICS} NOT NULL,
  `metric` VARCHAR(40) NOT NULL,
  `event_count` INT NOT NULL DEFAULT 0,
  `unique_patients` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_analytics_daily_metric` (`metric_date`, `clinic_id`, `metric`),
  CONSTRAINT `fk_analytics_metrics_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Pseudônimo do dia (HMAC com sal aleatório), apagado no dia seguinte pelo agendador.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `analytics_daily_subjects` (
  `id` {T_CLINICS} AUTO_INCREMENT PRIMARY KEY,
  `metric_date` DATE NOT NULL,
  `clinic_id` {T_CLINICS} NOT NULL,
  `metric` VARCHAR(40) NOT NULL,
  `subject_hash` CHAR(64) NOT NULL,
  UNIQUE KEY `uq_analytics_daily_subject` (`metric_date`, `clinic_id`, `metric`, `subject_hash`),
  CONSTRAINT `fk_analytics_subjects_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `analytics_daily_salts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `metric_date` DATE NOT NULL,
  `salt` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_analytics_daily_salt` (`metric_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('analytics_daily_metrics','analytics_daily_subjects','analytics_daily_salts')) = 3
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'checkins' AND COLUMN_NAME = 'updated_at') = 1);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000049_create_adherence_analytics'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000049_create_adherence_analytics'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 49 aplicada (check-in diário e métricas de adesão agregadas)', 'FALHA — ver preflight') AS resultado;

-- >>>>>>>>>> ETAPA migração 50  (origem: database/manual/migracao-2026-10-23-busca-alimentos-ni2-73.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 50 (NI2-73 · Busca eficiente no catálogo de alimentos)
-- Equivale a database/migrations/2026_10_23_000050_add_food_search_text.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-23-busca-alimentos-ni2-73.sql)
-- e da migração 49. Idempotente.
-- Efeito nos dados: foods ganha a coluna search_text (nome sem acento, para a busca por termos) e dois índices
-- (search_text e name).
-- Nenhum alimento muda. O próprio sistema preenche search_text na primeira busca depois do deploy
-- (e preenche sozinho qualquer alimento novo, inclusive os do SQL de importação da TACO).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND UPPER(ENGINE) = 'INNODB') = 1);

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'search_text');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `foods` ADD COLUMN `search_text` VARCHAR(255) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_search_text');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_foods_search_text` ON `foods` (`search_text`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_name');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_foods_name` ON `foods` (`name`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'search_text') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_search_text') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_name') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_23_000050_add_food_search_text'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_23_000050_add_food_search_text'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 50 aplicada (busca de alimentos por termos)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `foods`) AS alimentos,
  (SELECT COUNT(*) FROM `foods` WHERE `search_text` IS NULL) AS a_indexar_na_primeira_busca;

-- >>>>>>>>>> ETAPA migração 51  (origem: database/manual/migracao-2026-10-22-hidratacao-ni2-92.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 51 (NI2-92 · Hidratação: histórico, correção, exclusão e idempotência)
-- Equivale a database/migrations/2026_10_24_000051_harden_hydration_logs.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-22-hidratacao-ni2-92.sql)
-- e das migrações anteriores (48 do chat; 49 é a NI2-96). Idempotente: pode rodar de novo sem efeito.
-- Efeito nos dados: nenhum registro é alterado ou apagado. Registros antigos ficam com
-- idempotency_key NULL, source 'app', updated_at/deleted_at NULL.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('hydration_logs','patients','clinics','audit_logs') AND UPPER(ENGINE) = 'INNODB') = 4);

-- 1. Colunas novas.
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'idempotency_key');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `idempotency_key` VARCHAR(64) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'source');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT ''app''', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'updated_at');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `updated_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'deleted_at');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `hydration_logs` ADD COLUMN `deleted_at` DATETIME NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Índices: único da operação (idempotência) e por dia (histórico/totais).
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND INDEX_NAME = 'uq_hydration_operation');
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND COLUMN_NAME = 'idempotency_key');
SET @q = IF(@ok_base = 1 AND @has = 0 AND @col = 1, 'CREATE UNIQUE INDEX `uq_hydration_operation` ON `hydration_logs` (`clinic_id`, `patient_id`, `idempotency_key`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs' AND INDEX_NAME = 'idx_hydration_patient_day');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_hydration_patient_day` ON `hydration_logs` (`clinic_id`, `patient_id`, `logged_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Conferência e registro na tabela migrations.
SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
    AND COLUMN_NAME IN ('idempotency_key','source','updated_at','deleted_at')) = 4
  AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
    AND INDEX_NAME IN ('uq_hydration_operation','idx_hydration_patient_day')) = 2
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'hydration_logs'
    AND INDEX_NAME = 'uq_hydration_operation' AND NON_UNIQUE = 0) = 3);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_24_000051_harden_hydration_logs'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_24_000051_harden_hydration_logs'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 51 aplicada (hidratação: histórico, correção, exclusão lógica e idempotência)', 'FALHA — ver preflight') AS resultado;

-- >>>>>>>>>> ETAPA migração 52 e 53  (origem: database/manual/migracao-2026-10-22-notificacoes-ni2-95.sql)
-- ============================================================================
-- ClyoNutri — Migrações Manuais 52 e 53 (NI2-95 · Notificações: avisos, preferências, fila, push, lembretes e check-in)
-- Equivale a database/migrations/2026_10_24_000052_create_notification_domain.php
--        e a database/migrations/2026_10_24_000053_create_notification_checkin_settings.php.
-- Ordem: 48 (chat NI2-138) → 49 (adesão NI2-96) → 50 (busca de alimentos NI2-73) → 51 (hidratação NI2-92) → esta (52 e depois 53).
-- Rodar no phpMyAdmin DEPOIS do backup e do preflight (preflight-2026-10-22-notificacoes-ni2-95.sql).
-- Idempotente: pode rodar de novo sem efeito.
-- Efeito nos dados: só cria 8 tabelas vazias e índices nelas. Nada existente é alterado.
--  * O lembrete de check-in da paciente (tabela 9) vem desligado; só liga se ela escolher no app.
--  * Preferências antigas do chat (chat_preferences) continuam valendo até a pessoa salvar as novas.
--  * A tabela antiga `notifications` não é tocada (nunca foi usada pelo sistema).
--  * Depois de aplicar, o cron passa a criar lembretes de consulta (24 h antes, ou o que a clínica escolher
--    em Configurações) e a enviar a fila. O push só sai com PUSH_ENABLED=true no .env.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
SET @t_patients = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'id');
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','users','appointments','chat_notifications_log','migrations')
  AND UPPER(ENGINE) = 'INNODB') = 7);

-- 1. Avisos (caixa de avisos de pacientes e profissionais).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `event_type` VARCHAR(60) NOT NULL,
  `category` VARCHAR(30) NOT NULL,
  `recipient_type` VARCHAR(20) NOT NULL,
  `recipient_id` INT NOT NULL,
  `title` VARCHAR(120) NOT NULL,
  `body` VARCHAR(500) NOT NULL,
  `link` VARCHAR(255) NULL,
  `critical` INT NOT NULL DEFAULT 0,
  `dedupe_key` VARCHAR(120) NOT NULL,
  `read_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_notification_events_dedupe` (`clinic_id`, `recipient_type`, `recipient_id`, `dedupe_key`),
  KEY `idx_notification_events_inbox` (`clinic_id`, `recipient_type`, `recipient_id`, `id`),
  CONSTRAINT `fk_notification_events_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Entregas por canal (e-mail, push) com status real e fila.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_deliveries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `event_id` INT NOT NULL,
  `channel` VARCHAR(20) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''queued'',
  `reason` VARCHAR(60) NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_notification_deliveries_channel` (`event_id`, `channel`),
  KEY `idx_notification_deliveries_queue` (`status`, `next_attempt_at`),
  CONSTRAINT `fk_notification_deliveries_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_deliveries_event` FOREIGN KEY (`event_id`) REFERENCES `notification_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Preferências por tipo de aviso e canal.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_preferences` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `recipient_type` VARCHAR(20) NOT NULL,
  `recipient_id` INT NOT NULL,
  `category` VARCHAR(30) NOT NULL,
  `email_enabled` INT NOT NULL DEFAULT 1,
  `push_enabled` INT NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_notification_preferences` (`clinic_id`, `recipient_type`, `recipient_id`, `category`),
  CONSTRAINT `fk_notification_preferences_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Horário de silêncio.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_quiet_hours` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `recipient_type` VARCHAR(20) NOT NULL,
  `recipient_id` INT NOT NULL,
  `enabled` INT NOT NULL DEFAULT 0,
  `start_time` VARCHAR(5) NOT NULL DEFAULT ''22:00'',
  `end_time` VARCHAR(5) NOT NULL DEFAULT ''07:00'',
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_notification_quiet_hours` (`clinic_id`, `recipient_type`, `recipient_id`),
  CONSTRAINT `fk_notification_quiet_hours_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. Aparelhos da paciente para o aviso no celular (Expo Push). Um token = um aparelho.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_push_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `recipient_type` VARCHAR(20) NOT NULL,
  `recipient_id` INT NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `platform` VARCHAR(16) NOT NULL DEFAULT ''unknown'',
  `active` INT NOT NULL DEFAULT 1,
  `last_error` VARCHAR(60) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `last_sent_at` DATETIME NULL,
  UNIQUE KEY `uq_notification_push_tokens_token` (`token`),
  KEY `idx_notification_push_tokens_owner` (`clinic_id`, `recipient_type`, `recipient_id`, `active`),
  CONSTRAINT `fk_notification_push_tokens_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. Recibos do Expo de cada push enviado (o cron confere depois de 15 min).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_push_tickets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `delivery_id` INT NOT NULL,
  `token_id` INT NOT NULL,
  `ticket_id` VARCHAR(64) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT ''pending'',
  `error` VARCHAR(60) NULL,
  `created_at` DATETIME NOT NULL,
  `checked_at` DATETIME NULL,
  KEY `idx_notification_push_tickets_receipt` (`status`, `created_at`),
  CONSTRAINT `fk_notification_push_tickets_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_push_tickets_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `notification_deliveries`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_push_tickets_token` FOREIGN KEY (`token_id`) REFERENCES `notification_push_tokens`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 7. Antecedência do lembrete de consulta (1 a 72 h). professional_id = 0 é o padrão da clínica.
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_reminder_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `professional_id` INT NOT NULL DEFAULT 0,
  `lead_hours` INT NOT NULL,
  `updated_by` INT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_notification_reminder_settings` (`clinic_id`, `professional_id`),
  CONSTRAINT `fk_notification_reminder_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 8. Índices (caso alguma tabela já exista de uma tentativa anterior sem eles).
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_events') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_events' AND INDEX_NAME = 'uq_notification_events_dedupe') = 0,
  'CREATE UNIQUE INDEX `uq_notification_events_dedupe` ON `notification_events` (`clinic_id`, `recipient_type`, `recipient_id`, `dedupe_key`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_events') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_events' AND INDEX_NAME = 'idx_notification_events_inbox') = 0,
  'CREATE INDEX `idx_notification_events_inbox` ON `notification_events` (`clinic_id`, `recipient_type`, `recipient_id`, `id`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_deliveries') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_deliveries' AND INDEX_NAME = 'uq_notification_deliveries_channel') = 0,
  'CREATE UNIQUE INDEX `uq_notification_deliveries_channel` ON `notification_deliveries` (`event_id`, `channel`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_deliveries') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_deliveries' AND INDEX_NAME = 'idx_notification_deliveries_queue') = 0,
  'CREATE INDEX `idx_notification_deliveries_queue` ON `notification_deliveries` (`status`, `next_attempt_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_preferences') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_preferences' AND INDEX_NAME = 'uq_notification_preferences') = 0,
  'CREATE UNIQUE INDEX `uq_notification_preferences` ON `notification_preferences` (`clinic_id`, `recipient_type`, `recipient_id`, `category`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_quiet_hours') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_quiet_hours' AND INDEX_NAME = 'uq_notification_quiet_hours') = 0,
  'CREATE UNIQUE INDEX `uq_notification_quiet_hours` ON `notification_quiet_hours` (`clinic_id`, `recipient_type`, `recipient_id`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_events') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_events' AND INDEX_NAME = 'idx_notification_events_retention') = 0,
  'CREATE INDEX `idx_notification_events_retention` ON `notification_events` (`read_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_push_tokens') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_push_tokens' AND INDEX_NAME = 'uq_notification_push_tokens_token') = 0,
  'CREATE UNIQUE INDEX `uq_notification_push_tokens_token` ON `notification_push_tokens` (`token`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_push_tokens') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_push_tokens' AND INDEX_NAME = 'idx_notification_push_tokens_owner') = 0,
  'CREATE INDEX `idx_notification_push_tokens_owner` ON `notification_push_tokens` (`clinic_id`, `recipient_type`, `recipient_id`, `active`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_push_tickets') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_push_tickets' AND INDEX_NAME = 'idx_notification_push_tickets_receipt') = 0,
  'CREATE INDEX `idx_notification_push_tickets_receipt` ON `notification_push_tickets` (`status`, `created_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_reminder_settings') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_reminder_settings' AND INDEX_NAME = 'uq_notification_reminder_settings') = 0,
  'CREATE UNIQUE INDEX `uq_notification_reminder_settings` ON `notification_reminder_settings` (`clinic_id`, `professional_id`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('notification_events','notification_deliveries','notification_preferences','notification_quiet_hours',
                       'notification_push_tokens','notification_push_tickets','notification_reminder_settings')) = 7
  AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db
    AND INDEX_NAME IN ('uq_notification_events_dedupe','idx_notification_events_inbox','uq_notification_deliveries_channel',
                       'idx_notification_deliveries_queue','uq_notification_preferences','uq_notification_quiet_hours',
                       'idx_notification_events_retention','uq_notification_push_tokens_token','idx_notification_push_tokens_owner',
                       'idx_notification_push_tickets_receipt','uq_notification_reminder_settings')) = 11);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_24_000052_create_notification_domain'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_24_000052_create_notification_domain'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 9. Migração 53: lembrete do check-in diário escolhido pela paciente (opt-in, enabled = 0 por padrão).
SET @q = IF(@ok_base = 1, 'CREATE TABLE IF NOT EXISTS `notification_checkin_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `clinic_id` {T_CLINICS} NOT NULL,
  `patient_id` {T_PATIENTS} NOT NULL,
  `enabled` TINYINT NOT NULL DEFAULT 0,
  `remind_time` VARCHAR(5) NOT NULL DEFAULT ''20:00'',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `uq_notification_checkin_settings` (`clinic_id`, `patient_id`),
  CONSTRAINT `fk_notification_checkin_settings_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_checkin_settings_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'DO 0');
SET @q = REPLACE(REPLACE(@q, '{T_CLINICS}', IFNULL(@t_clinics, 'INT')), '{T_PATIENTS}', IFNULL(@t_patients, 'INT'));
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_checkin_settings') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_checkin_settings' AND INDEX_NAME = 'uq_notification_checkin_settings') = 0,
  'CREATE UNIQUE INDEX `uq_notification_checkin_settings` ON `notification_checkin_settings` (`clinic_id`, `patient_id`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @ok53 = @ok AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_checkin_settings') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_checkin_settings' AND INDEX_NAME = 'uq_notification_checkin_settings') > 0;
SET @q = IF(@ok53, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_24_000053_create_notification_checkin_settings'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_24_000053_create_notification_checkin_settings'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok AND @ok53, 'OK — migrações 52 e 53 aplicadas (avisos, preferências, fila, push, lembrete de consulta e de check-in)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_24_000052_create_notification_domain') AS registrada_52,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_24_000053_create_notification_checkin_settings') AS registrada_53;

-- >>>>>>>>>> ETAPA migração 54  (origem: database/manual/migracao-2026-10-24-diario-ni2-132.sql)
-- ============================================================================
-- ClyoNutri — Migração Manual 54 (NI2-132 · Diário alimentar: conteúdo da chave de idempotência e fuso)
-- Equivale a database/migrations/2026_10_24_000054_harden_food_diary_entries.php.
-- Rodar no phpMyAdmin DEPOIS do backup e do preflight (preflight-2026-10-24-diario-ni2-132.sql). Idempotente.
-- Efeito nos dados: food_diary_entries ganha request_hash (SHA-256 do conteúdo, para devolver 409 quando a mesma
-- chave chega com outro conteúdo) e consumed_tz (fuso do aparelho da paciente), mais dois índices para a paginação.
-- Nenhum registro muda de valor: os antigos ficam com request_hash e consumed_tz nulos e continuam funcionando.
-- O código novo tolera o banco sem esta migração (ele só não grava hash/fuso até ela rodar).
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @ok_base = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND UPPER(ENGINE) = 'INNODB') = 1);

SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME = 'request_hash');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `food_diary_entries` ADD COLUMN `request_hash` CHAR(64) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME = 'consumed_tz');
SET @q = IF(@ok_base = 1 AND @has = 0, 'ALTER TABLE `food_diary_entries` ADD COLUMN `consumed_tz` VARCHAR(64) NULL', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_patient_consumed');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_food_diary_patient_consumed` ON `food_diary_entries` (`patient_id`, `clinic_id`, `consumed_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_clinic_consumed');
SET @q = IF(@ok_base = 1 AND @has = 0, 'CREATE INDEX `idx_food_diary_clinic_consumed` ON `food_diary_entries` (`clinic_id`, `consumed_at`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = ((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME IN ('request_hash', 'consumed_tz')) = 2
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_patient_consumed') > 0
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = 'idx_food_diary_clinic_consumed') > 0);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_24_000054_harden_food_diary_entries'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_24_000054_harden_food_diary_entries'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 54 aplicada (diário alimentar)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `food_diary_entries`) AS registros_no_diario,
  (SELECT COUNT(*) FROM `food_diary_entries` WHERE `request_hash` IS NULL) AS registros_antigos_sem_hash;

-- >>>>>>>>>> ETAPA migração 55  (origem: database/manual/migracao-2026-10-16-textos-ajuda-v21.sql)
-- ClyoNutri v21: textos revisados da Central de ajuda (UPDATE por slug).
-- Rodar no phpMyAdmin, banco maic6824_Clyonutri2. Idempotente: pode rodar mais de uma vez.
-- Não apaga nem cria artigos; só atualiza título, resumo e texto dos 11 artigos abaixo.
-- Equivale a database/migrations/2026_10_24_000055_update_help_center_copy_v21.php (migração 55) e registra essa migração.
SET NAMES utf8mb4;

UPDATE helpdesk_articles SET title = 'Esqueci minha senha', summary = 'Como criar uma nova senha pelo link enviado por e-mail.', content_markdown = '1. Na tela de entrada, clique em **Esqueci minha senha**.
2. Digite o e-mail da sua conta.
3. Se o e-mail tiver cadastro, você recebe um link para criar uma nova senha. O link vale por 30 minutos e só funciona uma vez.
4. Crie a nova senha, com pelo menos 8 caracteres.

Não chegou? Confira o spam e peça um novo link. Se continuar sem chegar, abra um chamado aqui na Central de ajuda.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-redefinir-minha-senha';
UPDATE helpdesk_articles SET title = 'Como montar um plano alimentar com a TACO', summary = 'Refeições, alimentos da TACO, quantidades e publicação para o paciente.', content_markdown = 'O catálogo de alimentos usa a Tabela Brasileira de Composição de Alimentos (TACO).

1. No perfil do paciente, clique em **Criar plano alimentar** e dê um nome ao plano.
2. Adicione as refeições do dia (café da manhã, almoço, lanche, jantar…).
3. Em cada refeição, busque o alimento pelo nome e informe a quantidade em gramas ou em medida caseira.
4. Os totais de energia, carboidratos, proteínas, lipídios e fibras são recalculados a cada alimento incluído.
5. Se quiser, cadastre substituições para um alimento.
6. Revise e clique em **Revisar e publicar versão**. A versão publicada aparece para o paciente no app; o rascunho, não.

Se um alimento conflitar com alergias registradas na anamnese, o editor avisa antes de você publicar.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-elaborar-um-plano-alimentar-com-taco';
UPDATE helpdesk_articles SET title = 'Como registrar uma avaliação antropométrica', summary = 'Peso, altura, circunferências e percentual de gordura informado por você.', content_markdown = '1. No perfil do paciente, clique em **Nova avaliação antropométrica**.
2. Informe peso e altura (obrigatórios). A altura pode ser digitada em centímetros (170) ou em metros (1,70); em metros, o sistema mostra a conversão e pede sua confirmação.
3. Se quiser, preencha cintura, quadril, pescoço, tórax, braço, coxa e panturrilha, o percentual de gordura e o de massa muscular.
4. O sistema calcula o IMC e a relação cintura/estatura quando a cintura é informada.
5. A avaliação fica privada. Para o paciente ver no app, marque a opção de liberar ao salvar ou use **Liberar** depois (o sistema pede confirmação). Dá para revogar a qualquer momento.

**Dobras cutâneas e protocolos de composição corporal (Pollock, Faulkner, Petroski) ainda não estão disponíveis.** Se você usa adipômetro, calcule o percentual de gordura por fora e registre o resultado no campo de percentual de gordura.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-registrar-avaliacao-antropometrica-pollock-7-dobras';
UPDATE helpdesk_articles SET title = 'Como convidar o paciente para o app', summary = 'Link, código ou QR code para o primeiro acesso do paciente.', content_markdown = '1. No perfil do paciente, clique em **Gerar convite para o app**.
2. Envie o link de ativação pelo WhatsApp (o botão já abre a conversa com a mensagem pronta) ou copie o link e mande por onde preferir. Também dá para mostrar o QR code no consultório ou passar o código de convite.
3. O paciente abre o link, confirma os dados e cria a própria senha. O convite vale por 48 horas.

Se o prazo vencer, gere um novo convite. O app funciona no navegador do celular ou do computador; não precisa instalar nada.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-convidar-paciente-para-o-aplicativo';
UPDATE helpdesk_articles SET title = 'Como preencher a anamnese', summary = 'O que a anamnese registra e como ela ajuda no plano alimentar.', content_markdown = 'A anamnese tem três blocos:

- **Alergias alimentares e restrições clínicas**
- **Rotina diária e hábitos de vida**
- **Histórico clínico e meta hídrica prescrita**

Você pode salvar como rascunho e continuar depois. Ao concluir, a anamnese é assinada e vira uma versão no histórico; as versões anteriores continuam disponíveis para consulta e comparação.

As alergias registradas aparecem em destaque no perfil e o editor do plano alimentar avisa quando um alimento conflita com elas.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-preencher-anamnese-nutricional-estruturada';
UPDATE helpdesk_articles SET title = 'O que o paciente faz no app', summary = 'Plano alimentar publicado, água e diário alimentar.', content_markdown = 'No app, o paciente:

1. **Vê o plano alimentar publicado**, com as refeições e as substituições que você cadastrou.
2. **Registra a água** do dia com um toque e acompanha a meta.
3. **Registra refeições no diário**, com foto opcional e como se sentiu de saciedade. Você vê esses registros no consultório e pode responder.

Avaliações só aparecem no app quando você as libera.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-o-paciente-registra-agua-e-refeicoes-no-app';
UPDATE helpdesk_articles SET title = 'Como os dados dos pacientes são protegidos', summary = 'Isolamento por consultório, senhas, consentimento e acesso do suporte.', content_markdown = '- **Cada consultório só vê os próprios dados.** Toda consulta ao banco é filtrada pelo consultório de quem está logado.
- **Senhas** são guardadas cifradas (bcrypt), e o site só funciona por HTTPS.
- **Consentimento:** o termo de tratamento de dados de saúde é registrado com a versão aceita, a data e o canal. O paciente pode retirar o consentimento, e isso fica no histórico.
- **Suporte:** a equipe de suporte vê os chamados que você abre, não os prontuários.

Dúvidas sobre os direitos do titular (acesso, correção, exportação)? Abra um chamado.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-o-clyonutri-garante-a-privacidade-lgpd';
UPDATE helpdesk_articles SET title = 'Como funciona a teleconsulta', summary = 'Convite, sala de espera, teste de câmera e entrada só com áudio.', content_markdown = '1. Na agenda, abra a consulta marcada como teleconsulta e entre na sala.
2. Envie o convite ao paciente por e-mail ou copie o link. O link é pessoal e serve só para essa consulta.
3. O paciente abre o link no navegador (não precisa instalar nada), testa câmera e microfone e aceita o termo da teleconsulta.
4. Ele entra na sala de espera e você admite a entrada.

A chamada é feita direto entre os dois navegadores e não é gravada.

**Sem câmera?** O paciente pode escolher **Entrar apenas com áudio** ou **Continuar sem câmera**. Você vê na sua tela qual modo ele usou.

Se a consulta for remarcada, o convite anterior deixa de valer: envie um novo.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-funciona-a-sala-de-teleconsulta-e-o-teste-de-dispositivos';
UPDATE helpdesk_articles SET title = 'Câmera ou microfone bloqueados no navegador', summary = 'Como liberar a permissão no computador e no celular.', content_markdown = 'Quando o navegador bloqueia a câmera ou o microfone, aparece um ícone de cadeado ou de câmera riscada na barra de endereço.

**No computador**
1. Clique no cadeado ao lado do endereço clyonutri.com.
2. Em **Câmera** e **Microfone**, escolha **Permitir**.
3. Recarregue a página da sala de espera.

**No celular**
Abra os Ajustes do aparelho, vá até o navegador que está usando (Chrome, Safari…), libere câmera e microfone e volte para a página.

Se mesmo assim não funcionar, o paciente pode entrar só com áudio.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'o-que-fazer-se-a-c-mera-ou-microfone-n-o-forem-autorizados-no-navegador';
UPDATE helpdesk_articles SET title = 'Como reagendar uma consulta', summary = 'Mude o dia ou o horário sem perder o histórico da consulta.', content_markdown = '1. Na **Agenda**, na linha da consulta (agendada ou confirmada), clique em **Reagendar**. No perfil do paciente, o botão fica no quadro *Próximo passo*.
2. O formulário já vem com o horário e a duração atuais. Escolha o novo dia e horário e, se quiser, anote o motivo.
3. Clique em **Confirmar reagendamento**.

**O que acontece**
- A consulta continua a mesma: paciente, tipo, observações, anamnese, avaliações e planos vinculados não mudam.
- O histórico da consulta guarda o horário anterior, o novo, o motivo e quem reagendou.
- Se o novo horário bater com outra consulta, o formulário continua aberto e preenchido, com o horário destacado. Escolha outro ou marque a opção de sobreposição de horário (encaixe).
- Não dá para reagendar para um horário que já passou, nem consultas concluídas, canceladas ou com falta.
- Teleconsulta: o convite anterior deixa de valer. Envie um novo ao paciente.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-reagendar-uma-consulta';
UPDATE helpdesk_articles SET title = 'Como orientar o paciente no primeiro acesso', summary = 'O que dizer ao paciente para ele entrar no app sem travar.', content_markdown = 'O paciente entra pelo link ou pelo QR code e cria a própria senha. Não precisa instalar nada.

Vale combinar com ele:
- O link vale por 48 horas. Se vencer, você gera outro.
- Depois do primeiro acesso, ele entra com e-mail e senha em clyonutri.com/paciente/app.
- No app ele vê o plano publicado, registra a água e as refeições do diário.
- Se esquecer a senha, ele fala com você.', updated_at = CURRENT_TIMESTAMP WHERE slug = 'como-orientar-o-paciente-no-acesso-ao-link-web-e-aplicativo';

-- Registro da migração 55 (só se a tabela migrations existir; não duplica se já estiver registrada).
SET @db = DATABASE();
SET @tem_migrations = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'migrations');
SET @q = IF(@tem_migrations = 1, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_24_000055_update_help_center_copy_v21'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_24_000055_update_help_center_copy_v21'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência: deve listar os 11 slugs com os títulos novos.
SELECT slug, title FROM helpdesk_articles WHERE slug IN ('como-redefinir-minha-senha', 'como-elaborar-um-plano-alimentar-com-taco', 'como-registrar-avaliacao-antropometrica-pollock-7-dobras', 'como-convidar-paciente-para-o-aplicativo', 'como-preencher-anamnese-nutricional-estruturada', 'como-o-paciente-registra-agua-e-refeicoes-no-app', 'como-o-clyonutri-garante-a-privacidade-lgpd', 'como-funciona-a-sala-de-teleconsulta-e-o-teste-de-dispositivos', 'o-que-fazer-se-a-c-mera-ou-microfone-n-o-forem-autorizados-no-navegador', 'como-reagendar-uma-consulta', 'como-orientar-o-paciente-no-acesso-ao-link-web-e-aplicativo');

-- >>>>>>>>>> RESUMO FINAL
SELECT m.`migration` AS migracao_registrada, m.`executed_at`
FROM `migrations` m WHERE m.`migration` >= '2026_10_16_000042' ORDER BY m.`migration`;
SELECT IF((SELECT COUNT(*) FROM `migrations` WHERE `migration` IN ('2026_10_16_000042_create_booking_holds_and_self_service_tables','2026_10_19_000043_harden_meal_plan_prescriptions','2026_10_19_000044_create_skinfold_measurements','2026_10_19_000045_create_preconsultation_tables','2026_10_21_000046_create_self_metric_permissions_and_goals','2026_10_21_000047_create_patient_appointment_actions','2026_10_22_000048_create_chat_links_and_retention','2026_10_22_000049_create_adherence_analytics','2026_10_23_000050_add_food_search_text','2026_10_24_000051_harden_hydration_logs','2026_10_24_000052_create_notification_domain','2026_10_24_000053_create_notification_checkin_settings','2026_10_24_000054_harden_food_diary_entries','2026_10_24_000055_update_help_center_copy_v21')) = 14,
  'OK — v24 completo: migrações 42 a 55 registradas', 'ATENÇÃO — alguma etapa não registrou; veja os resultados acima') AS resultado_v24;
