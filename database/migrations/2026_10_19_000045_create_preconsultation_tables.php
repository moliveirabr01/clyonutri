<?php

declare(strict_types=1);

/**
 * Migration 45 (NI2-437): pré-consulta e anamnese adaptativa.
 *
 * - preconsultation_questionnaires: proposta revisada pelo nutricionista (cópia das perguntas que o paciente vê),
 *   link com token guardado só como hash SHA-256, expiração, bloqueio por tentativas, rascunho e envio.
 * - preconsultation_answer_snapshots: cada salvamento (rascunho ou envio) é gravado, append-only.
 * - consent_terms: termo de tratamento de dados de saúde da pré-consulta.
 */
class CreatePreconsultationTables
{
    public const TERM_VERSION = '2026.10-precons-r1';
    public const TERM_TEXT = 'Autorizo a clínica e o(a) nutricionista indicado(a) a tratar as informações de saúde e de hábitos que informei neste questionário, exclusivamente para preparar e realizar o meu atendimento nutricional. As respostas ficam no meu prontuário na clínica e não são compartilhadas com terceiros. Elas não substituem a consulta e não geram diagnóstico automático. Posso pedir acesso, correção ou exclusão dos meus dados à clínica, nos termos da LGPD.';

    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS preconsultation_questionnaires (
            id {$pk},
            clinic_id INT NOT NULL,
            patient_id INT NOT NULL,
            professional_id INT NOT NULL,
            appointment_id INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            objectives_json TEXT NOT NULL,
            suggestion_json TEXT NOT NULL,
            suggestion_ignored INT NOT NULL DEFAULT 0,
            questions_json TEXT NOT NULL,
            bank_version VARCHAR(20) NOT NULL,
            token_hash VARCHAR(64) NULL,
            token_generation INT NOT NULL DEFAULT 0,
            token_expires_at DATETIME NULL,
            sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            verified_at DATETIME NULL,
            failed_attempts INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            answers_json TEXT NULL,
            draft_saved_at DATETIME NULL,
            submitted_at DATETIME NULL,
            consent_id INT NULL,
            reviewed_at DATETIME NULL,
            reviewed_by_user_id INT NULL,
            review_notes TEXT NULL,
            imported_anamnesis_id INT NULL,
            imported_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
            FOREIGN KEY (professional_id) REFERENCES professionals(id),
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
        ){$engine}");

        $db->exec("CREATE TABLE IF NOT EXISTS preconsultation_answer_snapshots (
            id {$pk},
            clinic_id INT NOT NULL,
            questionnaire_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL,
            answers_json TEXT NOT NULL,
            answers_sha256 VARCHAR(64) NOT NULL,
            saved_at DATETIME NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (questionnaire_id) REFERENCES preconsultation_questionnaires(id) ON DELETE CASCADE
        ){$engine}");

        foreach ([
            ['preconsultation_questionnaires', 'uq_precons_token', 'token_hash', true],
            ['preconsultation_questionnaires', 'idx_precons_patient', 'clinic_id, patient_id, status', false],
            ['preconsultation_answer_snapshots', 'idx_precons_snap', 'questionnaire_id, saved_at', false],
        ] as [$table, $name, $cols, $unique]) {
            if (!$this->indexExists($db, $table, $name)) {
                $db->exec(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ') . "{$name} ON {$table} ({$cols})");
            }
        }

        $st = $db->prepare("SELECT COUNT(*) FROM consent_terms WHERE purpose_code = 'pre_consultation' AND version = :v");
        $st->execute(['v' => self::TERM_VERSION]);
        if ((int)$st->fetchColumn() === 0) {
            $db->prepare("INSERT INTO consent_terms (version, title, purpose_code, term_text, is_active) VALUES (:v, :t, 'pre_consultation', :x, 1)")
                ->execute(['v' => self::TERM_VERSION, 't' => 'Uso das respostas da pré-consulta', 'x' => self::TERM_TEXT]);
        }
    }

    private function indexExists(PDO $db, string $table, string $index): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :n");
            $st->execute(['n' => $index]);
            return (bool)$st->fetchColumn();
        }
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :n');
        $st->execute(['t' => $table, 'n' => $index]);
        return (int)$st->fetchColumn() > 0;
    }
}
