<?php

declare(strict_types=1);

class CreateClinicalDomainTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // Pacientes (Multi-tenant via clinic_id)
        $db->exec("CREATE TABLE IF NOT EXISTS patients (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            uuid VARCHAR(36) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(30) NULL,
            birth_date DATE NULL,
            gender VARCHAR(20) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine};");

        // Consentimentos LGPD
        $db->exec("CREATE TABLE IF NOT EXISTS patient_consents (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            consent_type VARCHAR(100) NOT NULL,
            granted INTEGER NOT NULL DEFAULT 1,
            ip_address VARCHAR(45) NULL,
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ){$engine};");

        // Prontuário Nutricional Longitudinal
        $db->exec("CREATE TABLE IF NOT EXISTS nutrition_records (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            summary TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine};");

        // Anamnese Nutricional
        $db->exec("CREATE TABLE IF NOT EXISTS anamneses (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            habits TEXT NULL,
            allergies TEXT NULL,
            food_preferences TEXT NULL,
            clinical_history TEXT NULL,
            water_intake_ml INTEGER NULL,
            routine TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine};");

        // Avaliações Antropométricas
        $db->exec("CREATE TABLE IF NOT EXISTS anthropometric_assessments (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            weight_kg DECIMAL(5,2) NOT NULL,
            height_cm DECIMAL(5,2) NOT NULL,
            bmi DECIMAL(4,2) NOT NULL,
            waist_cm DECIMAL(5,2) NULL,
            hip_cm DECIMAL(5,2) NULL,
            whr DECIMAL(4,2) NULL,
            body_fat_pct DECIMAL(4,2) NULL,
            muscle_mass_pct DECIMAL(4,2) NULL,
            notes TEXT NULL,
            assessed_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine};");
    }
}
