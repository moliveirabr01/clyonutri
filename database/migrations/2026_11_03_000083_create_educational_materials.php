<?php

declare(strict_types=1);

/**
 * Migração 83: biblioteca de materiais educativos da clínica (PDF/imagem) e envio para pacientes (estilo WebDiet).
 * Arquivos ficam fora da pasta pública (storage/app/materials/<clínica>/); o banco guarda metadados, hash e envios.
 * Idempotente; aditiva.
 */
final class CreateEducationalMaterials
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'TEXT' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS educational_materials (id $id, clinic_id INT NOT NULL, title VARCHAR(160) NOT NULL, description $text NULL,
            category VARCHAR(40) NOT NULL, file_name VARCHAR(160) NOT NULL, mime_type VARCHAR(60) NOT NULL, size_bytes INT NOT NULL, sha256 CHAR(64) NOT NULL,
            storage_name VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', uploaded_by_user_id INT NOT NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$engine");
        $db->exec("CREATE TABLE IF NOT EXISTS educational_material_shares (id $id, clinic_id INT NOT NULL, material_id INT NOT NULL, patient_id INT NOT NULL,
            shared_by_user_id INT NOT NULL, note VARCHAR(500) NULL, shared_at DATETIME NOT NULL, revoked_at DATETIME NULL, revoked_by_user_id INT NULL,
            first_opened_at DATETIME NULL, UNIQUE(material_id, patient_id))$engine");
        $indexes = [
            ['educational_materials', 'idx_edm_clinic', 'clinic_id,status,category'],
            ['educational_material_shares', 'idx_edms_patient', 'clinic_id,patient_id,revoked_at'],
        ];
        foreach ($indexes as [$t, $i, $c]) {
            if ($mysql) {
                $q = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
                $q->execute([$t, $i]);
                if (!$q->fetchColumn()) {
                    $db->exec("CREATE INDEX $i ON $t ($c)");
                }
            } else {
                $db->exec("CREATE INDEX IF NOT EXISTS $i ON $t ($c)");
            }
        }
    }
}
