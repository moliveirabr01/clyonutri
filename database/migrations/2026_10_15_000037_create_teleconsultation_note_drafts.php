<?php

declare(strict_types=1);

/**
 * Migration 37 (era a 33 do WIP do Maicon): Rascunho da evolução durante a teleconsulta (NI2-249).
 * Um rascunho por sessão e profissional, com versão para controle de conflito.
 * O rascunho é apagado quando a evolução é finalizada no prontuário.
 */
class CreateTeleconsultationNoteDrafts
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $text = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';

        $db->exec("CREATE TABLE IF NOT EXISTS teleconsultation_note_drafts (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            title VARCHAR(255) NULL,
            notes {$text} NULL,
            goals VARCHAR(1000) NULL,
            version INTEGER NOT NULL DEFAULT 1,
            updated_at_utc DATETIME NOT NULL,
            UNIQUE (session_id, professional_id),
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES teleconsultation_sessions(id) ON DELETE CASCADE
        ){$engine};");
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS teleconsultation_note_drafts');
    }
}
