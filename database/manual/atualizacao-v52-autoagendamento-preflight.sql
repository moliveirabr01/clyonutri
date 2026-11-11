-- Somente leitura: conferir migração 71 e tabelas canônicas da base instalada.
SELECT VERSION() AS servidor;
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN ('appointments','patients','professionals','booking_settings','booking_holds','notification_events','notification_deliveries','appointment_change_requests');
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration IN ('2026_11_01_000071_create_nutritional_prescriptions','2026_11_01_000072_complete_patient_self_service');
