-- Somente leitura. Banco explícito para evitar o contexto information_schema no phpMyAdmin.
SELECT 'maic6824_Clyonutri2' AS banco,VERSION() AS versao;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN ('booking_waitlist','booking_holds','booking_settings','booking_calendar_policies','notification_events','notification_deliveries');
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN ('2026_11_01_000069_create_booking_calendar_and_waitlist','2026_11_01_000070_bound_waitlist_processing');
-- Conferir migração 69 aplicada e tabelas InnoDB antes de executar a completa.
