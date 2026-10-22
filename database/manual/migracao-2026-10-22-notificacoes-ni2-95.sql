-- ============================================================================
-- ClyoNutri — Migrações Manuais 51 e 52 (NI2-95 · Notificações: avisos, preferências, fila, push, lembretes e check-in)
-- Equivale a database/migrations/2026_10_22_000051_create_notification_domain.php
--        e a database/migrations/2026_10_22_000052_create_notification_checkin_settings.php.
-- Ordem: 48 (chat NI2-138) → 49 (adesão NI2-96) → 50 (hidratação NI2-92) → esta (51 e depois 52).
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
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000051_create_notification_domain'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000051_create_notification_domain'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 9. Migração 52: lembrete do check-in diário escolhido pela paciente (opt-in, enabled = 0 por padrão).
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
SET @ok52 = @ok AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_checkin_settings') = 1
  AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'notification_checkin_settings' AND INDEX_NAME = 'uq_notification_checkin_settings') > 0;
SET @q = IF(@ok52, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000052_create_notification_checkin_settings'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000052_create_notification_checkin_settings'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok AND @ok52, 'OK — migrações 51 e 52 aplicadas (avisos, preferências, fila, push, lembrete de consulta e de check-in)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_22_000051_create_notification_domain') AS registrada_51,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_22_000052_create_notification_checkin_settings') AS registrada_52;
