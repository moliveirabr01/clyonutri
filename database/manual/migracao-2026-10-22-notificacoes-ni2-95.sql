-- ============================================================================
-- ClyoNutri — Migração Manual 49 (NI2-95 · Notificações: avisos, preferências e fila)
-- Equivale a database/migrations/2026_10_22_000049_create_notification_domain.php.
-- Rodar no phpMyAdmin DEPOIS do backup, do preflight (preflight-2026-10-22-notificacoes-ni2-95.sql)
-- e da migração 48. Idempotente: pode rodar de novo sem efeito.
-- Efeito nos dados: só cria 4 tabelas vazias. Nada existente é alterado.
--  * Preferências antigas do chat (chat_preferences) continuam valendo até a pessoa salvar as novas.
--  * A tabela antiga `notifications` não é tocada (nunca foi usada pelo sistema).
--  * Depois de aplicar, o cron de 15 min passa a criar lembretes de consulta (~24 h antes) e a enviar a fila.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @t_clinics = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id');
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

-- 5. Índices (caso alguma tabela já exista de uma tentativa anterior sem eles).
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

SET @ok = ((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('notification_events','notification_deliveries','notification_preferences','notification_quiet_hours')) = 4
  AND (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db
    AND INDEX_NAME IN ('uq_notification_events_dedupe','idx_notification_events_inbox','uq_notification_deliveries_channel',
                       'idx_notification_deliveries_queue','uq_notification_preferences','uq_notification_quiet_hours')) = 6);
SET @q = IF(@ok, CONCAT('INSERT INTO `migrations` (`migration`, `executed_at`) SELECT ''2026_10_22_000049_create_notification_domain'', CURRENT_TIMESTAMP FROM DUAL ',
  'WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = ''2026_10_22_000049_create_notification_domain'')'), 'DO 0');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SELECT IF(@ok, 'OK — migração 49 aplicada (avisos, preferências e fila de notificações)', 'FALHA — ver preflight') AS resultado,
  (SELECT COUNT(*) FROM `migrations` WHERE `migration` = '2026_10_22_000049_create_notification_domain') AS registrada_em_migrations;
