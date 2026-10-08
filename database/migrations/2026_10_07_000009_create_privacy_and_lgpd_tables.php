<?php

declare(strict_types=1);

class CreatePrivacyAndLgpdTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Termos de Consentimento e Políticas Versionadas
        $db->exec("CREATE TABLE IF NOT EXISTS consent_terms (
            id {$pk},
            version VARCHAR(20) NOT NULL,
            title VARCHAR(255) NOT NULL,
            purpose_code VARCHAR(100) NOT NULL, -- clinical_care, telehealth, communications, data_processing
            term_text TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (purpose_code, version)
        ){$engine};");

        // 2. Adicionar colunas complementares a patient_consents se não existirem
        $cols = [
            'term_id' => 'INTEGER NULL',
            'revoked_at' => 'DATETIME NULL',
            'revocation_reason' => 'TEXT NULL',
            'source_channel' => 'VARCHAR(50) DEFAULT "web"',
            'user_agent' => 'TEXT NULL',
        ];

        foreach ($cols as $col => $def) {
            try {
                $db->exec("ALTER TABLE patient_consents ADD COLUMN {$col} {$def};");
            } catch (\Throwable $e) {
                // Coluna já existe
            }
        }

        // 3. Workflow de Solicitações dos Titulares (LGPD Art. 18)
        $db->exec("CREATE TABLE IF NOT EXISTS data_subject_requests (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            request_type VARCHAR(50) NOT NULL, -- access, export, rectification, anonymization, deletion
            status VARCHAR(50) NOT NULL DEFAULT 'pending', -- pending, in_review, completed, rejected_legal_hold
            details TEXT NULL,
            legal_justification TEXT NULL,
            reviewed_by INTEGER NULL,
            response_notes TEXT NULL,
            deadline_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ){$engine};");

        // 4. Anexos Clínicos com Storage Seguro e Isolamento
        $db->exec("CREATE TABLE IF NOT EXISTS clinical_attachments (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size_bytes INTEGER NOT NULL,
            storage_path VARCHAR(255) NOT NULL,
            hash_sha256 VARCHAR(64) NOT NULL,
            is_private INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine};");
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS clinical_attachments;");
        $db->exec("DROP TABLE IF EXISTS data_subject_requests;");
        $db->exec("DROP TABLE IF EXISTS consent_terms;");
    }
}
