<?php
declare(strict_types=1);

/**
 * Migração 78 (NI2-635, fase 1 — motor): anamnese modular por protocolos clínicos versionados.
 *
 * Só cria estrutura. Não grava nenhuma pergunta clínica: protocolo sem versão aprovada por nutricionista
 * habilitado não aparece para ninguém, e a chave global feature_flag_clinical_protocols nasce desligada.
 * Idempotente (CREATE TABLE IF NOT EXISTS / índices conferidos). MySQL 5.7/8.0, MariaDB 10.3+ e SQLite.
 */
final class CreateClinicalProtocols
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $tables = [
            // Catálogo (global). kind: condition | profile (perfil não-diagnóstico). catalog_phase: A, B ou C.
            'clinical_protocols' => "id $id, code VARCHAR(60) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'condition', catalog_phase CHAR(1) NOT NULL DEFAULT 'A', requires_specialist_review INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL",
            // Versões imutáveis depois de aprovadas (content_sha256). status: draft | in_review | approved | retired.
            'clinical_protocol_versions' => "id $id, protocol_id INT NOT NULL, version INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', definition_json $text NOT NULL, content_sha256 CHAR(64) NOT NULL, references_text $text NULL, authored_by_user_id INT NULL, submitted_at DATETIME NULL, approved_by_user_id INT NULL, approved_by_name VARCHAR(160) NULL, approved_by_crn VARCHAR(30) NULL, specialist_review_note $text NULL, approved_at DATETIME NULL, review_due_at DATE NULL, retired_at DATETIME NULL, created_at DATETIME NOT NULL, UNIQUE(protocol_id, version)",
            // Trilha editorial (quem criou, enviou, aprovou, aposentou) — nunca apagada.
            'clinical_protocol_events' => "id $id, protocol_version_id INT NOT NULL, action VARCHAR(30) NOT NULL, actor_user_id INT NULL, note $text NULL, created_at DATETIME NOT NULL",
            // Adesão por clínica (desligado por padrão).
            'clinic_protocol_settings' => "id $id, clinic_id INT NOT NULL, protocol_id INT NOT NULL, enabled INT NOT NULL DEFAULT 0, updated_by_user_id INT NULL, updated_at DATETIME NOT NULL, UNIQUE(clinic_id, protocol_id)",
            // Condição do paciente com proveniência. status: suggested | reported | suspected | documented | resolved | dismissed.
            // provenance: free_text (sugestão) | patient | self_reported | professional | report (laudo).
            'patient_conditions' => "id $id, clinic_id INT NOT NULL, patient_id INT NOT NULL, protocol_code VARCHAR(60) NOT NULL, status VARCHAR(20) NOT NULL, provenance VARCHAR(20) NOT NULL, evidence_text VARCHAR(500) NULL, noted_at DATE NULL, valid_until DATE NULL, created_by_user_id INT NULL, confirmed_by_user_id INT NULL, confirmed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL",
            'patient_condition_events' => "id $id, clinic_id INT NOT NULL, condition_id INT NOT NULL, from_status VARCHAR(20) NULL, to_status VARCHAR(20) NOT NULL, provenance VARCHAR(20) NULL, actor_user_id INT NULL, note VARCHAR(500) NULL, created_at DATETIME NOT NULL",
            // Respostas: sempre uma linha nova (histórico longitudinal). is_draft separa rascunho de concluída (como NI2-605).
            'protocol_responses' => "id $id, clinic_id INT NOT NULL, patient_id INT NOT NULL, protocol_version_id INT NOT NULL, anamnesis_id INT NULL, answers_json $text NOT NULL, provenance VARCHAR(20) NOT NULL, is_draft INT NOT NULL DEFAULT 1, answered_by_user_id INT NULL, created_at DATETIME NOT NULL",
        ];
        foreach ($tables as $t => $cols) {
            $db->exec("CREATE TABLE IF NOT EXISTS $t ($cols)$engine");
        }
        $indexes = [
            ['clinical_protocol_versions', 'idx_cpv_status', 'protocol_id,status,version'],
            ['clinical_protocol_events', 'idx_cpe_version', 'protocol_version_id,id'],
            ['patient_conditions', 'idx_pc_patient', 'clinic_id,patient_id,status'],
            ['patient_condition_events', 'idx_pce_condition', 'clinic_id,condition_id,id'],
            ['protocol_responses', 'idx_pr_patient', 'clinic_id,patient_id,protocol_version_id,is_draft,id'],
        ];
        foreach ($indexes as [$t, $i, $c]) {
            if ($mysql) {
                $q = $db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
                $q->execute([$t, $i]);
                if (!$q->fetchColumn()) {
                    $db->exec("CREATE INDEX $i ON $t ($c)");
                }
            } else {
                $db->exec("CREATE INDEX IF NOT EXISTS $i ON $t ($c)");
            }
        }
    }
}
