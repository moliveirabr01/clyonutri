<?php
declare(strict_types=1);
final class CreateCareProgressEvents
{
    public function up(PDO $db): void
    {
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS care_progress_events (id $id, clinic_id INT NOT NULL, appointment_id INT NOT NULL, user_id INT NOT NULL, step_key VARCHAR(24) NOT NULL, decision VARCHAR(24) NOT NULL, reason_code VARCHAR(40) NOT NULL, evidence_sha256 CHAR(64) NOT NULL, request_key VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,appointment_id,request_key))".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
        $db->exec("CREATE TABLE IF NOT EXISTS care_workflow_policies (id $id, clinic_id INT NOT NULL, user_id INT NOT NULL, consultation_type VARCHAR(24) NOT NULL, policy_json TEXT NOT NULL, request_key VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,request_key))".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    }
}
