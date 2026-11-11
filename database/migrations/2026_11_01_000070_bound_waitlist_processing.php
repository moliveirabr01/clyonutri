<?php
declare(strict_types=1);
class BoundWaitlistProcessing
{
    public function up(PDO $db): void
    {
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $db->exec("CREATE TABLE IF NOT EXISTS booking_waitlist_worker (
            id INT PRIMARY KEY, cursor_created VARCHAR(19) NOT NULL DEFAULT '1000-01-01 00:00:00', cursor_id INT NOT NULL DEFAULT 0,
            pass_max_id INT NOT NULL DEFAULT 0, resume_id INT NULL, resume_date VARCHAR(10) NULL,
            updated_at DATETIME NOT NULL
        )".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
        if($mysql)$db->exec("INSERT IGNORE INTO booking_waitlist_worker (id,updated_at) VALUES (1,CURRENT_TIMESTAMP)");
        else $db->exec("INSERT OR IGNORE INTO booking_waitlist_worker (id,updated_at) VALUES (1,CURRENT_TIMESTAMP)");
        $name='idx_waitlist_matching_fifo';
        if($mysql){$st=$db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$st->execute(['booking_waitlist',$name]);if(!$st->fetchColumn())$db->exec("CREATE INDEX {$name} ON booking_waitlist (clinic_id,professional_id,status,created_at,id)");}
        else $db->exec("CREATE INDEX IF NOT EXISTS {$name} ON booking_waitlist (clinic_id,professional_id,status,created_at,id)");
    }
}
