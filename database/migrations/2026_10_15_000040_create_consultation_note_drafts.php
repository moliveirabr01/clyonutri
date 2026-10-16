<?php

declare(strict_types=1);

/**
 * Migration 40 (v14, UX-03): rascunho das anotações da consulta presencial/agenda (/consulta/{id}).
 * Um rascunho por consulta e usuário, com versão para controle de conflito entre abas.
 * É apagado quando a consulta é concluída (a evolução vai para o prontuário).
 */
class CreateConsultationNoteDrafts
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $text = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';

        $db->exec("CREATE TABLE IF NOT EXISTS consultation_note_drafts (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            evolution_summary {$text} NULL,
            weight_kg VARCHAR(20) NULL,
            waist_cm VARCHAR(20) NULL,
            version INTEGER NOT NULL DEFAULT 1,
            updated_at_utc DATETIME NOT NULL,
            UNIQUE (appointment_id, user_id),
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        ){$engine};");
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS consultation_note_drafts');
    }
}
