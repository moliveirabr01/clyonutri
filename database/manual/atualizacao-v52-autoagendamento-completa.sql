-- NI2-433: migração 72, requer base existente. Não habilita remarcação imediata automaticamente.

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`booking_self_service_policies` (clinic_id INT PRIMARY KEY, direct_reschedule INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`booking_self_service_operations` (id INT AUTO_INCREMENT PRIMARY KEY,clinic_id INT NOT NULL,patient_id INT NOT NULL,idempotency_key VARCHAR(64) NOT NULL,appointment_id INT NOT NULL,from_scheduled_at VARCHAR(19) NOT NULL,to_scheduled_at VARCHAR(19) NOT NULL,result_json TEXT NOT NULL,created_at DATETIME NOT NULL,UNIQUE(clinic_id,patient_id,idempotency_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maic6824_Clyonutri2`.`booking_daily_metrics` (clinic_id INT NOT NULL,metric_date DATE NOT NULL,event_name VARCHAR(40) NOT NULL,event_count INT NOT NULL DEFAULT 0,PRIMARY KEY(clinic_id,metric_date,event_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `maic6824_Clyonutri2`.`migrations` (migration) SELECT '2026_11_01_000072_complete_patient_self_service' WHERE NOT EXISTS (SELECT 1 FROM `maic6824_Clyonutri2`.`migrations` WHERE migration='2026_11_01_000072_complete_patient_self_service');
