<?php

declare(strict_types=1);

class CreateFoodDiaryAndChatTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Diário Alimentar do Paciente (com foto, sintomas, saciedade e contexto)
        $db->exec("CREATE TABLE IF NOT EXISTS food_diary_entries (
            id {$pk},
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
        ){$engine};");

        // 2. Chat Seguro Profissional-Paciente
        $db->exec("CREATE TABLE IF NOT EXISTS chat_conversations (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, archived
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id)
        ){$engine};");

        $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id {$pk},
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
        ){$engine};");
    }
}
