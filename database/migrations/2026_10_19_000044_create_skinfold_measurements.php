<?php

declare(strict_types=1);

/**
 * Migration 44 (NI2-439): dobras cutâneas por protocolo validado.
 *
 * skinfold_measurements guarda cada medição de forma imutável: leituras por dobra, protocolo e versão do
 * catálogo usados, sexo/idade que entraram na equação, resultado e proveniência (equação, coeficientes,
 * referência). Correção = nova linha (version + 1, supersedes_id) e a anterior vira 'superseded';
 * nada é sobrescrito. A trilha de auditoria fica em audit_logs (SKINFOLD_RECORDED / SKINFOLD_CORRECTED).
 */
class CreateSkinfoldMeasurements
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS skinfold_measurements (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    clinic_id INTEGER NOT NULL,
                    patient_id INTEGER NOT NULL,
                    professional_id INTEGER NOT NULL,
                    assessment_id INTEGER NULL,
                    record_group_id INTEGER NULL,
                    version INTEGER NOT NULL DEFAULT 1,
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    supersedes_id INTEGER NULL,
                    correction_reason TEXT NULL,
                    protocol_code VARCHAR(40) NOT NULL,
                    catalog_version VARCHAR(20) NOT NULL,
                    equation_sex VARCHAR(10) NOT NULL,
                    age_years INTEGER NOT NULL,
                    assessed_at DATETIME NOT NULL,
                    sites_json TEXT NOT NULL,
                    sum_mm DECIMAL(7,2) NOT NULL,
                    body_density DECIMAL(7,5) NOT NULL,
                    body_fat_pct DECIMAL(5,2) NOT NULL,
                    weight_kg DECIMAL(6,2) NULL,
                    fat_mass_kg DECIMAL(6,2) NULL,
                    lean_mass_kg DECIMAL(6,2) NULL,
                    warnings_json TEXT NULL,
                    provenance_json TEXT NOT NULL,
                    notes TEXT NULL,
                    created_by_user_id INTEGER NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
                    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
                    FOREIGN KEY (professional_id) REFERENCES professionals(id),
                    FOREIGN KEY (assessment_id) REFERENCES anthropometric_assessments(id) ON DELETE SET NULL
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_skinfold_patient ON skinfold_measurements (clinic_id, patient_id, status, assessed_at)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_skinfold_group ON skinfold_measurements (record_group_id, version)");
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS `skinfold_measurements` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `clinic_id` INT NOT NULL,
                `patient_id` INT NOT NULL,
                `professional_id` INT NOT NULL,
                `assessment_id` INT NULL,
                `record_group_id` INT NULL,
                `version` INT NOT NULL DEFAULT 1,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `supersedes_id` INT NULL,
                `correction_reason` TEXT NULL,
                `protocol_code` VARCHAR(40) NOT NULL,
                `catalog_version` VARCHAR(20) NOT NULL,
                `equation_sex` VARCHAR(10) NOT NULL,
                `age_years` INT NOT NULL,
                `assessed_at` DATETIME NOT NULL,
                `sites_json` TEXT NOT NULL,
                `sum_mm` DECIMAL(7,2) NOT NULL,
                `body_density` DECIMAL(7,5) NOT NULL,
                `body_fat_pct` DECIMAL(5,2) NOT NULL,
                `weight_kg` DECIMAL(6,2) NULL,
                `fat_mass_kg` DECIMAL(6,2) NULL,
                `lean_mass_kg` DECIMAL(6,2) NULL,
                `warnings_json` TEXT NULL,
                `provenance_json` TEXT NOT NULL,
                `notes` TEXT NULL,
                `created_by_user_id` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_skinfold_patient` (`clinic_id`, `patient_id`, `status`, `assessed_at`),
                INDEX `idx_skinfold_group` (`record_group_id`, `version`),
                CONSTRAINT `fk_skinfold_clinic` FOREIGN KEY (`clinic_id`) REFERENCES `clinics`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_skinfold_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_skinfold_professional` FOREIGN KEY (`professional_id`) REFERENCES `professionals`(`id`),
                CONSTRAINT `fk_skinfold_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `anthropometric_assessments`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
