<?php
declare(strict_types=1);
class CreateBookingCalendarAndWaitlist
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS booking_calendar_policies (
            clinic_id INT PRIMARY KEY, buffer_before_minutes INT NOT NULL DEFAULT 0,
            buffer_after_minutes INT NOT NULL DEFAULT 0, waitlist_enabled INT NOT NULL DEFAULT 0,
            offer_ttl_minutes INT NOT NULL DEFAULT 15, updated_at DATETIME NOT NULL
        ){$engine}");
        $db->exec("CREATE TABLE IF NOT EXISTS booking_calendar_exceptions (
            id {$pk}, clinic_id INT NOT NULL, professional_id INT NULL, rule_date DATE NOT NULL,
            kind VARCHAR(16) NOT NULL, start_time VARCHAR(5) NULL, end_time VARCHAR(5) NULL,
            label VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL
        ){$engine}");
        $db->exec("CREATE TABLE IF NOT EXISTS booking_waitlist (
            id {$pk}, clinic_id INT NOT NULL, patient_id INT NOT NULL, professional_id INT NOT NULL,
            date_from DATE NOT NULL, date_to DATE NOT NULL, time_from VARCHAR(5) NOT NULL,
            time_to VARCHAR(5) NOT NULL, modality VARCHAR(16) NOT NULL, service_type VARCHAR(20) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'waiting', active_key VARCHAR(80) NULL,
            hold_id INT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE (clinic_id, active_key)
        ){$engine}");
        foreach ([['booking_calendar_exceptions','idx_booking_calendar_day','clinic_id, rule_date, professional_id'],['booking_waitlist','idx_booking_waitlist_fifo','status, created_at, id'],['booking_waitlist','idx_booking_waitlist_patient','clinic_id, patient_id, status']] as [$table,$name,$cols]) {
            if ($mysql) {
                $st=$db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$st->execute([$table,$name]);
                if (!$st->fetchColumn()) $db->exec("CREATE INDEX {$name} ON {$table} ({$cols})");
            } else $db->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})");
        }
    }
}
