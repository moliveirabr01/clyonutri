<?php

declare(strict_types=1);

/**
 * Migration 21: Criação da Tabela de Progresso de Onboarding e Ativação do Nutricionista (NI2-282)
 */
class CreateOnboardingAndActivationTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("
            CREATE TABLE IF NOT EXISTS onboarding_progress (
                id {$pk},
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
            ){$engine}
        ");

        if ($driver === 'sqlite') {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_onboarding_clinic_user ON onboarding_progress (clinic_id, user_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_onboarding_is_completed ON onboarding_progress (is_completed)");
        } else {
            $db->exec("CREATE INDEX idx_onboarding_clinic_user ON onboarding_progress (clinic_id, user_id)");
            $db->exec("CREATE INDEX idx_onboarding_is_completed ON onboarding_progress (is_completed)");
        }
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS onboarding_progress");
    }
}
