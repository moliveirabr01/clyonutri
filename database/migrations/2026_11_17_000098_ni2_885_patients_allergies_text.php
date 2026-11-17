<?php

declare(strict_types=1);

/**
 * Migração 98 (NI2-885): patients.allergies VARCHAR(500) -> TEXT no MySQL.
 * Desde a v54.68 a alergia do cadastro é gravada cifrada (enc:v1, ~1,4x o texto + 35 caracteres). Uma alergia com mais
 * de ~340 caracteres não cabia em VARCHAR(500): no MySQL estrito o cadastro dava erro; sem modo estrito o envelope era
 * cortado e ficava ilegível. No SQLite o tamanho não é imposto: nada a fazer. Idempotente.
 */
final class Ni2PatientsAllergiesText
{
    public function up(PDO $db): void
    {
        if ((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $type = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'allergies'")->fetchColumn();
        if ($type !== false && strtolower((string)$type) === 'varchar') {
            $db->exec('ALTER TABLE patients MODIFY allergies TEXT NULL');
        }
    }
}
