-- Banco explícito para evitar troca de contexto do phpMyAdmin para information_schema.
-- Para outro banco, substituir maic6824_Clyonutri2 em TODO este arquivo.
-- Somente leitura. Selecionar o banco correto no phpMyAdmin antes de continuar.
SELECT 'maic6824_Clyonutri2' AS banco_selecionado, VERSION() AS versao_servidor;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN
('migrations','clinics','patients','professionals','appointments','appointment_status_history','booking_settings','booking_holds','notification_events','notification_deliveries')
ORDER BY TABLE_NAME;
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN
('2026_10_31_000068_ni2_514_phase3_anthropometry_allergens_and_agenda','2026_11_01_000069_create_booking_calendar_and_waitlist');
-- Esperado: as tabelas da base existentes, tabelas de dados InnoDB, migração 68 aplicada.
-- Se migration 69 já estiver registrada, rode apenas a verificação.
