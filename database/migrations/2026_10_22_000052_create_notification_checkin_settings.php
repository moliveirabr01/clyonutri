<?php

declare(strict_types=1);

/**
 * Migration 52 (NI2-95): lembrete do check-in diário (NI2-96), escolhido pela própria paciente no app.
 *
 * - notification_checkin_settings: uma linha por paciente. enabled = 0 por padrão (o lembrete é opt-in) e
 *   remind_time (HH:MM, fuso da aplicação) é a hora em que ela quer ser lembrada, se ainda não fez o check-in do dia.
 *   Sem linha = lembrete desligado.
 *
 * O aviso para o profissional quando chega check-in novo não precisa de tabela: usa notification_events e a
 * preferência de e-mail da categoria "checkin" em notification_preferences (migration 51).
 */
class CreateNotificationCheckinSettings
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS notification_checkin_settings (
            id {$pk},
            clinic_id INT NOT NULL,
            patient_id INT NOT NULL,
            enabled TINYINT NOT NULL DEFAULT 0,
            remind_time VARCHAR(5) NOT NULL DEFAULT '20:00',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ){$engine}");

        if (!$this->indexExists($db, 'notification_checkin_settings', 'uq_notification_checkin_settings')) {
            $db->exec('CREATE UNIQUE INDEX uq_notification_checkin_settings ON notification_checkin_settings (clinic_id, patient_id)');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS notification_checkin_settings');
    }

    private function indexExists(PDO $db, string $table, string $index): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :n");
            $st->execute(['n' => $index]);
            return (bool)$st->fetchColumn();
        }
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :n');
        $st->execute(['t' => $table, 'n' => $index]);
        return (int)$st->fetchColumn() > 0;
    }
}
