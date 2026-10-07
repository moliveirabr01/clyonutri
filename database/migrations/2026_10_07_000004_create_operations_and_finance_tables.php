<?php

declare(strict_types=1);

class CreateOperationsAndFinanceTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // Agenda / Consultas
        $db->exec("CREATE TABLE IF NOT EXISTS appointments (
            id {$pk},
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
        ){$engine};");

        // Engajamento: Hidratação do Paciente
        $db->exec("CREATE TABLE IF NOT EXISTS hydration_logs (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            amount_ml INTEGER NOT NULL,
            logged_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
        ){$engine};");

        // Engajamento: Check-ins
        $db->exec("CREATE TABLE IF NOT EXISTS checkins (
            id {$pk},
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
        ){$engine};");

        // Financeiro Essencial: Recebíveis
        $db->exec("CREATE TABLE IF NOT EXISTS receivables (
            id {$pk},
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
        ){$engine};");

        // Financeiro Essencial: Pagamentos
        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            receivable_id INTEGER NOT NULL,
            amount_cents INTEGER NOT NULL,
            payment_method VARCHAR(50) NOT NULL, -- pix, credit_card, debit_card, cash, transfer
            paid_at DATETIME NOT NULL,
            receipt_code VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (receivable_id) REFERENCES receivables(id) ON DELETE CASCADE
        ){$engine};");

        // Notificações do Sistema
        $db->exec("CREATE TABLE IF NOT EXISTS notifications (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            read_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine};");
    }
}
