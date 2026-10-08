<?php

declare(strict_types=1);

/**
 * Migration 19: Aperfeiçoamento do Canal Seguro de Comunicação e Engajamento (NI2-189)
 * Implementa idempotência anti-duplicação, anexos privados fora do webroot,
 * notificações com preservação de privacidade e controle de entrega/leitura.
 */
class EnhanceChatSecureMessagingTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Adicionar colunas em chat_messages se não existirem
        $existingCols = [];
        if ($driver === 'sqlite') {
            $cols = $db->query("PRAGMA table_info(chat_messages)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $existingCols[] = $col['name'];
            }
        } else {
            $stmt = $db->query("SHOW COLUMNS FROM chat_messages");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existingCols[] = $row['Field'];
            }
        }

        if (!in_array('idempotency_key', $existingCols, true)) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN idempotency_key VARCHAR(64) NULL;");
        }
        if (!in_array('status', $existingCols, true)) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sent';");
        }
        if (!in_array('delivered_at', $existingCols, true)) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN delivered_at DATETIME NULL;");
        }
        if (!in_array('attachment_name', $existingCols, true)) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN attachment_name VARCHAR(255) NULL;");
        }
        if (!in_array('attachment_mime', $existingCols, true)) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN attachment_mime VARCHAR(100) NULL;");
        }
        if (!in_array('attachment_size', $existingCols, true)) {
            $db->exec("ALTER TABLE chat_messages ADD COLUMN attachment_size INTEGER NULL;");
        }

        // Criar índice de busca rápida e idempotência
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_messages_idemp ON chat_messages(conversation_id, idempotency_key);");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_messages_created ON chat_messages(conversation_id, id);");
        } catch (\Throwable $e) {
            // Ignora se já existir
        }

        // 2. Tabela de Anexos Privados Protegidos
        $db->exec("CREATE TABLE IF NOT EXISTS chat_attachments (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            conversation_id INTEGER NOT NULL,
            message_id INTEGER NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256_hash VARCHAR(64) NOT NULL,
            storage_path VARCHAR(255) NOT NULL,
            uploaded_by_type VARCHAR(20) NOT NULL, -- professional, patient
            uploaded_by_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
        ){$engine};");

        // 3. Tabela de Log de Notificações com Garantia de Zero Dados Clínicos
        $db->exec("CREATE TABLE IF NOT EXISTS chat_notifications_log (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            conversation_id INTEGER NOT NULL,
            message_id INTEGER NOT NULL,
            recipient_type VARCHAR(20) NOT NULL, -- professional, patient
            recipient_id INTEGER NOT NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'in_app', -- in_app, email, push
            title VARCHAR(255) NOT NULL,
            body_preview VARCHAR(255) NOT NULL,
            contains_clinical_data INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
        ){$engine};");

        // 4. Tabela de Preferências de Notificação e Horários de Silêncio
        $db->exec("CREATE TABLE IF NOT EXISTS chat_preferences (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            user_type VARCHAR(20) NOT NULL, -- professional, patient
            user_id INTEGER NOT NULL,
            email_enabled INTEGER NOT NULL DEFAULT 1,
            push_enabled INTEGER NOT NULL DEFAULT 1,
            quiet_hours_enabled INTEGER NOT NULL DEFAULT 0,
            quiet_hours_start VARCHAR(5) NULL DEFAULT '22:00',
            quiet_hours_end VARCHAR(5) NULL DEFAULT '07:00',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine};");
    }
}
