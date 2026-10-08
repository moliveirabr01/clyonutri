<?php

declare(strict_types=1);

class CreatePatientDocumentsAndFilesTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("
            CREATE TABLE IF NOT EXISTS patient_documents (
                id {$pk},
                clinic_id INTEGER NOT NULL,
                patient_id INTEGER NOT NULL,
                uploaded_by_type VARCHAR(30) NOT NULL DEFAULT 'professional',
                uploaded_by_id INTEGER NOT NULL,
                title VARCHAR(255) NOT NULL,
                category VARCHAR(50) NOT NULL DEFAULT 'exam',
                file_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                access_token VARCHAR(64) UNIQUE,
                revoked_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
                FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
            ){$engine};
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_patient_documents_tenant ON patient_documents (clinic_id, patient_id);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_patient_documents_token ON patient_documents (access_token);");
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS patient_documents;");
    }
}
