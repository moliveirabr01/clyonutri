<?php

declare(strict_types=1);

/**
 * Migration 58 (NI2-481): tabela de recuperação de senha do PACIENTE.
 *
 * Separada de password_resets (que é dos profissionais, por e-mail global): o paciente é
 * escopado por clínica e o mesmo e-mail pode existir em clínicas diferentes, então o reset é
 * por patient_id. Só o hash SHA-256 do token é guardado; expiração estrita; uso único (used_at).
 *
 * Idempotente e compatível com MySQL 5.7 (sem recursos exclusivos de 8) e SQLite.
 */
class CreatePatientPasswordResets
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS patient_password_resets (
            id {$pk},
            clinic_id INTEGER NOT NULL,
            patient_id INTEGER NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ){$engine}");

        if (!$this->hasIndex($db, $mysql, 'patient_password_resets', 'idx_patient_pwreset_patient')) {
            $db->exec('CREATE INDEX idx_patient_pwreset_patient ON patient_password_resets (patient_id, expires_at)');
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS patient_password_resets');
    }

    private function hasIndex(PDO $db, bool $mysql, string $table, string $index): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $index]);
            return (int)$st->fetchColumn() > 0;
        }
        $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([$index]);
        return (int)$st->fetchColumn() > 0;
    }
}
