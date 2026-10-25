<?php

declare(strict_types=1);

/**
 * Migration 56 (NI2-450): Período de avaliação de 14 dias (trial) e fim de faturas fictícias.
 * Adiciona a coluna `trial_ends_at` em `clinic_subscriptions` para controle do período de testes.
 * 
 * Idempotente e compatível com MySQL 5.7 (sem ADD COLUMN IF NOT EXISTS) e SQLite.
 */
class AddTrialEndsAtToClinicSubscriptions
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if (!$this->hasColumn($db, $mysql, 'clinic_subscriptions', 'trial_ends_at')) {
            $db->exec('ALTER TABLE clinic_subscriptions ADD COLUMN trial_ends_at DATETIME NULL');
        }

        if (!$this->hasIndex($db, $mysql, 'clinic_subscriptions', 'idx_clinic_sub_trial')) {
            $db->exec('CREATE INDEX idx_clinic_sub_trial ON clinic_subscriptions (status, trial_ends_at)');
        }
    }

    public function down(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($this->hasIndex($db, $mysql, 'clinic_subscriptions', 'idx_clinic_sub_trial')) {
            $db->exec($mysql ? 'DROP INDEX idx_clinic_sub_trial ON clinic_subscriptions' : 'DROP INDEX idx_clinic_sub_trial');
        }

        if ($this->hasColumn($db, $mysql, 'clinic_subscriptions', 'trial_ends_at')) {
            $db->exec('ALTER TABLE clinic_subscriptions DROP COLUMN trial_ends_at');
        }
    }

    private function hasColumn(PDO $db, bool $mysql, string $table, string $column): bool
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        }

        foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return true;
            }
        }

        return false;
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
