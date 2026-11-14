-- ==============================================================================
-- ATUALIZAÇÃO v54.60 — NI2-820: TRANSCRIÇÃO EM TEMPO REAL E MINUTA NUTRICIONAL
-- ==============================================================================
-- Compatível com MySQL 5.7+ e MySQL 8.0+ (HostGator).
-- Executar no banco de produção/homologação.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS consultation_ai_consents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    user_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'granted',
    terms_version VARCHAR(50) NOT NULL DEFAULT 'v1.0',
    consented_at_utc DATETIME NOT NULL,
    revoked_at_utc DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_consent_clinic_appt (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_transcription_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_uuid VARCHAR(64) NOT NULL UNIQUE,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    professional_id INT NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'live',
    status VARCHAR(30) NOT NULL DEFAULT 'connected',
    started_at_utc DATETIME NOT NULL,
    paused_at_utc DATETIME NULL,
    resumed_at_utc DATETIME NULL,
    completed_at_utc DATETIME NULL,
    duration_seconds INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_transcription_sess_clinic (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_transcription_segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    clinic_id INT NOT NULL,
    sequence_number INT NOT NULL,
    speaker VARCHAR(50) NOT NULL DEFAULT 'speaker_uncertain',
    text TEXT NOT NULL,
    is_final TINYINT(1) NOT NULL DEFAULT 1,
    confidence DECIMAL(4,3) NULL,
    start_seconds DECIMAL(8,2) NULL,
    end_seconds DECIMAL(8,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transcription_seg_seq (session_id, sequence_number),
    KEY idx_transcription_seg_clinic (clinic_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_evolution_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    clinic_id INT NOT NULL,
    appointment_id INT NOT NULL,
    patient_id INT NOT NULL,
    professional_id INT NOT NULL,
    chief_complaint TEXT NULL,
    nutritional_goals TEXT NULL,
    dietary_routine TEXT NULL,
    reported_anthropometry TEXT NULL,
    clinical_symptoms TEXT NULL,
    adherence_notes TEXT NULL,
    agreed_conduct TEXT NULL,
    next_appointment_notes TEXT NULL,
    full_evolution_text MEDIUMTEXT NOT NULL,
    approval_status VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by INT NULL,
    approved_at_utc DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_evolution_drafts_clinic_appt (clinic_id, appointment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO migrations (migration, executed_at) VALUES ('2026_11_11_000092_create_realtime_transcription_tables', NOW());
