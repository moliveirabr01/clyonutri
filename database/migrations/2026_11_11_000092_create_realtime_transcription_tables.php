<?php

declare(strict_types=1);

/**
 * Migration 92 — NI2-820: Transcrição Nutricional em Tempo Real na Consulta.
 *
 * Cria as tabelas necessárias para:
 *   1. consultation_ai_consents: Registro do consentimento LGPD antes da captação.
 *   2. consultation_transcription_sessions: Sessão de transcrição (modo ao vivo/discreto, estados).
 *   3. consultation_transcription_segments: Segmentos incrementais transcritos com controle de sequência e idempotência.
 *   4. consultation_evolution_drafts: Minuta estruturada da evolução com revisão e aprovação explícita.
 *
 * Compatível com MySQL 5.7+ / 8.0+ e SQLite.
 */
class CreateRealtimeTranscriptionTables
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        // 1. Consentimentos de IA / Gravação (LGPD)
        $db->exec("CREATE TABLE IF NOT EXISTS consultation_ai_consents (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'granted',
            terms_version VARCHAR(50) NOT NULL DEFAULT 'v1.0',
            consented_at_utc DATETIME NOT NULL,
            revoked_at_utc DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // 2. Sessões de Transcrição em Tempo Real
        $db->exec("CREATE TABLE IF NOT EXISTS consultation_transcription_sessions (
            id {$pk},
            session_uuid VARCHAR(64) NOT NULL UNIQUE,
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'live',
            status VARCHAR(30) NOT NULL DEFAULT 'connected',
            started_at_utc DATETIME NOT NULL,
            paused_at_utc DATETIME NULL,
            resumed_at_utc DATETIME NULL,
            completed_at_utc DATETIME NULL,
            duration_seconds INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // 3. Segmentos Incrementais da Transcrição
        $db->exec("CREATE TABLE IF NOT EXISTS consultation_transcription_segments (
            id {$pk},
            session_id INTEGER NOT NULL,
            clinic_id INTEGER NOT NULL,
            sequence_number INTEGER NOT NULL,
            speaker VARCHAR(50) NOT NULL DEFAULT 'speaker_uncertain',
            text TEXT NOT NULL,
            is_final INTEGER NOT NULL DEFAULT 1,
            confidence REAL NULL,
            start_seconds REAL NULL,
            end_seconds REAL NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // 4. Minutas Estruturadas de Evolução Clínica Nutricional
        $db->exec("CREATE TABLE IF NOT EXISTS consultation_evolution_drafts (
            id {$pk},
            session_id INTEGER NOT NULL,
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            professional_id INTEGER NOT NULL,
            chief_complaint TEXT NULL,
            nutritional_goals TEXT NULL,
            dietary_routine TEXT NULL,
            reported_anthropometry TEXT NULL,
            clinical_symptoms TEXT NULL,
            adherence_notes TEXT NULL,
            agreed_conduct TEXT NULL,
            next_appointment_notes TEXT NULL,
            full_evolution_text TEXT NOT NULL,
            approval_status VARCHAR(20) NOT NULL DEFAULT 'draft',
            approved_by INTEGER NULL,
            approved_at_utc DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine};");

        // Criação de índices para performance e isolamento multi-tenant
        $createIndex = function(string $table, string $name, string $cols, bool $unique = false) use ($db, $driver): void {
            $uniqueSql = $unique ? 'UNIQUE ' : '';
            if ($driver === 'mysql') {
                try {
                    $st = $db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$name}' LIMIT 1");
                    if (!$st || !$st->fetchColumn()) {
                        $db->exec("CREATE {$uniqueSql}INDEX {$name} ON {$table} ({$cols})");
                    }
                } catch (\Throwable $e) {
                    try { $db->exec("CREATE {$uniqueSql}INDEX {$name} ON {$table} ({$cols})"); } catch (\Throwable $ignored) {}
                }
            } else {
                $db->exec("CREATE {$uniqueSql}INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})");
            }
        };

        $createIndex('consultation_ai_consents', 'idx_ai_consent_clinic_appt', 'clinic_id, appointment_id');
        $createIndex('consultation_transcription_sessions', 'idx_transcription_sess_clinic', 'clinic_id, appointment_id');
        $createIndex('consultation_transcription_segments', 'uq_transcription_seg_seq', 'session_id, sequence_number', true);
        $createIndex('consultation_transcription_segments', 'idx_transcription_seg_clinic', 'clinic_id, session_id');
        $createIndex('consultation_evolution_drafts', 'idx_evolution_drafts_clinic_appt', 'clinic_id, appointment_id');
    }

    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS consultation_evolution_drafts;");
        $db->exec("DROP TABLE IF EXISTS consultation_transcription_segments;");
        $db->exec("DROP TABLE IF EXISTS consultation_transcription_sessions;");
        $db->exec("DROP TABLE IF EXISTS consultation_ai_consents;");
    }
}
