<?php

declare(strict_types=1);

class CreateScaleAndIntegrationTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Teleconsulta e Integração com Calendários
        $db->exec("CREATE TABLE IF NOT EXISTS telehealth_sessions (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL UNIQUE,
            meeting_url VARCHAR(255) NOT NULL,
            external_calendar_event_id VARCHAR(100) NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'clyo_meet', -- clyo_meet, google_meet, zoom
            status VARCHAR(20) NOT NULL DEFAULT 'ready',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        ){$engine};");

        // 2. Perfis Públicos e Agendamento Online
        $db->exec("CREATE TABLE IF NOT EXISTS public_profiles (
            id {$pk},
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
        ){$engine};");

        // 3. Pacotes de Consulta e Planos de Recorrência
        $db->exec("CREATE TABLE IF NOT EXISTS consultation_packages (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            total_sessions INTEGER NOT NULL DEFAULT 3,
            price_cents INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        ){$engine};");

        $db->exec("CREATE TABLE IF NOT EXISTS patient_packages (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            package_id INTEGER NOT NULL,
            sessions_used INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, completed, cancelled
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (package_id) REFERENCES consultation_packages(id)
        ){$engine};");

        // 4. Integrações de Saúde (Apple Health & Health Connect)
        $db->exec("CREATE TABLE IF NOT EXISTS health_sync_data (
            id {$pk},
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
        ){$engine};");

        // 5. Academy e Programa de Indicações
        $db->exec("CREATE TABLE IF NOT EXISTS referral_programs (
            id {$pk},
            professional_id INTEGER NOT NULL,
            referral_code VARCHAR(30) NOT NULL UNIQUE,
            referred_count INTEGER NOT NULL DEFAULT 0,
            reward_credits_cents INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE
        ){$engine};");
    }
}
