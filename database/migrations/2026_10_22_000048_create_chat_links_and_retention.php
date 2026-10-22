<?php

declare(strict_types=1);

/**
 * Migration 48 (NI2-138): canal seguro de mensagens.
 *
 * - patient_professional_links: vínculo paciente–profissional que libera o chat (ativo/encerrado, com histórico).
 *   Semeado pelo histórico de cuidado: profissional de consulta não cancelada, de plano alimentar ou que já
 *   respondeu no chat, desde que siga ativo na clínica.
 * - chat_clinic_settings: tempo de resposta informado à paciente e prazo de guarda (padrão 20 anos).
 * - chat_messages.attachment_id: o anexo da mensagem (o link de download usa este id, não o da mensagem).
 * - chat_messages: (conversation_id, idempotency_key) passa a ser ÚNICO (repetições antigas são renomeadas).
 * - chat_notifications_log.failure_reason: motivo real quando o canal não entregou.
 */
class CreateChatLinksAndRetention
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS patient_professional_links (
            id {$pk},
            clinic_id INT NOT NULL,
            patient_id INT NOT NULL,
            professional_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            source VARCHAR(30) NOT NULL DEFAULT 'manual',
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            ended_reason VARCHAR(200) NULL,
            created_by_user_id INT NULL,
            ended_by_user_id INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS chat_clinic_settings (
            id {$pk},
            clinic_id INT NOT NULL,
            response_time_label VARCHAR(60) NULL,
            retention_years INT NOT NULL DEFAULT 20,
            updated_by_user_id INT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine}");

        if (!$this->columnExists($db, 'chat_messages', 'attachment_id')) {
            $db->exec('ALTER TABLE chat_messages ADD COLUMN attachment_id INT NULL');
        }
        if (!$this->columnExists($db, 'chat_notifications_log', 'failure_reason')) {
            $db->exec('ALTER TABLE chat_notifications_log ADD COLUMN failure_reason VARCHAR(60) NULL');
        }
        $db->exec('UPDATE chat_messages SET attachment_id = (SELECT MIN(a.id) FROM chat_attachments a WHERE a.message_id = chat_messages.id)
            WHERE attachment_id IS NULL AND EXISTS (SELECT 1 FROM chat_attachments a2 WHERE a2.message_id = chat_messages.id)');

        // Chave de idempotência única por conversa: as repetições antigas ganham sufixo antes do índice único.
        $dups = $db->query('SELECT conversation_id, idempotency_key FROM chat_messages WHERE idempotency_key IS NOT NULL
            GROUP BY conversation_id, idempotency_key HAVING COUNT(*) > 1')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dups as $d) {
            $ids = $db->prepare('SELECT id FROM chat_messages WHERE conversation_id = ? AND idempotency_key = ? ORDER BY id');
            $ids->execute([$d['conversation_id'], $d['idempotency_key']]);
            $all = $ids->fetchAll(PDO::FETCH_COLUMN);
            array_shift($all);
            foreach ($all as $id) {
                $db->prepare('UPDATE chat_messages SET idempotency_key = ? WHERE id = ?')
                    ->execute([substr((string)$d['idempotency_key'], 0, 40) . '-dup-' . $id, $id]);
            }
        }

        foreach ([
            ['chat_messages', 'uq_chat_messages_idemp', 'conversation_id, idempotency_key', true],
            ['chat_clinic_settings', 'uq_chat_clinic_settings', 'clinic_id', true],
            ['patient_professional_links', 'idx_links_patient', 'clinic_id, patient_id, status', false],
            ['patient_professional_links', 'idx_links_professional', 'clinic_id, professional_id, status', false],
            ['chat_messages', 'idx_chat_messages_sender_time', 'conversation_id, sender_type, created_at', false],
        ] as [$table, $name, $cols, $unique]) {
            if (!$this->indexExists($db, $table, $name)) {
                $db->exec(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . "{$name} ON {$table} ({$cols})");
            }
        }

        // O índice antigo (não único) sai depois que o único existe (no MySQL a FK precisa de um índice).
        if ($this->indexExists($db, 'chat_messages', 'idx_chat_messages_idemp')) {
            $db->exec($mysql ? 'ALTER TABLE chat_messages DROP INDEX idx_chat_messages_idemp' : 'DROP INDEX idx_chat_messages_idemp');
        }

        // Vínculos iniciais pelo histórico de cuidado (só profissional ativo na clínica e paciente ativa).
        $now = date('Y-m-d H:i:s');
        $seed = $db->query("
            SELECT DISTINCT x.clinic_id, x.patient_id, x.professional_id FROM (
                SELECT clinic_id, patient_id, professional_id FROM appointments WHERE status NOT IN ('cancelled')
                UNION SELECT clinic_id, patient_id, professional_id FROM meal_plans
                UNION SELECT c.clinic_id, c.patient_id, c.professional_id FROM chat_conversations c
                    WHERE EXISTS (SELECT 1 FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender_type = 'professional')
            ) x
            JOIN patients pa ON pa.id = x.patient_id AND pa.clinic_id = x.clinic_id AND pa.status = 'active'
            JOIN professionals pr ON pr.id = x.professional_id
            JOIN clinic_members cm ON cm.user_id = pr.user_id AND cm.clinic_id = x.clinic_id AND cm.status = 'active'
        ")->fetchAll(PDO::FETCH_ASSOC);
        $exists = $db->prepare("SELECT 1 FROM patient_professional_links WHERE clinic_id = ? AND patient_id = ? AND professional_id = ? AND status = 'active'");
        $ins = $db->prepare("INSERT INTO patient_professional_links (clinic_id, patient_id, professional_id, status, source, started_at, created_at, updated_at)
            VALUES (?, ?, ?, 'active', 'migration_care_history', ?, ?, ?)");
        foreach ($seed as $s) {
            $exists->execute([$s['clinic_id'], $s['patient_id'], $s['professional_id']]);
            if (!$exists->fetchColumn()) {
                $ins->execute([$s['clinic_id'], $s['patient_id'], $s['professional_id'], $now, $now, $now]);
            }
        }
    }

    private function columnExists(PDO $db, string $table, string $column): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $st->execute(['t' => $table, 'c' => $column]);
        return (int)$st->fetchColumn() > 0;
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
