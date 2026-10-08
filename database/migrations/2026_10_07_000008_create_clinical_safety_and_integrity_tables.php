<?php

declare(strict_types=1);

class CreateClinicalSafetyAndIntegrityTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Prontuário Append-Only e Registro Clínico com Encadeamento Criptográfico
        $db->exec("CREATE TABLE IF NOT EXISTS clinical_records (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            supersedes_id INTEGER NULL,
            revision_reason TEXT NULL,
            title VARCHAR(255) NOT NULL,
            content_json TEXT NOT NULL,
            record_hash VARCHAR(64) NOT NULL,
            previous_hash VARCHAR(64) NOT NULL,
            is_retracted INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id),
            FOREIGN KEY (supersedes_id) REFERENCES clinical_records(id),
            UNIQUE (clinic_id, patient_id, version)
        ){$engine};");

        // 2. Âncoras Periódicas de Integridade Clínica
        $db->exec("CREATE TABLE IF NOT EXISTS clinical_integrity_anchors (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            anchor_hash VARCHAR(64) NOT NULL,
            record_count INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ){$engine};");

        // 3. Versões Imutáveis Publicadas de Planos Alimentares
        $db->exec("CREATE TABLE IF NOT EXISTS meal_plan_versions (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            meal_plan_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            version_number INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            daily_energy_kcal DECIMAL(7,2) NOT NULL DEFAULT 0,
            payload_json TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'published', -- published, superseded
            published_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine};");

        // 4. Medidas Caseiras de Alimentos (Tabela de Conversão)
        $db->exec("CREATE TABLE IF NOT EXISTS food_household_measures (
            id {$pk},
            food_id INTEGER NOT NULL,
            measure_name VARCHAR(100) NOT NULL,
            grams_equivalent DECIMAL(7,2) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE,
            UNIQUE (food_id, measure_name)
        ){$engine};");

        // 5. Suporte a Alergênicos na Tabela de Alimentos
        // Para SQLite ou MySQL, garantir coluna allergens
        try {
            $db->exec("ALTER TABLE foods ADD COLUMN allergens TEXT NULL;");
        } catch (\Throwable $e) {
            // Coluna já existe
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS food_household_measures;");
        $db->exec("DROP TABLE IF EXISTS meal_plan_versions;");
        $db->exec("DROP TABLE IF EXISTS clinical_integrity_anchors;");
        $db->exec("DROP TABLE IF EXISTS clinical_records;");
    }
}
