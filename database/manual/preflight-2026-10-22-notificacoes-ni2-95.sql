-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-95 · Migração 51: avisos, preferências, fila, push e lembrete). NÃO altera dados.
-- Rodar no phpMyAdmin antes de migracao-2026-10-22-notificacoes-ni2-95.sql.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','users','appointments','chat_notifications_log','migrations')
  AND UPPER(ENGINE) = 'INNODB');
SET @novas = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('notification_events','notification_deliveries','notification_preferences','notification_quiet_hours',
                     'notification_push_tokens','notification_push_tickets','notification_reminder_settings'));
SET @m49 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'migrations');
SET @q = IF(@m49 = 1, 'SELECT COUNT(*) INTO @m49 FROM `migrations` WHERE `migration` LIKE ''%000049%'' AND `migration` NOT LIKE ''%notification_domain%''', 'SELECT 0 INTO @m49');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @m50 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'migrations');
SET @q = IF(@m50 = 1, 'SELECT COUNT(*) INTO @m50 FROM `migrations` WHERE `migration` LIKE ''%000050%''', 'SELECT 0 INTO @m50');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @m48 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_professional_links');
SET @chatprefs = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chat_preferences');
SET @q = IF(@chatprefs = 1, 'SELECT COUNT(*) INTO @chat_prefs_salvas FROM `chat_preferences`', 'SELECT 0 INTO @chat_prefs_salvas');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SELECT
  CASE WHEN @novas = 7 THEN 'OK — migração 51 já aplicada'
       WHEN @novas > 0 THEN 'ATENÇÃO — migração 51 aplicada pela metade ou versão antiga (rodar a migração de novo completa o que falta)'
       WHEN @base = 7 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB' END AS preflight_status,
  (@base = 7) AS pronto_para_migrar,
  @novas AS tabelas_de_aviso_existentes,
  @m48 AS migracao_48_aplicada,
  (@m49 > 0) AS migracao_49_registrada,
  (@m50 > 0) AS migracao_50_registrada,
  @chat_prefs_salvas AS preferencias_antigas_do_chat,
  (SELECT COUNT(*) FROM `appointments` WHERE `status` IN ('scheduled','confirmed')
     AND `scheduled_at` > NOW() + INTERVAL 15 MINUTE AND `scheduled_at` <= NOW() + INTERVAL 24 HOUR) AS lembretes_no_primeiro_ciclo_do_cron,
  (SELECT COUNT(*) FROM `notifications`) AS linhas_na_tabela_antiga_notifications,
  (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clinics' AND COLUMN_NAME = 'id') AS tipo_da_chave_clinics,
  @@time_zone AS fuso_do_mysql, NOW() AS agora_no_mysql;
