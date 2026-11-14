<?php

declare(strict_types=1);

/**
 * Migration 93 — NI2-830 (SEC-001): consentimento verificável do paciente antes da transcrição.
 *
 * Cria consultation_ai_consent_requests: cada pedido de aceite é ligado a clínica + atendimento + paciente + versão
 * do texto, tem token de uso único guardado só como hash, validade curta, decisão do próprio paciente (link ou
 * portal), origem do evento, revogação e validade do aceite. A tabela consultation_ai_consents (migration 92) NÃO é
 * mais fonte de autorização: linhas antigas "granted" criadas só pelo profissional não valem como aceite.
 *
 * Idempotente (CREATE TABLE IF NOT EXISTS). MySQL 5.7+/8.0 e SQLite. Sem JSON, sem CTE, sem função de janela.
 * Índices: no MySQL nascem junto da tabela (KEY/UNIQUE KEY na própria CREATE TABLE) e, depois, a migration CONFERE
 * que existem; se faltar algum, falha com exceção (nunca segue em silêncio).
 */
class CreateTranscriptionConsentRequests
{
    private const INDEXES = [
        'uq_ai_consent_req_token',
        'uq_ai_consent_req_revoke_token',
        'idx_ai_consent_req_appt',
    ];

    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $mysql = $driver === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $keys = $mysql
            ? ",\n            UNIQUE KEY uq_ai_consent_req_token (token_hash),\n            UNIQUE KEY uq_ai_consent_req_revoke_token (revoke_token_hash),\n            KEY idx_ai_consent_req_appt (clinic_id, appointment_id, patient_id, status)"
            : '';

        $db->exec("CREATE TABLE IF NOT EXISTS consultation_ai_consent_requests (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            requested_by_user_id INTEGER NOT NULL,
            token_hash CHAR(64) NOT NULL,
            revoke_token_hash CHAR(64) NULL,
            terms_version VARCHAR(50) NOT NULL,
            terms_sha256 CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            request_origin VARCHAR(40) NOT NULL DEFAULT 'professional_session',
            expires_at_utc DATETIME NOT NULL,
            decided_at_utc DATETIME NULL,
            decision_mechanism VARCHAR(40) NULL,
            decision_ip_hash CHAR(64) NULL,
            decision_user_agent VARCHAR(120) NULL,
            valid_until_utc DATETIME NULL,
            revoked_at_utc DATETIME NULL,
            revoked_by VARCHAR(30) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP{$keys}
        ){$engine};");

        if (!$mysql) {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_ai_consent_req_token ON consultation_ai_consent_requests (token_hash)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_ai_consent_req_revoke_token ON consultation_ai_consent_requests (revoke_token_hash)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_consent_req_appt ON consultation_ai_consent_requests (clinic_id, appointment_id, patient_id, status)');
        }

        $missing = array_diff(self::INDEXES, $this->existingIndexes($db, $mysql));
        if ($missing !== []) {
            throw new RuntimeException('Migration 93: índice(s) ausente(s) em consultation_ai_consent_requests: ' . implode(', ', $missing)
                . '. Rode o SQL manual database/manual/atualizacao-v54-61-ni2-830-consentimento-verificavel.sql e confira os índices.');
        }
    }

    /** @return list<string> */
    private function existingIndexes(PDO $db, bool $mysql): array
    {
        if ($mysql) {
            $st = $db->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consultation_ai_consent_requests'");
            return $st ? array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)) : [];
        }
        $st = $db->query("PRAGMA index_list('consultation_ai_consent_requests')");
        $names = [];
        foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $names[] = (string)$row['name'];
        }
        return $names;
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS consultation_ai_consent_requests;');
    }
}
