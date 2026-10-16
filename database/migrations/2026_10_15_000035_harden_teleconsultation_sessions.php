<?php

declare(strict_types=1);

/**
 * Migration 35 (NI2-221 v11): endurece teleconsultation_sessions sem apagar nada.
 *
 * - token_generation / token_issued_at / token_expires_at / token_revoked_at: convite do paciente com
 *   validade, janela e revogação por geração (o token bruto nunca é gravado; só o SHA-256).
 * - scheduled_at_snapshot: horário do agendamento quando o convite foi emitido; reagendar invalida.
 * - external_room_name: nome da sala no provedor (deleteRoom usa o nome, não o id) — F11.
 * - consent_term_id: termo (consent_terms) aceito/recusado na sessão — F18.
 * - Termo de consentimento de teleconsulta (purpose_code='telehealth', versão 2026.10-r1) inserido se faltar.
 * Idempotente: só cria o que faltar. Nenhum DROP, nenhum UPDATE de dados existentes além do termo novo.
 */
class HardenTeleconsultationSessions
{
    public const TERM_VERSION = '2026.10-r1';
    public const TERM_TITLE = 'Consentimento para teleconsulta nutricional';
    public const TERM_TEXT = 'Concordo em realizar esta consulta de nutrição a distância, por vídeo, com o(a) profissional indicado(a) nesta página. '
        . 'Entendo que o atendimento remoto tem limitações em relação ao presencial (por exemplo, não há exame físico) e que o(a) profissional pode '
        . 'recomendar uma consulta presencial. A sessão não é gravada. As anotações clínicas ficam no meu prontuário na clínica. '
        . 'Posso recusar este termo; nesse caso a teleconsulta não acontece e posso pedir à clínica um atendimento presencial.';

    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $cols = [];
        if ($mysql) {
            foreach ($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'teleconsultation_sessions'") as $r) {
                $cols[] = $r['COLUMN_NAME'];
            }
        } else {
            foreach ($db->query('PRAGMA table_info(teleconsultation_sessions)') as $r) {
                $cols[] = $r['name'];
            }
        }
        $add = [
            'token_generation' => 'INTEGER NOT NULL DEFAULT 1',
            'token_issued_at' => 'DATETIME NULL',
            'token_expires_at' => 'DATETIME NULL',
            'token_revoked_at' => 'DATETIME NULL',
            'scheduled_at_snapshot' => 'DATETIME NULL',
            'external_room_name' => 'VARCHAR(150) NULL',
            'consent_term_id' => 'INTEGER NULL',
        ];
        foreach ($add as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $db->exec("ALTER TABLE teleconsultation_sessions ADD COLUMN {$col} {$def}");
            }
        }

        $st = $db->prepare("SELECT COUNT(*) FROM consent_terms WHERE purpose_code = 'telehealth' AND version = :v");
        $st->execute(['v' => self::TERM_VERSION]);
        if ((int)$st->fetchColumn() === 0) {
            $db->prepare("INSERT INTO consent_terms (version, title, purpose_code, term_text, is_active) VALUES (:v, :t, 'telehealth', :x, 1)")
                ->execute(['v' => self::TERM_VERSION, 't' => self::TERM_TITLE, 'x' => self::TERM_TEXT]);
        }
    }
}
