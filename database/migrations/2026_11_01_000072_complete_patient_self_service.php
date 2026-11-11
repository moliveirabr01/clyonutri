<?php
declare(strict_types=1);
final class CompletePatientSelfService
{
    public function up(PDO $db): void
    {
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec("CREATE TABLE IF NOT EXISTS booking_self_service_policies (clinic_id INT PRIMARY KEY, direct_reschedule INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL)$engine");
        $db->exec("CREATE TABLE IF NOT EXISTS booking_self_service_operations (id $id,clinic_id INT NOT NULL,patient_id INT NOT NULL,idempotency_key VARCHAR(64) NOT NULL,appointment_id INT NOT NULL,from_scheduled_at VARCHAR(19) NOT NULL,to_scheduled_at VARCHAR(19) NOT NULL,result_json TEXT NOT NULL,created_at DATETIME NOT NULL,UNIQUE(clinic_id,patient_id,idempotency_key))$engine");
        $db->exec("CREATE TABLE IF NOT EXISTS booking_daily_metrics (clinic_id INT NOT NULL,metric_date DATE NOT NULL,event_name VARCHAR(40) NOT NULL,event_count INT NOT NULL DEFAULT 0,PRIMARY KEY(clinic_id,metric_date,event_name))$engine");
    }
}
