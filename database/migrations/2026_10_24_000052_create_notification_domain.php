<?php

declare(strict_types=1);

/**
 * Migration 52 (NI2-95): notificações — eventos, preferências, entregas, push e lembrete configurável.
 * (Numeração: 48 = chat NI2-138, 49 = adesão NI2-96, 50 = hidratação NI2-92.)
 *
 * - notification_events: um aviso por destinatário (paciente ou profissional). É a caixa de avisos do app/web
 *   (read_at) e a origem das entregas. dedupe_key evita o mesmo aviso duas vezes (ex.: lembrete da mesma consulta).
 * - notification_deliveries: uma linha por canal externo (email, push) com status real:
 *   queued, sent, logged (driver de log), failed, skipped (com motivo) ou blocked (canal sem provedor).
 *   attempts/next_attempt_at controlam a fila drenada pelo cron (a cada minuto).
 * - notification_preferences: liga/desliga e-mail e push por categoria (chat, appointments, plan) e destinatário.
 * - notification_quiet_hours: horário de silêncio por destinatário (fuso da aplicação).
 * - notification_push_tokens: aparelhos da paciente no Expo Push (um token = um aparelho). active=0 quando a paciente
 *   sai do app ou o Expo responde DeviceNotRegistered.
 * - notification_push_tickets: recibo de cada push enviado (ticket do Expo), conferido pelo cron depois de 15 min.
 * - notification_reminder_settings: antecedência do lembrete de consulta (1 a 72 h) da clínica (professional_id = 0)
 *   ou de um profissional. Sem linha, vale NOTIFICATION_APPOINTMENT_REMINDER_HOURS (padrão 24).
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

        $db->exec("CREATE TABLE IF NOT EXISTS notification_push_tokens (
            id {$pk},
            clinic_id INT NOT NULL,
            recipient_type VARCHAR(20) NOT NULL,
            recipient_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            platform VARCHAR(16) NOT NULL DEFAULT 'unknown',
            active INT NOT NULL DEFAULT 1,
            last_error VARCHAR(60) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_sent_at DATETIME NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS notification_push_tickets (
            id {$pk},
            clinic_id INT NOT NULL,
            delivery_id INT NOT NULL,
            token_id INT NOT NULL,
            ticket_id VARCHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error VARCHAR(60) NULL,
            created_at DATETIME NOT NULL,
            checked_at DATETIME NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (delivery_id) REFERENCES notification_deliveries(id) ON DELETE CASCADE,
            FOREIGN KEY (token_id) REFERENCES notification_push_tokens(id) ON DELETE CASCADE
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS notification_reminder_settings (
            id {$pk},
            clinic_id INT NOT NULL,
            professional_id INT NOT NULL DEFAULT 0,
            lead_hours INT NOT NULL,
            updated_by INT NULL,
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
            ['notification_events', 'idx_notification_events_retention', 'read_at', false],
            ['notification_push_tokens', 'uq_notification_push_tokens_token', 'token', true],
            ['notification_push_tokens', 'idx_notification_push_tokens_owner', 'clinic_id, recipient_type, recipient_id, active', false],
            ['notification_push_tickets', 'idx_notification_push_tickets_receipt', 'status, created_at', false],
            ['notification_reminder_settings', 'uq_notification_reminder_settings', 'clinic_id, professional_id', true],
        ] as [$table, $name, $cols, $unique]) {
            if (!$this->indexExists($db, $table, $name)) {
                $db->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$name} ON {$table} ({$cols})");
            }
        }
    }

    public function down(PDO $db): void
    {
        foreach (['notification_push_tickets', 'notification_push_tokens', 'notification_reminder_settings', 'notification_deliveries', 'notification_events', 'notification_preferences', 'notification_quiet_hours'] as $t) {
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
