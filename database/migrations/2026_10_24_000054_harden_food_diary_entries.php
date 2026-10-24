<?php

declare(strict_types=1);

/**
 * Migration 54 (NI2-132): diário alimentar.
 *  - request_hash  : SHA-256 do conteúdo do registro; a mesma Idempotency-Key com conteúdo diferente vira 409.
 *  - consumed_tz   : fuso enviado pelo aparelho da paciente (IANA ou deslocamento, ex.: America/Manaus, -04:00).
 *                    consumed_at continua gravado no horário do consultório (APP_TIMEZONE), como no resto do sistema.
 *  - dois índices para a paginação por cursor (paciente e clínica, por consumed_at).
 * Idempotente (MySQL/MariaDB e SQLite). Registros antigos ficam com request_hash e consumed_tz nulos.
 */
class HardenFoodDiaryEntries
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach (['request_hash' => 'CHAR(64) NULL', 'consumed_tz' => 'VARCHAR(64) NULL'] as $column => $definition) {
            if (!$this->hasColumn($db, $mysql, $column)) {
                $db->exec("ALTER TABLE food_diary_entries ADD COLUMN {$column} {$definition}");
            }
        }
        foreach ([
            'idx_food_diary_patient_consumed' => '(patient_id, clinic_id, consumed_at)',
            'idx_food_diary_clinic_consumed' => '(clinic_id, consumed_at)',
        ] as $index => $columns) {
            if (!$this->hasIndex($db, $mysql, $index)) {
                $db->exec("CREATE INDEX {$index} ON food_diary_entries {$columns}");
            }
        }
    }

    public function down(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        // No MySQL/MariaDB a chave estrangeira de clinic_id passa a usar o índice composto; antes de removê-lo
        // recria o índice simples, senão o servidor recusa o DROP (erro 1553).
        if ($mysql && !$this->hasIndex($db, $mysql, 'clinic_id')) {
            $db->exec('CREATE INDEX clinic_id ON food_diary_entries (clinic_id)');
        }
        foreach (['idx_food_diary_patient_consumed', 'idx_food_diary_clinic_consumed'] as $index) {
            if ($this->hasIndex($db, $mysql, $index)) {
                $db->exec($mysql ? "DROP INDEX {$index} ON food_diary_entries" : "DROP INDEX {$index}");
            }
        }
        foreach (['request_hash', 'consumed_tz'] as $column) {
            if ($this->hasColumn($db, $mysql, $column)) {
                $db->exec("ALTER TABLE food_diary_entries DROP COLUMN {$column}");
            }
        }
    }

    private function hasColumn(PDO $db, bool $mysql, string $column): bool
    {
        if ($mysql) {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_diary_entries' AND COLUMN_NAME = ?");
            $st->execute([$column]);
            return (int)$st->fetchColumn() > 0;
        }
        foreach ($db->query('PRAGMA table_info(food_diary_entries)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    private function hasIndex(PDO $db, bool $mysql, string $index): bool
    {
        if ($mysql) {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_diary_entries' AND INDEX_NAME = ?");
            $st->execute([$index]);
            return (int)$st->fetchColumn() > 0;
        }
        $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([$index]);
        return (int)$st->fetchColumn() > 0;
    }
}
