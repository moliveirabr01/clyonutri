<?php
declare(strict_types=1);
/** Forward only; intentionally no active ingredient or approved regulation is seeded. */
final class CreateNutritionalPrescriptions
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $definitions = [
            'rx_policy_versions' => "id $id, clinic_id INT NOT NULL, version VARCHAR(80) NOT NULL, effective_from DATE NOT NULL, effective_until DATE NOT NULL, clinical_reviewer VARCHAR(160) NOT NULL, legal_reviewer VARCHAR(160) NOT NULL, evidence TEXT NOT NULL, signature_profile VARCHAR(40) NOT NULL, retention_days INT NOT NULL, retention_basis TEXT NOT NULL, enabled INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, UNIQUE(clinic_id,version)",
            'rx_ingredients' => "id $id, clinic_id INT NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(180) NOT NULL, category VARCHAR(40) NOT NULL, enabled INT NOT NULL DEFAULT 0, UNIQUE(clinic_id,code)",
            'rx_ingredient_rules' => "id $id, clinic_id INT NOT NULL, policy_id INT NOT NULL, ingredient_id INT NOT NULL, decision VARCHAR(16) NOT NULL, constraints_json TEXT NOT NULL, justification TEXT NOT NULL, source_url VARCHAR(1000) NOT NULL, UNIQUE(policy_id,ingredient_id)",
            'rx_qualifications' => "id $id, clinic_id INT NOT NULL, professional_id INT NOT NULL, crn VARCHAR(40) NOT NULL, crn_region VARCHAR(20) NOT NULL, registration_status VARCHAR(20) NOT NULL, phytotherapy INT NOT NULL DEFAULT 0, certificate_sha256 VARCHAR(64) NOT NULL, verified_by VARCHAR(160) NOT NULL, evidence TEXT NOT NULL, valid_until DATETIME NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL",
            'rx_prescriptions' => "id $id, clinic_id INT NOT NULL, patient_id INT NOT NULL, professional_id INT NOT NULL, appointment_id INT NULL, meal_plan_id INT NULL, root_id INT NULL, previous_id INT NULL, version_number INT NOT NULL DEFAULT 1, status VARCHAR(20) NOT NULL DEFAULT 'draft', revision INT NOT NULL DEFAULT 1, payload_json TEXT NOT NULL, snapshot_json TEXT NULL, document_pdf " . ($mysql ? 'LONGBLOB' : 'BLOB') . " NULL, pdf_sha256 VARCHAR(64) NULL, policy_id INT NULL, prepared_at DATETIME NULL, issued_at DATETIME NULL, retain_until DATE NULL, cancelled_at DATETIME NULL, cancellation_reason TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE(previous_id)",
            'rx_signatures' => "id $id, clinic_id INT NOT NULL, prescription_id INT NOT NULL UNIQUE, signature_der " . ($mysql ? 'LONGBLOB' : 'BLOB') . " NOT NULL, certificate_sha256 VARCHAR(64) NOT NULL, pdf_sha256 VARCHAR(64) NOT NULL, qualification_id INT NOT NULL, verified_at DATETIME NOT NULL, profile VARCHAR(40) NOT NULL",
            'rx_audit_events' => "id $id, clinic_id INT NOT NULL, prescription_id INT NULL, actor_type VARCHAR(20) NOT NULL, actor_id INT NULL, action VARCHAR(40) NOT NULL, metadata_json TEXT NOT NULL, created_at DATETIME NOT NULL",
            'rx_deliveries' => "id $id, clinic_id INT NOT NULL, prescription_id INT NOT NULL, patient_id INT NOT NULL, channel VARCHAR(20) NOT NULL, action VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL",
            'rx_templates' => "id $id, clinic_id INT NOT NULL, professional_id INT NOT NULL, name VARCHAR(160) NOT NULL, payload_json TEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE(clinic_id,professional_id,name)",
            'rx_followups' => "id $id, clinic_id INT NOT NULL, prescription_id INT NOT NULL, professional_id INT NOT NULL, note TEXT NOT NULL, created_at DATETIME NOT NULL",
        ];
        foreach ($definitions as $table => $columns) $db->exec("CREATE TABLE IF NOT EXISTS $table ($columns)$engine");
        foreach ([['rx_prescriptions','idx_rx_author','clinic_id,professional_id,patient_id,id'],['rx_prescriptions','idx_rx_patient','clinic_id,patient_id,status,id'],['rx_audit_events','idx_rx_audit','clinic_id,prescription_id,id'],['rx_qualifications','idx_rx_qualification','clinic_id,professional_id,id'],['rx_deliveries','idx_rx_delivery','clinic_id,prescription_id,id']] as [$table,$index,$cols]) {
            if ($mysql) {
                $q=$db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$q->execute([$table,$index]);
                if (!$q->fetchColumn()) $db->exec("CREATE INDEX $index ON $table ($cols)");
            } else $db->exec("CREATE INDEX IF NOT EXISTS $index ON $table ($cols)");
        }
    }
}
