-- Somente leitura: esperadas três tabelas InnoDB e migração 72 registrada.
SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN ('booking_self_service_policies','booking_self_service_operations','booking_daily_metrics') ORDER BY TABLE_NAME;
SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='maic6824_Clyonutri2' AND TABLE_NAME IN ('booking_self_service_policies','booking_self_service_operations','booking_daily_metrics') GROUP BY TABLE_NAME,INDEX_NAME;
SELECT migration FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000072_complete_patient_self_service';
SELECT clinic_id,direct_reschedule FROM `maic6824_Clyonutri2`.`booking_self_service_policies`;
-- Nenhuma linha de política é esperada na primeira instalação.
