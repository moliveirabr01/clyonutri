<?php

declare(strict_types=1);

/**
 * Migration 49 (NI2-95): notificações — eventos, preferências e entregas.
 *
 * - notification_events: um aviso por destinatário (paciente ou profissional). É a caixa de avisos do app/web
 *   (read_at) e a origem das entregas. dedupe_key evita o mesmo aviso duas vezes (ex.: lembrete da mesma consulta).
 * - notification_deliveries: uma linha por canal externo (email, push) com status real:
 *   queued, sent, logged (driver de log), failed, skipped (com motivo) ou blocked (canal sem provedor).
 *   attempts/next_attempt_at controlam a fila drenada pelo cron de 15 min.
 * - notification_preferences: liga/desliga e-mail e push por categoria (chat, appointments, plan) e destinatário.
 * - notification_quiet_hours: horário de silêncio por destinatário (fuso da aplicação).
 *
 * A tabela antiga `notifications` (migration 4) fica como está: nunca teve código e só aceita usuários do sistema.
 */
class CreateNotificationDomain
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS notification_events (
            id {$pk},
            clinic_id INT NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            category VARCHAR(30) NOT NULL,
            recipient_type VARCHAR(20) NOT NULL,
            recipient_id INT NOT NULL,
            title VARCHAR(120) NOT NULL,
            body VARCHAR(500) NOT NULL,
            link VARCHAR(255) NULL,
            critical INT NOT NULL DEFAULT 0,
            dedupe_key VARCHAR(120) NOT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS notification_deliveries (
            id {$pk},
            clinic_id INT NOT NULL,
            event_id INT NOT NULL,
            channel VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            reason VARCHAR(60) NULL,
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES notification_events(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
            id {$pk},
            clinic_id INT NOT NULL,
            recipient_type VARCHAR(20) NOT NULL,
            recipient_id INT NOT NULL,
            category VARCHAR(30) NOT NULL,
            email_enabled INT NOT NULL DEFAULT 1,
            push_enabled INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS notification_quiet_hours (
            id {$pk},
            clinic_id INT NOT NULL,
            recipient_type VARCHAR(20) NOT NULL,
            recipient_id INT NOT NULL,
            enabled INT NOT NULL DEFAULT 0,
            start_time VARCHAR(5) NOT NULL DEFAULT '22:00',
            end_time VARCHAR(5) NOT NULL DEFAULT '07:00',
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine}");

        foreach ([
            ['notification_events', 'uq_notification_events_dedupe', 'clinic_id, recipient_type, recipient_id, dedupe_key', true],
            ['notification_events', 'idx_notification_events_inbox', 'clinic_id, recipient_type, recipient_id, id', false],
            ['notification_deliveries', 'uq_notification_deliveries_channel', 'event_id, channel', true],
            ['notification_deliveries', 'idx_notification_deliveries_queue', 'status, next_attempt_at', false],
            ['notification_preferences', 'uq_notification_preferences', 'clinic_id, recipient_type, recipient_id, category', true],
            ['notification_quiet_hours', 'uq_notification_quiet_hours', 'clinic_id, recipient_type, recipient_id', true],
        ] as [$table, $name, $cols, $unique]) {
            if (!$this->indexExists($db, $table, $name)) {
                $db->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$name} ON {$table} ({$cols})");
            }
        }
    }

    public function down(PDO $db): void
    {
        foreach (['notification_deliveries', 'notification_events', 'notification_preferences', 'notification_quiet_hours'] as $t) {
            $db->exec("DROP TABLE IF EXISTS {$t}");
        }
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
