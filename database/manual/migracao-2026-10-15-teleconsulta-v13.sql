-- ============================================================================
-- ClyoNutri — Migração 2026-10-15 (v13): teleconsulta (merge v11 + Consultório Online WebRTC + link do app de uso único)
-- Rodar no phpMyAdmin com o banco do sistema selecionado (aba SQL ou Importar), DEPOIS do backup.
-- Serve para quem está na v10 (nunca rodou a SQL da v11), para quem já rodou a da v11 e para quem já rodou a da v12.
-- Idempotente: pode rodar mais de uma vez. MySQL 5.7/8 e MariaDB 10.x.
--
-- O que faz (nenhum DROP, nenhum DELETE):
--   035  teleconsultation_sessions: token_generation, token_issued_at, token_expires_at, token_revoked_at,
--        scheduled_at_snapshot, external_room_name, consent_term_id; UNIQUE(appointment_id);
--        termo de consentimento 'telehealth' versão 2026.10-r1 (consent_terms).
--   036  teleconsultation_sessions: provider_status, join_opens_at_utc, join_closes_at_utc, invite_generation,
--        invite_issued_at_utc, invite_revoked_at_utc, media_ended_at_utc; tabelas
--        teleconsultation_access_attempts (limite de tentativas) e teleconsultation_signals (sinalização WebRTC).
--        Convites antigos (anteriores à v12, sem janela de entrada) são REVOGADOS: os links antigos param de
--        funcionar e o nutricionista gera um novo na sala. Único UPDATE em dado existente (segurança).
--   037  tabela teleconsultation_note_drafts (rascunho da evolução durante a teleconsulta).
--   038  consentimentos antigos com versão solta ('telehealth:<versão>' / consent_term_version sem
--        consent_term_id) passam a apontar para um termo INATIVO daquela versão em consent_terms.
--   039  tabela teleconsultation_ticket_redemptions (código de acesso do app de uso único, v13).
--   Registra 035, 036, 037, 038 e 039 na tabela migrations.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t = CONCAT('`', @db, '`.`teleconsultation_sessions`');
SET @has_table = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions');
SET @has_terms = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'consent_terms');

SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_generation');
SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_issued_at');
SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_expires_at');
SET @c4 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'token_revoked_at');
SET @c5 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'scheduled_at_snapshot');
SET @c6 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'external_room_name');
SET @c7 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'consent_term_id');

SET @parts = CONCAT_WS(', ',
  IF(@c1 = 0, 'ADD COLUMN `token_generation` INT NOT NULL DEFAULT 1', NULL),
  IF(@c2 = 0, 'ADD COLUMN `token_issued_at` DATETIME NULL', NULL),
  IF(@c3 = 0, 'ADD COLUMN `token_expires_at` DATETIME NULL', NULL),
  IF(@c4 = 0, 'ADD COLUMN `token_revoked_at` DATETIME NULL', NULL),
  IF(@c5 = 0, 'ADD COLUMN `scheduled_at_snapshot` DATETIME NULL', NULL),
  IF(@c6 = 0, 'ADD COLUMN `external_room_name` VARCHAR(150) NULL', NULL),
  IF(@c7 = 0, 'ADD COLUMN `consent_term_id` INT NULL', NULL)
);
SET @q = IF(@has_table = 1 AND @parts <> '', CONCAT('ALTER TABLE ', @t, ' ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- UNIQUE(appointment_id): uma sessão por agendamento
SET @has_uq = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'appointment_id' AND NON_UNIQUE = 0);
SET @dups = 0;
SET @q = IF(@has_table = 1 AND @has_uq = 0, CONCAT('SELECT COUNT(*) FROM (SELECT appointment_id FROM ', @t, ' GROUP BY appointment_id HAVING COUNT(*) > 1) d INTO @dups'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@has_table = 1 AND @has_uq = 0 AND @dups = 0, CONCAT('ALTER TABLE ', @t, ' ADD UNIQUE INDEX `uq_tele_sessions_appointment` (`appointment_id`)'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Termo de consentimento da teleconsulta (texto pendente de aprovação jurídica — decisão do Maicon)
SET @q = IF(@has_terms = 1, CONCAT('INSERT INTO `', @db, '`.`consent_terms` (`version`, `title`, `purpose_code`, `term_text`, `is_active`) ',
  'SELECT ''2026.10-r1'', ''Consentimento para teleconsulta nutricional'', ''telehealth'', ',
  '''Concordo em realizar esta consulta de nutrição a distância, por vídeo, com o(a) profissional indicado(a) nesta página. Entendo que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não há exame físico) e que o(a) profissional pode recomendar uma consulta presencial. A sessão não é gravada. As anotações clínicas ficam no meu prontuário na clínica. Posso recusar este termo; nesse caso a teleconsulta não acontece e posso pedir à clínica um atendimento presencial.'', 1 ',
  'FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`consent_terms` WHERE `purpose_code` = ''telehealth'' AND `version` = ''2026.10-r1'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok = (@has_table = 1 AND @has_terms = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions'
       AND COLUMN_NAME IN ('token_generation','token_issued_at','token_expires_at','token_revoked_at','scheduled_at_snapshot','external_room_name','consent_term_id')) = 7);
SET @q = IF(@ok, CONCAT('INSERT INTO `', @db, '`.`migrations` (`migration`, `executed_at`) ',
  'SELECT ''2026_10_15_000035_harden_teleconsultation_sessions'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `', @db, '`.`migrations` WHERE `migration` = ''2026_10_15_000035_harden_teleconsultation_sessions'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------------- 036
SET @x0 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'provider_status');
SET @x1 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'join_opens_at_utc');
SET @x2 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'join_closes_at_utc');
SET @x3 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'invite_generation');
SET @x4 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'invite_issued_at_utc');
SET @x5 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'invite_revoked_at_utc');
SET @x6 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions' AND COLUMN_NAME = 'media_ended_at_utc');
SET @parts = CONCAT_WS(', ',
  IF(@x0 = 0, 'ADD COLUMN `provider_status` VARCHAR(30) NOT NULL DEFAULT ''pending''', NULL),
  IF(@x1 = 0, 'ADD COLUMN `join_opens_at_utc` DATETIME NULL', NULL),
  IF(@x2 = 0, 'ADD COLUMN `join_closes_at_utc` DATETIME NULL', NULL),
  IF(@x3 = 0, 'ADD COLUMN `invite_generation` INT NOT NULL DEFAULT 1', NULL),
  IF(@x4 = 0, 'ADD COLUMN `invite_issued_at_utc` DATETIME NULL', NULL),
  IF(@x5 = 0, 'ADD COLUMN `invite_revoked_at_utc` DATETIME NULL', NULL),
  IF(@x6 = 0, 'ADD COLUMN `media_ended_at_utc` DATETIME NULL', NULL)
);
SET @q = IF(@has_table = 1 AND @parts <> '', CONCAT('ALTER TABLE `', @db, '`.`teleconsultation_sessions` ', @parts), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `teleconsultation_access_attempts` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `bucket` VARCHAR(40) NOT NULL,
  `subject_hash` VARCHAR(64) NOT NULL,
  `attempted_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teleconsultation_signals` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `clinic_id` BIGINT NOT NULL,
  `session_id` BIGINT NOT NULL,
  `room_name` VARCHAR(150) NOT NULL,
  `sender_role` VARCHAR(20) NOT NULL,
  `signal_type` VARCHAR(20) NOT NULL,
  `payload` MEDIUMTEXT NOT NULL,
  `created_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tele_signals_session` (`session_id`),
  KEY `idx_tele_signals_clinic` (`clinic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @hi = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_signals' AND INDEX_NAME = 'idx_tele_signals_room');
SET @q = IF(@hi = 0, 'CREATE INDEX `idx_tele_signals_room` ON `teleconsultation_signals` (`room_name`, `id`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @hi = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_access_attempts' AND INDEX_NAME = 'idx_tele_attempts_lookup');
SET @q = IF(@hi = 0, 'CREATE INDEX `idx_tele_attempts_lookup` ON `teleconsultation_access_attempts` (`bucket`, `subject_hash`, `attempted_at_utc`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Convites anteriores à v12 (sem janela/geração registradas) deixam de valer.
SET @q = IF(@has_table = 1, CONCAT('UPDATE `', @db, '`.`teleconsultation_sessions` SET `invite_revoked_at_utc` = UTC_TIMESTAMP() ',
  'WHERE `invite_revoked_at_utc` IS NULL AND `invite_issued_at_utc` IS NULL AND `join_closes_at_utc` IS NULL ',
  'AND `status` NOT IN (''ended'',''rejected'',''cancelled'',''expired'',''completed'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- 037
CREATE TABLE IF NOT EXISTS `teleconsultation_note_drafts` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `clinic_id` BIGINT NOT NULL,
  `session_id` BIGINT NOT NULL,
  `professional_id` BIGINT NOT NULL,
  `title` VARCHAR(255) NULL,
  `notes` MEDIUMTEXT NULL,
  `goals` VARCHAR(1000) NULL,
  `version` INT NOT NULL DEFAULT 1,
  `updated_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tele_drafts_session_prof` (`session_id`, `professional_id`),
  KEY `idx_tele_drafts_clinic` (`clinic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------- 038
-- Versões antigas de termo vindas das sessões e de patient_consents ('telehealth:<versão>').
SET @q = IF(@has_table = 1 AND @has_terms = 1, CONCAT(
  'INSERT INTO `consent_terms` (`version`, `title`, `purpose_code`, `term_text`, `is_active`) ',
  'SELECT v.ver, CONCAT(''Consentimento para teleconsulta (versão anterior '', v.ver, '')''), ''telehealth'', ',
  '''Concordo em ser atendido(a) por teleconsulta, ciente de que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não permite exame físico) e de que o(a) nutricionista pode indicar consulta presencial quando necessário.

A chamada não é gravada. Meus dados de saúde são tratados para a prestação do atendimento nutricional, conforme a LGPD, e as anotações ficam no meu prontuário. Posso interromper a teleconsulta a qualquer momento.'', 0 FROM (',
  '  SELECT DISTINCT LEFT(`consent_term_version`, 20) AS ver FROM `teleconsultation_sessions` ',
  '   WHERE `consent_term_version` IS NOT NULL AND `consent_term_version` <> '''' AND `consent_term_id` IS NULL ',
  '  UNION SELECT DISTINCT LEFT(SUBSTRING(`consent_type`, 12), 20) FROM `patient_consents` WHERE `consent_type` LIKE ''telehealth:%'' AND LENGTH(`consent_type`) > 11',
  ') v WHERE NOT EXISTS (SELECT 1 FROM `consent_terms` t WHERE t.`purpose_code` = ''telehealth'' AND t.`version` = v.ver)'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@has_table = 1 AND @has_terms = 1,
  'UPDATE `teleconsultation_sessions` s JOIN `consent_terms` t ON t.`purpose_code` = ''telehealth'' AND t.`version` = LEFT(s.`consent_term_version`, 20) SET s.`consent_term_id` = t.`id` WHERE s.`consent_term_id` IS NULL AND s.`consent_term_version` IS NOT NULL AND s.`consent_term_version` <> ''''',
  'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(@has_terms = 1,
  'UPDATE `patient_consents` c JOIN `consent_terms` t ON t.`purpose_code` = ''telehealth'' AND t.`version` = LEFT(SUBSTRING(c.`consent_type`, 12), 20) SET c.`term_id` = t.`id`, c.`consent_type` = ''telehealth'' WHERE c.`consent_type` LIKE ''telehealth:%''',
  'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- 039 (v13)
CREATE TABLE IF NOT EXISTS `teleconsultation_ticket_redemptions` (
  `ticket_hash` CHAR(64) NOT NULL PRIMARY KEY,
  `session_id` BIGINT NOT NULL,
  `clinic_id` BIGINT NOT NULL,
  `browser_key_hash` CHAR(64) NOT NULL,
  `redeemed_at_utc` DATETIME NOT NULL,
  `expires_at_utc` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @i39 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_ticket_redemptions' AND INDEX_NAME = 'idx_tele_ticket_red_expires');
SET @q = IF(@i39 = 0, 'CREATE INDEX `idx_tele_ticket_red_expires` ON `teleconsultation_ticket_redemptions` (`expires_at_utc`)', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------- registro
SET @ok36 = (@has_table = 1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_sessions'
       AND COLUMN_NAME IN ('provider_status','join_opens_at_utc','join_closes_at_utc','invite_generation','invite_issued_at_utc','invite_revoked_at_utc','media_ended_at_utc','external_room_name')) = 8
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('teleconsultation_access_attempts','teleconsultation_signals')) = 2);
SET @ok37 = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_note_drafts') = 1);
SET @ok38 = (@has_terms = 1 AND (SELECT COUNT(*) FROM patient_consents WHERE consent_type LIKE 'telehealth:%') = 0);
SET @q = IF(@ok36, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000036_add_teleconsultation_webrtc_and_join_window'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000036_add_teleconsultation_webrtc_and_join_window'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@ok37, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000037_create_teleconsultation_note_drafts'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000037_create_teleconsultation_note_drafts'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF(@ok38, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000038_link_legacy_teleconsultation_consents_to_terms'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000038_link_legacy_teleconsultation_consents_to_terms'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @ok39 = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'teleconsultation_ticket_redemptions') = 1);
SET @q = IF(@ok39, 'INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_15_000039_create_teleconsultation_ticket_redemptions'', CURRENT_TIMESTAMP FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_15_000039_create_teleconsultation_ticket_redemptions'')', 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Conferência: deve mostrar resultado = 'OK'
SELECT CASE
  WHEN @has_table = 0 THEN 'ERRO: tabela teleconsultation_sessions não existe. Nada foi alterado.'
  WHEN @has_terms = 0 THEN 'ERRO: tabela consent_terms não existe. Envie este resultado para análise.'
  WHEN @has_uq = 0 AND @dups > 0 THEN CONCAT('ATENÇÃO: ', @dups, ' agendamento(s) com sessão repetida; o UNIQUE não foi criado. Envie este resultado.')
  WHEN @ok AND @ok36 AND @ok37 AND @ok38 AND @ok39
       AND (SELECT COUNT(*) FROM consent_terms WHERE purpose_code = 'telehealth' AND version = '2026.10-r1' AND is_active = 1) = 1
       AND (SELECT COUNT(*) FROM migrations WHERE migration IN ('2026_10_15_000035_harden_teleconsultation_sessions','2026_10_15_000036_add_teleconsultation_webrtc_and_join_window','2026_10_15_000037_create_teleconsultation_note_drafts','2026_10_15_000038_link_legacy_teleconsultation_consents_to_terms','2026_10_15_000039_create_teleconsultation_ticket_redemptions')) = 5
    THEN 'OK'
  ELSE 'ERRO: conferência falhou; envie este resultado.'
END AS resultado,
(SELECT COUNT(*) FROM teleconsultation_sessions) AS sessoes_existentes,
(SELECT COUNT(*) FROM teleconsultation_sessions WHERE invite_revoked_at_utc IS NOT NULL) AS convites_revogados,
(SELECT COUNT(*) FROM migrations WHERE migration IN ('2026_10_14_000032_create_login_attempts_table','2026_10_15_000033_convert_login_attempts_to_bucket_key_primary','2026_10_15_000034_add_taco_code_to_foods')) AS migracoes_32_33_34_previas_de_3,
(SELECT setting_value FROM platform_settings WHERE setting_key = 'feature_flag_telehealth') AS feature_flag_telehealth;
