<?php

declare(strict_types=1);

/**
 * Migration 18: Criação da Central de Ajuda e Service Desk (NI2-195 a NI2-208)
 */
class CreateHelpdeskAndServiceDeskTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Base de Conhecimento: Artigos e FAQs
        $db->exec("CREATE TABLE IF NOT EXISTS helpdesk_articles (
            id {$pk},
            category VARCHAR(60) NOT NULL,
            slug VARCHAR(150) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            summary TEXT NOT NULL,
            content_markdown TEXT NOT NULL,
            is_published INTEGER NOT NULL DEFAULT 1,
            view_count INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // 2. Chamados / Tickets de Suporte
        $db->exec("CREATE TABLE IF NOT EXISTS helpdesk_tickets (
            id {$pk},
            protocol VARCHAR(40) NOT NULL UNIQUE,
            clinic_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'question',
            category VARCHAR(60) NOT NULL,
            priority_perceived VARCHAR(20) NOT NULL DEFAULT 'medium',
            priority_operational VARCHAR(10) NOT NULL DEFAULT 'P2',
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            subject VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            assigned_to_user_id INTEGER NULL,
            first_response_at DATETIME NULL,
            resolved_at DATETIME NULL,
            closed_at DATETIME NULL,
            reopened_at DATETIME NULL,
            resolution_summary TEXT NULL,
            origin_route VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");

        // 3. Mensagens e Timeline do Chamado (Interação + Notas Internas)
        $db->exec("CREATE TABLE IF NOT EXISTS helpdesk_ticket_messages (
            id {$pk},
            ticket_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            is_internal_note INTEGER NOT NULL DEFAULT 0,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");

        // 4. Anexos Seguros (Fora do webroot)
        $db->exec("CREATE TABLE IF NOT EXISTS helpdesk_attachments (
            id {$pk},
            ticket_id INTEGER NOT NULL,
            message_id INTEGER NULL,
            file_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INTEGER NOT NULL,
            uploaded_by_user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");

        // 5. Avaliação CSAT pós-resolução
        $db->exec("CREATE TABLE IF NOT EXISTS helpdesk_ticket_feedbacks (
            id {$pk},
            ticket_id INTEGER NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            rating INTEGER NOT NULL,
            comment TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");

        // 6. Notificações In-App de Chamados
        $db->exec("CREATE TABLE IF NOT EXISTS helpdesk_notifications (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            ticket_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            body VARCHAR(255) NOT NULL,
            read_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE
        ){$engine};");

        // 7. Auditoria Operacional
        $db->exec("CREATE TABLE IF NOT EXISTS helpdesk_audit_events (
            id {$pk},
            ticket_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            payload_json TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");

        // 8. Índices de Desempenho e Agregação de SLAs e Indicadores (Ponto 8)
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hd_tickets_status ON helpdesk_tickets(status, created_at);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hd_tickets_priority ON helpdesk_tickets(priority_operational, status);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hd_tickets_clinic ON helpdesk_tickets(clinic_id, status);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hd_tickets_first_resp ON helpdesk_tickets(first_response_at);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hd_tickets_resolved ON helpdesk_tickets(resolved_at);");
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS helpdesk_audit_events;");
        $db->exec("DROP TABLE IF EXISTS helpdesk_notifications;");
        $db->exec("DROP TABLE IF EXISTS helpdesk_ticket_feedbacks;");
        $db->exec("DROP TABLE IF EXISTS helpdesk_attachments;");
        $db->exec("DROP TABLE IF EXISTS helpdesk_ticket_messages;");
        $db->exec("DROP TABLE IF EXISTS helpdesk_tickets;");
        $db->exec("DROP TABLE IF EXISTS helpdesk_articles;");
    }
}
