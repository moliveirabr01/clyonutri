-- ============================================================================
-- ClyoNutri II — Esquema Completo DDL para MySQL 8.0 (HostGator / AlmaLinux)
-- Total de Migrações: 32 | Charset: utf8mb4 | Engine: InnoDB
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `migration` VARCHAR(255) NOT NULL UNIQUE,
  `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000001_create_tenants_and_users_table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clinics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(36) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            document VARCHAR(30) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(30) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(36) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'nutritionist',
            is_verified INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS clinic_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'nutritionist',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE (clinic_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS professionals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INTEGER NOT NULL UNIQUE,
            crn VARCHAR(20) NOT NULL,
            crn_region VARCHAR(10) NOT NULL,
            specialty VARCHAR(100) NULL,
            bio TEXT NULL,
            signature_path VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) PRIMARY KEY,
            user_id INTEGER NOT NULL,
            clinic_id INTEGER NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            payload TEXT NOT NULL,
            last_activity INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS password_resets (
            email VARCHAR(255) NOT NULL,
            token VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (email, token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NULL,
            user_id INTEGER NULL,
            action VARCHAR(100) NOT NULL,
            resource VARCHAR(100) NOT NULL,
            resource_id VARCHAR(50) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            details TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000001_create_tenants_and_users_table') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000002_create_clinical_domain_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS patients (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS patient_consents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            consent_type VARCHAR(100) NOT NULL,
            granted INTEGER NOT NULL DEFAULT 1,
            ip_address VARCHAR(45) NULL,
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS nutrition_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS anamneses (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS anthropometric_assessments (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000002_create_clinical_domain_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000003_create_food_and_meal_plan_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS foods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NULL,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(100) NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'TACO',
            energy_kcal DECIMAL(7,2) NOT NULL DEFAULT 0,
            protein_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            carbohydrate_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            lipid_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            fiber_g DECIMAL(7,2) NOT NULL DEFAULT 0,
            serving_size_g DECIMAL(7,2) NOT NULL DEFAULT 100,
            serving_unit VARCHAR(50) NOT NULL DEFAULT 'g',
            is_custom INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS meal_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft, published, archived
            daily_energy_kcal DECIMAL(7,2) NULL,
            notes TEXT NULL,
            published_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS meal_plan_meals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meal_plan_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            time_of_day VARCHAR(10) NULL,
            ordering INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS meal_plan_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meal_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            notes TEXT NULL,
            ordering INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (meal_id) REFERENCES meal_plan_meals(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS meal_plan_substitutions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES meal_plan_items(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000003_create_food_and_meal_plan_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000004_create_operations_and_finance_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            scheduled_at DATETIME NOT NULL,
            duration_minutes INTEGER NOT NULL DEFAULT 60,
            status VARCHAR(30) NOT NULL DEFAULT 'scheduled', -- scheduled, confirmed, in_progress, completed, cancelled
            type VARCHAR(50) NOT NULL DEFAULT 'first_visit', -- first_visit, follow_up, return
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hydration_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            amount_ml INTEGER NOT NULL,
            logged_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS checkins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            energy_level INTEGER NULL,
            hunger_level INTEGER NULL,
            meal_adherence_pct INTEGER NULL,
            notes TEXT NULL,
            checkin_date DATE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS receivables (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            appointment_id INTEGER NULL,
            description VARCHAR(255) NOT NULL,
            amount_cents INTEGER NOT NULL,
            due_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending, partially_paid, paid, cancelled
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            receivable_id INTEGER NOT NULL,
            amount_cents INTEGER NOT NULL,
            payment_method VARCHAR(50) NOT NULL, -- pix, credit_card, debit_card, cash, transfer
            paid_at DATETIME NOT NULL,
            receipt_code VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (receivable_id) REFERENCES receivables(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            read_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000004_create_operations_and_finance_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000005_create_advanced_prescription_and_recipes_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meal_plan_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NULL, -- null = template global do sistema
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL, -- Low Carb, Hipertrofia, Vegetariano, Equilíbrio
            description TEXT NULL,
            target_energy_kcal DECIMAL(7,2) NULL,
            payload_json TEXT NOT NULL, -- Estrutura de refeições, porções e horários
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recipes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NULL, -- null = receita padrão da biblioteca
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) NULL,
            prep_time_minutes INTEGER NOT NULL DEFAULT 15,
            servings INTEGER NOT NULL DEFAULT 1,
            instructions TEXT NOT NULL,
            energy_kcal_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            protein_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            carbs_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            fat_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            is_custom INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recipe_ingredients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            notes VARCHAR(255) NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS food_equivalence_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NULL,
            group_name VARCHAR(100) NOT NULL, -- Carboidratos Complexos, Proteínas Magras, Frutas
            reference_description VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS food_equivalence_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INTEGER NOT NULL,
            food_id INTEGER NOT NULL,
            equivalent_amount DECIMAL(7,2) NOT NULL,
            measure_unit VARCHAR(50) NOT NULL,
            FOREIGN KEY (group_id) REFERENCES food_equivalence_groups(id) ON DELETE CASCADE,
            FOREIGN KEY (food_id) REFERENCES foods(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000005_create_advanced_prescription_and_recipes_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000006_create_food_diary_and_chat_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS food_diary_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            meal_name VARCHAR(100) NOT NULL, -- Café da Manhã, Almoço, Lanche, Jantar
            consumed_at DATETIME NOT NULL,
            photo_path VARCHAR(255) NULL,
            description TEXT NULL,
            hunger_level INTEGER NULL, -- Escala 1 a 5
            satiety_level INTEGER NULL, -- Escala 1 a 5
            symptoms TEXT NULL, -- Gases, refluxo, plenitude gástrica, disposição
            feedback_from_professional TEXT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, archived
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INTEGER NOT NULL,
            clinic_id INTEGER NOT NULL,
            sender_type VARCHAR(20) NOT NULL, -- professional, patient
            sender_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            attachment_path VARCHAR(255) NULL,
            read_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000006_create_food_diary_and_chat_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000007_create_scale_and_integration_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS telehealth_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL UNIQUE,
            meeting_url VARCHAR(255) NOT NULL,
            external_calendar_event_id VARCHAR(100) NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'clyo_meet', -- clyo_meet, google_meet, zoom
            status VARCHAR(20) NOT NULL DEFAULT 'ready',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS public_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL UNIQUE,
            slug VARCHAR(100) NOT NULL UNIQUE,
            headline VARCHAR(255) NULL,
            bio TEXT NULL,
            service_types TEXT NULL, -- JSON com tipos e preços de consulta
            is_published INTEGER NOT NULL DEFAULT 1,
            is_verified INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS consultation_packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            total_sessions INTEGER NOT NULL DEFAULT 3,
            price_cents INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS patient_packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            package_id INTEGER NOT NULL,
            sessions_used INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, completed, cancelled
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (package_id) REFERENCES consultation_packages(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS health_sync_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            source VARCHAR(50) NOT NULL, -- apple_health, google_health_connect
            metric_type VARCHAR(50) NOT NULL, -- step_count, active_calories, sleep_hours, resting_heart_rate
            value DECIMAL(8,2) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            recorded_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS referral_programs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            professional_id INTEGER NOT NULL,
            referral_code VARCHAR(30) NOT NULL UNIQUE,
            referred_count INTEGER NOT NULL DEFAULT 0,
            reward_credits_cents INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000007_create_scale_and_integration_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000008_create_clinical_safety_and_integrity_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clinical_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS clinical_integrity_anchors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            anchor_hash VARCHAR(64) NOT NULL,
            record_count INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS meal_plan_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS food_household_measures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            food_id INTEGER NOT NULL,
            measure_name VARCHAR(100) NOT NULL,
            grams_equivalent DECIMAL(7,2) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (food_id) REFERENCES foods(id) ON DELETE CASCADE,
            UNIQUE (food_id, measure_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE foods ADD COLUMN allergens TEXT NULL;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000008_create_clinical_safety_and_integrity_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_07_000009_create_privacy_and_lgpd_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consent_terms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(20) NOT NULL,
            title VARCHAR(255) NOT NULL,
            purpose_code VARCHAR(100) NOT NULL, -- clinical_care, telehealth, communications, data_processing
            term_text TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (purpose_code, version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE patient_consents ADD COLUMN term_id INTEGER NULL;
ALTER TABLE patient_consents ADD COLUMN revoked_at DATETIME NULL;
ALTER TABLE patient_consents ADD COLUMN revocation_reason TEXT NULL;
ALTER TABLE patient_consents ADD COLUMN source_channel VARCHAR(50) DEFAULT "web";
ALTER TABLE patient_consents ADD COLUMN user_agent TEXT NULL;
CREATE TABLE IF NOT EXISTS data_subject_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS clinical_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_07_000009_create_privacy_and_lgpd_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000010_create_personal_access_tokens_table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personal_access_tokens (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tokenable_type VARCHAR(50) NOT NULL,
            tokenable_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            abilities TEXT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000010_create_personal_access_tokens_table') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000011_create_appointment_lifecycle_and_history_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointment_status_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            from_status VARCHAR(30) NULL,
            to_status VARCHAR(30) NOT NULL,
            changed_by_user_id INTEGER NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX IF NOT EXISTS idx_app_status_hist_app ON appointment_status_history (appointment_id);
CREATE INDEX IF NOT EXISTS idx_appointments_clinic_date ON appointments (clinic_id, scheduled_at);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000011_create_appointment_lifecycle_and_history_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000012_enhance_anthropometry_and_sharing_tables
-- ----------------------------------------------------------------------------
ALTER TABLE anthropometric_assessments ADD COLUMN neck_cm DECIMAL(5,2) NULL;
ALTER TABLE anthropometric_assessments ADD COLUMN chest_cm DECIMAL(5,2) NULL;
ALTER TABLE anthropometric_assessments ADD COLUMN arm_cm DECIMAL(5,2) NULL;
ALTER TABLE anthropometric_assessments ADD COLUMN thigh_cm DECIMAL(5,2) NULL;
ALTER TABLE anthropometric_assessments ADD COLUMN calf_cm DECIMAL(5,2) NULL;
ALTER TABLE anthropometric_assessments ADD COLUMN is_shared BOOLEAN NOT NULL DEFAULT 0;
ALTER TABLE anthropometric_assessments ADD COLUMN shared_at DATETIME NULL;
ALTER TABLE anthropometric_assessments ADD COLUMN shared_by_user_id INTEGER NULL;
CREATE TABLE IF NOT EXISTS anthropometry_shares_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            assessment_id INTEGER NOT NULL,
            action VARCHAR(20) NOT NULL,
            changed_by_user_id INTEGER NULL,
            reason TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (assessment_id) REFERENCES anthropometric_assessments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX IF NOT EXISTS idx_anthro_patient_date ON anthropometric_assessments (clinic_id, patient_id, assessed_at);
CREATE INDEX IF NOT EXISTS idx_anthro_shares_audit ON anthropometry_shares_audit (assessment_id, clinic_id);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000012_enhance_anthropometry_and_sharing_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000013_enhance_meal_plans_and_prescriptions_tables
-- ----------------------------------------------------------------------------
ALTER TABLE meal_plans ADD COLUMN parent_plan_id INTEGER NULL;
ALTER TABLE meal_plans ADD COLUMN protein_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plans ADD COLUMN carbohydrate_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plans ADD COLUMN lipid_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plans ADD COLUMN fiber_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_meals ADD COLUMN notes TEXT NULL;
ALTER TABLE meal_plan_items ADD COLUMN energy_kcal DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_items ADD COLUMN protein_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_items ADD COLUMN carbohydrate_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_items ADD COLUMN lipid_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_items ADD COLUMN fiber_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_substitutions ADD COLUMN energy_kcal DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_substitutions ADD COLUMN protein_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_substitutions ADD COLUMN carbohydrate_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_substitutions ADD COLUMN lipid_g DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE meal_plan_substitutions ADD COLUMN fiber_g DECIMAL(7,2) NOT NULL DEFAULT 0;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000013_enhance_meal_plans_and_prescriptions_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000014_create_patient_documents_and_files_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS patient_documents (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX IF NOT EXISTS idx_patient_documents_tenant ON patient_documents (clinic_id, patient_id);
CREATE INDEX IF NOT EXISTS idx_patient_documents_token ON patient_documents (access_token);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000014_create_patient_documents_and_files_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000015_add_authentication_and_credentials_to_patients_table
-- ----------------------------------------------------------------------------
ALTER TABLE patients ADD COLUMN password_hash VARCHAR(255) NULL;
ALTER TABLE patients ADD COLUMN access_pin VARCHAR(255) NULL;
ALTER TABLE patients ADD COLUMN otp_code VARCHAR(255) NULL;
ALTER TABLE patients ADD COLUMN otp_expires_at DATETIME NULL;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000015_add_authentication_and_credentials_to_patients_table') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000016_enhance_anamnesis_structured_fields
-- ----------------------------------------------------------------------------
ALTER TABLE anamneses ADD COLUMN objectives TEXT NULL;
ALTER TABLE anamneses ADD COLUMN restrictions TEXT NULL;
ALTER TABLE anamneses ADD COLUMN symptoms TEXT NULL;
ALTER TABLE anamneses ADD COLUMN is_draft INTEGER NOT NULL DEFAULT 0;
ALTER TABLE anamneses ADD COLUMN notes TEXT NULL;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000016_enhance_anamnesis_structured_fields') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000017_create_teleconsultation_sessions_table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teleconsultation_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL UNIQUE,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'daily_co',
            external_room_id VARCHAR(150) NULL,
            external_room_url VARCHAR(255) NULL,
            patient_token_hash VARCHAR(64) NOT NULL UNIQUE,
            professional_token_hash VARCHAR(64) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'scheduled', 
            -- scheduled, device_checked, waiting_room, admitted, in_progress, completed, cancelled, rejected
            device_check_passed INTEGER NOT NULL DEFAULT 0,
            consent_term_version VARCHAR(50) NULL,
            consent_accepted_at DATETIME NULL,
            waiting_room_entered_at DATETIME NULL,
            admitted_at DATETIME NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            clinical_record_id INTEGER NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS teleconsultation_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            -- session_created, token_issued, device_checked, consent_signed, waiting_room_entered, 
            -- patient_admitted, patient_rejected, call_started, clinical_note_appended, call_ended, session_cancelled
            actor_type VARCHAR(30) NOT NULL, -- professional, patient, system
            actor_id INTEGER NULL,
            details_json TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES teleconsultation_sessions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000017_create_teleconsultation_sessions_table') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000018_create_helpdesk_and_service_desk_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS helpdesk_articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(60) NOT NULL,
            slug VARCHAR(150) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            summary TEXT NOT NULL,
            content_markdown TEXT NOT NULL,
            is_published INTEGER NOT NULL DEFAULT 1,
            view_count INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS helpdesk_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS helpdesk_ticket_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            is_internal_note INTEGER NOT NULL DEFAULT 0,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS helpdesk_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS helpdesk_ticket_feedbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INTEGER NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            rating INTEGER NOT NULL,
            comment TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS helpdesk_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS helpdesk_audit_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            payload_json TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES helpdesk_tickets(id) ON DELETE CASCADE,
            FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX IF NOT EXISTS idx_hd_tickets_status ON helpdesk_tickets(status, created_at);
CREATE INDEX IF NOT EXISTS idx_hd_tickets_priority ON helpdesk_tickets(priority_operational, status);
CREATE INDEX IF NOT EXISTS idx_hd_tickets_clinic ON helpdesk_tickets(clinic_id, status);
CREATE INDEX IF NOT EXISTS idx_hd_tickets_first_resp ON helpdesk_tickets(first_response_at);
CREATE INDEX IF NOT EXISTS idx_hd_tickets_resolved ON helpdesk_tickets(resolved_at);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000018_create_helpdesk_and_service_desk_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000019_enhance_chat_secure_messaging_tables
-- ----------------------------------------------------------------------------
ALTER TABLE chat_messages ADD COLUMN idempotency_key VARCHAR(64) NULL;
ALTER TABLE chat_messages ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sent';
ALTER TABLE chat_messages ADD COLUMN delivered_at DATETIME NULL;
ALTER TABLE chat_messages ADD COLUMN attachment_name VARCHAR(255) NULL;
ALTER TABLE chat_messages ADD COLUMN attachment_mime VARCHAR(100) NULL;
ALTER TABLE chat_messages ADD COLUMN attachment_size INTEGER NULL;
CREATE INDEX IF NOT EXISTS idx_chat_messages_idemp ON chat_messages(conversation_id, idempotency_key);
CREATE INDEX IF NOT EXISTS idx_chat_messages_created ON chat_messages(conversation_id, id);
CREATE TABLE IF NOT EXISTS chat_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_notifications_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000019_enhance_chat_secure_messaging_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000020_enhance_culinary_recipes_and_prescriptions_tables
-- ----------------------------------------------------------------------------
ALTER TABLE recipes ADD COLUMN summary TEXT NULL;
ALTER TABLE recipes ADD COLUMN meal_type VARCHAR(60) NULL;
ALTER TABLE recipes ADD COLUMN difficulty VARCHAR(20) NOT NULL DEFAULT 'fácil';
ALTER TABLE recipes ADD COLUMN cook_time_minutes INTEGER NOT NULL DEFAULT 0;
ALTER TABLE recipes ADD COLUMN serving_size_name VARCHAR(100) NULL;
ALTER TABLE recipes ADD COLUMN serving_size_g DECIMAL(7,2) NULL;
ALTER TABLE recipes ADD COLUMN fiber_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE recipes ADD COLUMN sodium_mg_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0;
ALTER TABLE recipes ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'published';
ALTER TABLE recipes ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'clinic';
ALTER TABLE recipes ADD COLUMN parent_recipe_id INTEGER NULL;
ALTER TABLE recipes ADD COLUMN author_user_id INTEGER NULL;
ALTER TABLE recipes ADD COLUMN photo_path VARCHAR(255) NULL;
ALTER TABLE recipes ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE recipe_ingredients ADD COLUMN gross_weight_g DECIMAL(7,2) NULL;
ALTER TABLE recipe_ingredients ADD COLUMN net_weight_g DECIMAL(7,2) NULL;
ALTER TABLE recipe_ingredients ADD COLUMN correction_factor DECIMAL(5,2) NOT NULL DEFAULT 1.0;
ALTER TABLE recipe_ingredients ADD COLUMN cooking_factor DECIMAL(5,2) NOT NULL DEFAULT 1.0;
ALTER TABLE recipe_ingredients ADD COLUMN ordering INTEGER NOT NULL DEFAULT 1;
CREATE TABLE IF NOT EXISTS recipe_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INTEGER NOT NULL,
            version_number INTEGER NOT NULL DEFAULT 1,
            title VARCHAR(255) NOT NULL,
            servings INTEGER NOT NULL DEFAULT 1,
            serving_size_g DECIMAL(7,2) NULL,
            energy_kcal_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            protein_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            carbs_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            fat_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            fiber_g_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            sodium_mg_per_serving DECIMAL(7,2) NOT NULL DEFAULT 0,
            payload_json TEXT NOT NULL, -- Snapshot imutável de ingredientes e passos
            status VARCHAR(20) NOT NULL DEFAULT 'published', -- draft, published, superseded
            published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            published_by_user_id INTEGER NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recipe_steps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INTEGER NOT NULL,
            step_number INTEGER NOT NULL,
            instruction TEXT NOT NULL,
            tip TEXT NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recipe_tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INTEGER NOT NULL,
            tag_name VARCHAR(50) NOT NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recipe_allergens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INTEGER NOT NULL,
            allergen_group VARCHAR(60) NOT NULL,
            risk_level VARCHAR(20) NOT NULL DEFAULT 'contains', -- contains, may_contain_traces, free_from
            verified_by_professional INTEGER NOT NULL DEFAULT 1,
            notes VARCHAR(255) NULL,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recipe_prescriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            meal_plan_id INTEGER NOT NULL,
            meal_id INTEGER NULL,
            patient_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            recipe_version_id INTEGER NULL,
            prescribed_servings DECIMAL(4,2) NOT NULL DEFAULT 1.0,
            meal_name VARCHAR(100) NULL,
            instructions_override TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recipe_patient_favorites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX IF NOT EXISTS idx_recipes_clinic ON recipes(clinic_id, status);
CREATE INDEX IF NOT EXISTS idx_recipe_prescriptions_patient ON recipe_prescriptions(patient_id, meal_plan_id);
CREATE INDEX IF NOT EXISTS idx_recipe_versions_recipe ON recipe_versions(recipe_id, version_number);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000020_enhance_culinary_recipes_and_prescriptions_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000021_create_onboarding_and_activation_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS onboarding_progress (
                id INT AUTO_INCREMENT PRIMARY KEY,
                clinic_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                current_step INTEGER NOT NULL DEFAULT 1,
                completed_steps TEXT NULL,
                is_completed INTEGER NOT NULL DEFAULT 0,
                completed_at DATETIME NULL,
                focus_objectives TEXT NULL,
                professional_data TEXT NULL,
                practice_preferences TEXT NULL,
                first_patient_id INTEGER NULL,
                contextual_notes TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE (clinic_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_onboarding_clinic_user ON onboarding_progress (clinic_id, user_id);
CREATE INDEX idx_onboarding_is_completed ON onboarding_progress (is_completed);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000021_create_onboarding_and_activation_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_08_000022_create_patient_self_metrics_table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS patient_self_metrics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                clinic_id INTEGER NOT NULL,
                patient_id INTEGER NOT NULL,
                metric_type VARCHAR(50) NOT NULL,
                value REAL NOT NULL,
                unit VARCHAR(30) NOT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'patient_self_report',
                notes TEXT NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
                FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_patient_self_metrics_tenant_patient ON patient_self_metrics (clinic_id, patient_id, recorded_at);
CREATE INDEX idx_patient_self_metrics_type ON patient_self_metrics (metric_type);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_08_000022_create_patient_self_metrics_table') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_09_000023_add_allergy_assessment_to_anamneses
-- ----------------------------------------------------------------------------
ALTER TABLE anamneses ADD COLUMN allergy_assessment VARCHAR(20) NOT NULL DEFAULT 'not_assessed';
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_09_000023_add_allergy_assessment_to_anamneses') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_09_000024_add_professional_account_settings
-- ----------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL;
ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 0;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_09_000024_add_professional_account_settings') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_09_000025_create_patient_app_invitations
-- ----------------------------------------------------------------------------
CREATE TABLE patient_app_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY, clinic_id INTEGER NOT NULL, patient_id INTEGER NOT NULL,
            created_by INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL, accepted_at DATETIME NULL, revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id),
            FOREIGN KEY (patient_id) REFERENCES patients(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_09_000025_create_patient_app_invitations') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_09_000026_bind_access_tokens_to_clinic
-- ----------------------------------------------------------------------------
ALTER TABLE personal_access_tokens ADD COLUMN clinic_id INTEGER NULL;
ALTER TABLE personal_access_tokens ADD COLUMN auth_version INTEGER NULL;
CREATE INDEX access_tokens_clinic ON personal_access_tokens(clinic_id,tokenable_type,tokenable_id);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_09_000026_bind_access_tokens_to_clinic') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_09_000027_version_clinical_record_integrity
-- ----------------------------------------------------------------------------
ALTER TABLE clinical_records ADD COLUMN hash_version INTEGER NOT NULL DEFAULT 1;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_09_000027_version_clinical_record_integrity') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_09_000028_add_idempotency_to_food_diary
-- ----------------------------------------------------------------------------
ALTER TABLE food_diary_entries ADD COLUMN idempotency_key VARCHAR(64) NULL;
CREATE INDEX IF NOT EXISTS idx_food_diary_idempotency ON food_diary_entries(patient_id, clinic_id, idempotency_key);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_09_000028_add_idempotency_to_food_diary') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_10_000029_enforce_food_diary_idempotency
-- ----------------------------------------------------------------------------
UPDATE food_diary_entries SET idempotency_key = NULL WHERE patient_id = ? AND clinic_id = ? AND idempotency_key = ? AND id <> ?;
CREATE UNIQUE INDEX uq_food_diary_operation ON food_diary_entries(patient_id, clinic_id, idempotency_key);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_10_000029_enforce_food_diary_idempotency') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_10_000030_create_saas_subscriptions_and_cancellation_tables
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS saas_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            price_cents INTEGER NOT NULL,
            billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
            max_professionals INTEGER NOT NULL DEFAULT 1,
            max_patients INTEGER NOT NULL DEFAULT 50,
            storage_mb INTEGER NOT NULL DEFAULT 2048,
            features_json TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO saas_plans (code, name, price_cents, billing_cycle, max_professionals, max_patients, storage_mb, features_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?);
CREATE TABLE IF NOT EXISTS clinic_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL UNIQUE,
            plan_id INTEGER NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
            price_cents INTEGER NOT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            current_period_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            current_period_end DATETIME NOT NULL,
            canceled_at DATETIME NULL,
            cancellation_protocol VARCHAR(50) NULL,
            cancellation_reason_category VARCHAR(60) NULL,
            cancellation_feedback TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (plan_id) REFERENCES saas_plans(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS subscription_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            amount_cents INTEGER NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'paid',
            due_date DATE NOT NULL,
            paid_at DATETIME NULL,
            payment_method VARCHAR(50) NOT NULL DEFAULT 'credit_card',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (subscription_id) REFERENCES clinic_subscriptions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cancellation_surveys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            clinic_id INTEGER NOT NULL,
            subscription_id INTEGER NOT NULL,
            protocol VARCHAR(50) NOT NULL UNIQUE,
            reason_category VARCHAR(60) NULL,
            feedback_text TEXT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (subscription_id) REFERENCES clinic_subscriptions(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_10_000030_create_saas_subscriptions_and_cancellation_tables') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_10_000031_create_platform_settings_table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS platform_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            description VARCHAR(255) NULL,
            updated_by_user_id INTEGER NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO platform_settings (setting_key, setting_value, description) VALUES (?, ?, ?);
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_10_000031_create_platform_settings_table') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_14_000032_create_login_attempts_table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `bucket_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `failures` int NOT NULL DEFAULT '0',
  `window_started_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `last_attempt_at` datetime NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_login_attempts_last` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_14_000032_create_login_attempts_table') ON DUPLICATE KEY UPDATE `migration` = `migration`;

-- ----------------------------------------------------------------------------
-- Migration: 2026_10_15_000033_convert_login_attempts_to_bucket_key_primary
-- (instalação nova já nasce no modelo final; só registra)
-- ----------------------------------------------------------------------------
INSERT INTO `migrations` (`migration`) VALUES ('2026_10_15_000033_convert_login_attempts_to_bucket_key_primary') ON DUPLICATE KEY UPDATE `migration` = `migration`;

SET FOREIGN_KEY_CHECKS = 1;
