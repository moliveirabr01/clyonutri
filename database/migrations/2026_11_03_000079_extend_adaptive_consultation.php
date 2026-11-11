<?php
declare(strict_types=1);

final class ExtendAdaptiveConsultation
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec("CREATE TABLE IF NOT EXISTS clinic_protocol_shares (id $id, protocol_version_id INT NOT NULL, source_clinic_id INT NOT NULL, target_clinic_id INT NOT NULL, shared_by_user_id INT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(protocol_version_id, target_clinic_id))" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        foreach ([['clinical_protocols', 'clinic_id', 'INT NULL'],
            ['preconsultation_questionnaires', 'access_hash', 'VARCHAR(64) NULL'],
            ['preconsultation_questionnaires', 'answer_revision', 'INT NOT NULL DEFAULT 0']] as [$table, $column, $type]) {
            if ($mysql) {
                $st = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                $st->execute([$table, $column]);
                $exists = (bool)$st->fetchColumn();
            } else {
                $exists = in_array($column, array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
            }
            if (!$exists) $db->exec("ALTER TABLE $table ADD COLUMN $column $type");
        }
    }
}
