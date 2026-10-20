<?php

declare(strict_types=1);

/**
 * Migration 40 (v14/v17, UX-03 & V16-02): rascunho das anotações da consulta presencial/agenda (/consulta/{id}).
 * Um rascunho por consulta e usuário, com versão para controle de conflito entre abas.
 * Política de integridade/retenção (V16-02): ON DELETE RESTRICT em clinic_id e appointment_id para impedir
 * exclusão física em cascata de anotações clínicas em andamento. O descarte legítimo ocorre apenas via
 * ConsultationDraftService::discard() após conclusão da consulta, com registro em trilha de auditoria.
 */
class CreateConsultationNoteDrafts
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $clinicType = $this->mysqlColumnType($db, 'clinics', 'id') ?: 'INT';
            $apptType = $this->mysqlColumnType($db, 'appointments', 'id') ?: 'INT';
            $userType = $this->mysqlColumnType($db, 'users', 'id') ?: 'INT';
            $pkType = (stripos($clinicType, 'bigint') !== false || stripos($apptType, 'bigint') !== false) ? 'BIGINT' : 'INT';

            $db->exec("CREATE TABLE IF NOT EXISTS consultation_note_drafts (
                id {$pkType} NOT NULL AUTO_INCREMENT PRIMARY KEY,
                clinic_id {$clinicType} NOT NULL,
                appointment_id {$apptType} NOT NULL,
                user_id {$userType} NOT NULL,
                evolution_summary MEDIUMTEXT NULL,
                weight_kg VARCHAR(20) NULL,
                waist_cm VARCHAR(20) NULL,
                version INT NOT NULL DEFAULT 1,
                updated_at_utc DATETIME NOT NULL,
                UNIQUE KEY uq_consult_drafts_appt_user (appointment_id, user_id),
                KEY idx_consult_drafts_clinic (clinic_id),
                CONSTRAINT fk_consult_drafts_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT,
                CONSTRAINT fk_consult_drafts_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            return;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS consultation_note_drafts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            evolution_summary TEXT NULL,
            weight_kg VARCHAR(20) NULL,
            waist_cm VARCHAR(20) NULL,
            version INTEGER NOT NULL DEFAULT 1,
            updated_at_utc DATETIME NOT NULL,
            UNIQUE (appointment_id, user_id),
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT
        );");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_consult_drafts_clinic ON consultation_note_drafts (clinic_id);");
        self::ensureRestrictForeignKeys($db);
    }

    /**
     * Garante que bancos SQLite pré-existentes (criados com ON DELETE CASCADE) sejam migrados
     * de forma não destrutiva para ON DELETE RESTRICT (V16-02).
     */
    public static function ensureRestrictForeignKeys(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }
        $fks = $db->query("PRAGMA foreign_key_list('consultation_note_drafts')")->fetchAll(PDO::FETCH_ASSOC);
        if (!$fks) {
            return;
        }
        $needsRebuild = false;
        foreach ($fks as $fk) {
            if (strtoupper((string)($fk['on_delete'] ?? '')) !== 'RESTRICT') {
                $needsRebuild = true;
                break;
            }
        }
        if (!$needsRebuild) {
            return;
        }
        $fkState = (int)$db->query('PRAGMA foreign_keys')->fetchColumn();
        if ($fkState === 1 && !$db->inTransaction()) {
            $db->exec('PRAGMA foreign_keys = OFF');
        }
        $db->exec("CREATE TABLE consultation_note_drafts__v17 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            appointment_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            evolution_summary TEXT NULL,
            weight_kg VARCHAR(20) NULL,
            waist_cm VARCHAR(20) NULL,
            version INTEGER NOT NULL DEFAULT 1,
            updated_at_utc DATETIME NOT NULL,
            UNIQUE (appointment_id, user_id),
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE RESTRICT,
            FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT
        );");
        $db->exec("INSERT INTO consultation_note_drafts__v17 (id, clinic_id, appointment_id, user_id, evolution_summary, weight_kg, waist_cm, version, updated_at_utc)
            SELECT id, clinic_id, appointment_id, user_id, evolution_summary, weight_kg, waist_cm, version, updated_at_utc FROM consultation_note_drafts;");
        $db->exec("DROP TABLE consultation_note_drafts;");
        $db->exec("ALTER TABLE consultation_note_drafts__v17 RENAME TO consultation_note_drafts;");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_consult_drafts_clinic ON consultation_note_drafts (clinic_id);");
        if ($fkState === 1 && !$db->inTransaction()) {
            $db->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS consultation_note_drafts');
    }

    private function mysqlColumnType(PDO $db, string $table, string $column): ?string
    {
        try {
            $stmt = $db->prepare(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            $type = $stmt->fetchColumn();
            return is_string($type) && $type !== '' ? strtoupper($type) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}


