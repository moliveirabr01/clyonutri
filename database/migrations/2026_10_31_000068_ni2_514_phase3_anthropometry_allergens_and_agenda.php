<?php

declare(strict_types=1);

/**
 * Migration 68 (v48, NI2-514 Fase 3: NI2-524 e NI2-525). A NI2-526 (comparar versões do plano) e a NI2-527
 * (confirmação pela agenda) não precisam de coluna nova.
 * Idempotente, sem chave estrangeira, só acrescenta colunas que aceitam vazio. MySQL 5.7/8.0 e SQLite.
 * SQL manual equivalente para o phpMyAdmin: database/manual/atualizacao-v49-{preflight,completa,verificacao}.sql.
 *
 * 1. NI2-524: amplia anthropometric_assessments de 7 para 14 circunferências corporais
 *    (abdomen_cm, shoulder_cm, arm_contracted_cm, forearm_cm, wrist_cm, thigh_proximal_cm, calf_left_cm)
 *    e adiciona campos de Bioimpedância e proveniência explícita (body_fat_source, bioimpedance_device,
 *    visceral_fat_level, total_body_water_pct, bone_mass_kg, phase_angle_deg), mantendo o cálculo derivado
 *    de dobras separado da medida manual/BIA.
 * 2. NI2-525: adiciona allergen_alert_json e allergen_ack_reason em meal_plan_items e meal_plan_substitutions
 *    para preservar a decisão clínica e justificativa de alérgeno/incerteza registrada no momento da adição.
 */
class Ni2Phase3AnthropometryAllergensAndAgenda
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';

        $this->columns($db, $mysql, 'anthropometric_assessments', [
            'abdomen_cm' => 'DECIMAL(5,2) NULL',
            'shoulder_cm' => 'DECIMAL(5,2) NULL',
            'arm_contracted_cm' => 'DECIMAL(5,2) NULL',
            'forearm_cm' => 'DECIMAL(5,2) NULL',
            'wrist_cm' => 'DECIMAL(5,2) NULL',
            'thigh_proximal_cm' => 'DECIMAL(5,2) NULL',
            'calf_left_cm' => 'DECIMAL(5,2) NULL',
            'body_fat_source' => 'VARCHAR(40) NULL',
            'bioimpedance_device' => 'VARCHAR(120) NULL',
            'visceral_fat_level' => 'DECIMAL(5,2) NULL',
            'total_body_water_pct' => 'DECIMAL(5,2) NULL',
            'bone_mass_kg' => 'DECIMAL(5,2) NULL',
            'phase_angle_deg' => 'DECIMAL(4,2) NULL',
        ]);

        $this->columns($db, $mysql, 'meal_plan_items', [
            'allergen_alert_json' => "{$text} NULL",
            'allergen_ack_reason' => 'VARCHAR(500) NULL',
        ]);

        $this->columns($db, $mysql, 'meal_plan_substitutions', [
            'allergen_alert_json' => "{$text} NULL",
            'allergen_ack_reason' => 'VARCHAR(500) NULL',
        ]);

        if (class_exists(\ClyoNutri\Core\Database\SchemaInfo::class)) {
            \ClyoNutri\Core\Database\SchemaInfo::reset();
        }
    }

    public function down(PDO $db): void
    {
        // Colunas opcionais (nullable): mantidas para compatibilidade reversa sem perda de dados.
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
