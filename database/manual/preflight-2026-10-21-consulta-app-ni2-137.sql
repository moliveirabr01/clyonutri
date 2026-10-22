-- ============================================================================
-- ClyoNutri — Preflight Somente-Leitura (NI2-137 · Próxima consulta e ações da paciente). NÃO altera dados.
-- ============================================================================
SET NAMES utf8mb4;
SET @db = DATABASE();
SET @base = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('clinics','patients','professionals','appointments','appointment_status_history','booking_settings','audit_logs') AND UPPER(ENGINE) = 'INNODB');
SET @cols = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'booking_settings'
  AND COLUMN_NAME IN ('patient_can_confirm','patient_can_cancel','patient_can_request_reschedule','patient_show_address'));
SET @hist = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_status_history' AND COLUMN_NAME = 'changed_by_patient_id');
SET @req = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointment_change_requests');
SET @m46 = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patient_goals');
SELECT
  CASE WHEN @cols = 4 AND @hist = 1 AND @req = 1 THEN 'OK — migração 47 já aplicada'
       WHEN @base = 7 THEN 'OK — PRONTO PARA MIGRAR'
       ELSE 'FALHA — tabelas base ausentes ou fora do InnoDB' END AS preflight_status,
  (@base = 7) AS pronto_para_migrar, (@cols = 4 AND @hist = 1 AND @req = 1) AS migracao_ja_aplicada, @m46 AS migracao_46_aplicada,
  (SELECT COUNT(*) FROM `booking_settings`) AS clinicas_com_regras_de_agenda,
  (SELECT COUNT(*) FROM `booking_settings` WHERE `cancel_min_advance_hours` < 1 OR `reschedule_min_advance_hours` < 1) AS clinicas_com_prazo_zero,
  (SELECT COUNT(*) FROM `appointments` WHERE `status` IN ('scheduled','confirmed')) AS consultas_futuras_ou_em_aberto,
  (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'id') AS tipo_das_chaves;
