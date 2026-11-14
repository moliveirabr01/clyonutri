-- ==============================================================================
-- ATUALIZAÇÃO v54.61 — NI2-830 (SEC-001): CONSENTIMENTO VERIFICÁVEL DO PACIENTE
-- ==============================================================================
-- Migration 93 (2026_11_13_000093_create_transcription_consent_requests).
-- Compatível com MySQL 5.7+ e 8.0 (HostGator). Idempotente: pode rodar mais de uma vez.
-- Sem JSON, sem CTE, sem função de janela. Índices criados junto da tabela (inline).
--
-- PRÉ-REQUISITO: as tabelas da migration 92 (v54.60 / NI2-820) já devem existir:
--   consultation_ai_consents, consultation_transcription_sessions,
--   consultation_transcription_segments, consultation_evolution_drafts.
-- Se faltarem, rode antes database/manual/atualizacao-v54-60-ni2-820-realtime-transcription.sql.
-- (Atenção: a migration 091 — production_events/production_alerts — NÃO está no banco de produção;
--  ela não é exigida por esta atualização, mas segue pendente: database/manual/atualizacao-v54-52-ni2-767-*.sql.)
--
-- A tabela consultation_ai_consents (migration 92) deixa de ser fonte de autorização.
-- Linhas antigas "granted" criadas só pelo profissional NÃO valem como aceite do paciente.
-- Nenhuma linha antiga é alterada ou apagada.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS consultation_ai_consent_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    requested_by_user_id INT NOT NULL,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ai_consent_req_token (token_hash),
    UNIQUE KEY uq_ai_consent_req_revoke_token (revoke_token_hash),
    KEY idx_ai_consent_req_appt (clinic_id, appointment_id, patient_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificação (precisa devolver 3 linhas: os 3 índices). Se devolver menos, a tabela
-- já existia sem os índices: NÃO ignore; crie o que faltar com ALTER TABLE ... ADD ... e confira de novo.
SELECT DISTINCT INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'consultation_ai_consent_requests'
  AND INDEX_NAME IN ('uq_ai_consent_req_token', 'uq_ai_consent_req_revoke_token', 'idx_ai_consent_req_appt');

-- Registro da migration (para o Migrator não tentar rodar de novo; mesmo padrão do SQL manual da v54.60).
INSERT IGNORE INTO migrations (migration, executed_at) VALUES ('2026_11_13_000093_create_transcription_consent_requests', NOW());
