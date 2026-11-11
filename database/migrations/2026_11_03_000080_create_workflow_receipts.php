<?php
declare(strict_types=1);
final class CreateWorkflowReceipts
{
    public function up(PDO $db): void
    {
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'INT AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS workflow_write_receipts (id $id, clinic_id INT NOT NULL, user_id INT NOT NULL, request_key VARCHAR(80) NOT NULL, request_sha256 CHAR(64) NOT NULL, result_json TEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,user_id,request_key))".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    }
}
