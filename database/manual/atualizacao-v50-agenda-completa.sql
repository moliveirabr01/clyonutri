-- Banco explícito para evitar troca de contexto do phpMyAdmin para information_schema.
-- Para outro banco, substituir maic6824_Clyonutri2 em TODO este arquivo.
-- Clyo Nutri: calendário, buffers e lista de espera (migração 69).
-- Selecionar EXPLICITAMENTE o banco da aplicação no phpMyAdmin antes de executar.
-- Requer a v49 e a tabela migrations existentes. Fazer backup e pausar cron/operações.
-- Somente criação de tabelas novas; não altera consultas ou configurações anteriores.
CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`booking_calendar_policies` (
 clinic_id INT PRIMARY KEY,
 buffer_before_minutes INT NOT NULL DEFAULT 0,
 buffer_after_minutes INT NOT NULL DEFAULT 0,
 waitlist_enabled INT NOT NULL DEFAULT 0,
 offer_ttl_minutes INT NOT NULL DEFAULT 15,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`booking_calendar_exceptions` (
 id INT AUTO_INCREMENT PRIMARY KEY,
 clinic_id INT NOT NULL,
 professional_id INT NULL,
 rule_date DATE NOT NULL,
 kind VARCHAR(16) NOT NULL,
 start_time VARCHAR(5) NULL,
 end_time VARCHAR(5) NULL,
 label VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL,
 KEY idx_booking_calendar_day (clinic_id,rule_date,professional_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`booking_waitlist` (
 id INT AUTO_INCREMENT PRIMARY KEY,
 clinic_id INT NOT NULL,
 patient_id INT NOT NULL,
 professional_id INT NOT NULL,
 date_from DATE NOT NULL,
 date_to DATE NOT NULL,
 time_from VARCHAR(5) NOT NULL,
 time_to VARCHAR(5) NOT NULL,
 modality VARCHAR(16) NOT NULL,
 service_type VARCHAR(20) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'waiting',
 active_key VARCHAR(80) NULL,
 hold_id INT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_booking_waitlist_active (clinic_id,active_key),
 KEY idx_booking_waitlist_fifo (status,created_at,id),
 KEY idx_booking_waitlist_patient (clinic_id,patient_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `maic6824_Clyonutri2`.`migrations` (migration)
SELECT '2026_11_01_000069_create_booking_calendar_and_waitlist'
WHERE NOT EXISTS (SELECT 1 FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000069_create_booking_calendar_and_waitlist');
SELECT 'Migração 69 registrada. Executar a verificação.' AS resultado, 'maic6824_Clyonutri2' AS banco;
