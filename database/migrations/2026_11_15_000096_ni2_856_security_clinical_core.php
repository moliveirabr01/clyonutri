<?php

declare(strict_types=1);

/**
 * v54.67 · Migração 96 (NI2-856):
 *  - NI2-858 (SEC-001): OTP, IP do solicitante e alerta de mesmo IP em consultation_ai_consent_requests
 *  - NI2-859 (SEC-004): TOTP/MFA em users
 *  - NI2-861 (F-03): appointment_id anulável em anamneses, anthropometric_assessments, meal_plans e meal_plan_versions
 */
final class Ni2SecurityClinicalCore
{
    public function up(PDO $db): void
    {
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasColumn = static function (string $table, string $column) use ($db, $driver): bool {
            if ($driver === 'sqlite') {
                $st = $db->query("PRAGMA table_info({$table})");
                if (!$st) {
                    return false;
                }
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }
            $st = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
            $st->execute(['t' => $table, 'c' => $column]);
            return (int)$st->fetchColumn() > 0;
        };

        $addColumn = static function (string $table, string $column, string $sqliteDef, string $mysqlDef) use ($db, $driver, $hasColumn): void {
            if ($hasColumn($table, $column)) {
                return;
            }
            $def = $driver === 'sqlite' ? $sqliteDef : $mysqlDef;
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$def}");
        };

        // NI2-858 (SEC-001)
        $addColumn('consultation_ai_consent_requests', 'otp_hash', 'TEXT NULL', 'VARCHAR(255) NULL');
        $addColumn('consultation_ai_consent_requests', 'otp_expires_at_utc', 'TEXT NULL', 'DATETIME NULL');
        $addColumn('consultation_ai_consent_requests', 'otp_attempts', 'INTEGER NOT NULL DEFAULT 0', 'INT NOT NULL DEFAULT 0');
        $addColumn('consultation_ai_consent_requests', 'requested_ip_hash', 'TEXT NULL', 'VARCHAR(64) NULL');
        $addColumn('consultation_ai_consent_requests', 'same_ip_alert', 'INTEGER NOT NULL DEFAULT 0', 'TINYINT(1) NOT NULL DEFAULT 0');

        // NI2-859 (SEC-004)
        $addColumn('users', 'mfa_enabled', 'INTEGER NOT NULL DEFAULT 0', 'TINYINT(1) NOT NULL DEFAULT 0');
        $addColumn('users', 'mfa_secret_enc', 'TEXT NULL', 'TEXT NULL');
        $addColumn('users', 'mfa_recovery_codes_json', 'TEXT NULL', 'TEXT NULL');
        $addColumn('users', 'mfa_enrolled_at', 'TEXT NULL', 'DATETIME NULL');
        $addColumn('users', 'mfa_last_step', 'INTEGER NULL', 'BIGINT NULL');

        // NI2-861 (F-03)
        foreach (['anamneses', 'anthropometric_assessments', 'meal_plans', 'meal_plan_versions'] as $tbl) {
            $addColumn($tbl, 'appointment_id', 'INTEGER NULL', 'INT NULL');
        }
    }
}
