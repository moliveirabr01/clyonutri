<?php

declare(strict_types=1);

/**
 * Migração 73 (NI2-223: [P0] Anamnese estruturada completa com campos clínicos condicionais).
 * Adiciona colunas para os 12 blocos clínicos estruturados e condicionais na tabela anamneses.
 * Idempotente, compatível com MySQL 5.7/8.0 e SQLite.
 */
class AddStructuredConditionalFieldsToAnamneses
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';

        $this->columns($db, $mysql, 'anamneses', [
            'chief_complaint' => "{$text} NULL",
            'family_history' => "{$text} NULL",
            'pathologies' => "{$text} NULL",
            'has_surgeries' => "VARCHAR(10) NOT NULL DEFAULT 'no'",
            'surgeries_details' => "{$text} NULL",
            'medication_usage' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
            'medications_details' => "{$text} NULL",
            'supplement_usage' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
            'supplements_details' => "{$text} NULL",
            'intolerance_assessment' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
            'alcohol_consumption' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
            'alcohol_details' => "{$text} NULL",
            'smoking_status' => "VARCHAR(20) NOT NULL DEFAULT 'non_smoker'",
            'smoking_details' => "{$text} NULL",
            'sleep_hours' => "DECIMAL(3,1) NULL",
            'sleep_quality' => "VARCHAR(20) NULL",
            'sleep_notes' => "{$text} NULL",
            'physical_activity' => "VARCHAR(20) NOT NULL DEFAULT 'sedentary'",
            'activity_modality' => "VARCHAR(150) NULL",
            'activity_frequency_weekly' => "INTEGER NULL",
            'activity_duration_minutes' => "INTEGER NULL",
            'referenced_exams' => "{$text} NULL",
            'structured_data_json' => "{$text} NULL",
        ]);

        if (class_exists(\ClyoNutri\Core\Database\SchemaInfo::class)) {
            \ClyoNutri\Core\Database\SchemaInfo::reset();
        }
    }

    public function down(PDO $db): void
    {
        // Migração aditiva para preservar dados históricos
    }

    private function columns(PDO $db, bool $mysql, string $table, array $columns): void
    {
        if (!$this->hasTable($db, $mysql, $table)) {
            return;
        }
        foreach ($columns as $name => $definition) {
            if (!$this->hasColumn($db, $mysql, $table, $name)) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
    }

    private function hasTable(PDO $db, bool $mysql, string $table): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        }
        $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    }

    private function hasColumn(PDO $db, bool $mysql, string $table, string $column): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        }
        foreach ($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (($col['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }
}
