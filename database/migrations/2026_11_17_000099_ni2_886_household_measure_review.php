<?php

declare(strict_types=1);

/**
 * Migração 99 (NI2-886): revisão profissional das medidas caseiras estimadas ("Estimativa ClyoNutri (revisar)").
 * food_household_measures ganha o estado da revisão, quem revisou (id e nome, para o SQL de produção) e o peso
 * anterior à correção. A medida removida não é apagada (o histórico e os planos antigos continuam legíveis):
 * só deixa de ser oferecida. Idempotente; SQL manual equivalente em database/manual/ni2_886_medidas_caseiras_revisao_mysql57.sql.
 */
final class Ni2HouseholdMeasureReview
{
    public function up(PDO $db): void
    {
        $columns = [
            'review_status' => 'VARCHAR(20) NULL',
            'reviewed_by' => 'INTEGER NULL',
            'reviewer_name' => 'VARCHAR(160) NULL',
            'reviewed_at' => 'DATETIME NULL',
            'grams_before_review' => 'DECIMAL(7,2) NULL',
        ];
        foreach ($columns as $name => $type) {
            if (!$this->hasColumn($db, $name)) {
                $db->exec("ALTER TABLE food_household_measures ADD COLUMN {$name} {$type}");
            }
        }
    }

    private function hasColumn(PDO $db, string $col): bool
    {
        if ((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_household_measures' AND COLUMN_NAME = ?");
            $st->execute([$col]);
            return (int)$st->fetchColumn() > 0;
        }
        foreach ($db->query('PRAGMA table_info(food_household_measures)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === $col) {
                return true;
            }
        }
        return false;
    }
}
