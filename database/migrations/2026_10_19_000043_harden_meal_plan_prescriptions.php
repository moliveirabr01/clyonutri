<?php

declare(strict_types=1);

use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;

/**
 * Code review NI2-9 (19/10/2026).
 *
 * 1. Peso em gramas persistido no momento do cálculo (substitui a subconsulta aproximada
 *    por medida caseira): weight_grams + weight_is_estimated em itens e substituições.
 * 2. Autoria e justificativa da publicação gravadas na versão (published_by_user_id,
 *    allergen_override_reason).
 * 3. Unicidade do número de versão por paciente e índice de leitura da versão vigente.
 *    Se já houver números repetidos (corrida anterior), o índice único NÃO é criado e nada
 *    é alterado: rode database/manual/preflight-2026-10-19-planos-ni2-9.sql e corrija antes.
 */
class HardenMealPlanPrescriptions
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $flag = $driver === 'sqlite' ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0';

        foreach (['meal_plan_items', 'meal_plan_substitutions'] as $table) {
            $this->addColumnIfNotExists($db, $table, 'weight_grams', 'DECIMAL(9,2) NULL');
            $this->addColumnIfNotExists($db, $table, 'weight_is_estimated', $flag);
        }
        $this->addColumnIfNotExists($db, 'meal_plan_versions', 'published_by_user_id', 'INTEGER NULL');
        $this->addColumnIfNotExists($db, 'meal_plan_versions', 'allergen_override_reason', 'TEXT NULL');

        $this->backfillWeights($db);

        if (!$this->indexExists($db, 'meal_plan_versions', 'idx_meal_plan_versions_current')) {
            $db->exec('CREATE INDEX idx_meal_plan_versions_current ON meal_plan_versions (clinic_id, patient_id, status)');
        }
        $dupes = (int)$db->query('SELECT COUNT(*) FROM (SELECT clinic_id, patient_id, version_number FROM meal_plan_versions
            GROUP BY clinic_id, patient_id, version_number HAVING COUNT(*) > 1) d')->fetchColumn();
        if ($dupes === 0 && !$this->indexExists($db, 'meal_plan_versions', 'uq_meal_plan_versions_number')) {
            $db->exec('CREATE UNIQUE INDEX uq_meal_plan_versions_number ON meal_plan_versions (clinic_id, patient_id, version_number)');
        } elseif ($dupes > 0) {
            error_log("NI2-9: uq_meal_plan_versions_number não criado: {$dupes} número(s) de versão repetido(s). Ver preflight-2026-10-19-planos-ni2-9.sql.");
        }
    }

    /** Preenche o peso das linhas antigas com a mesma conversão usada no cálculo; unidade desconhecida fica NULL. */
    private function backfillWeights(PDO $db): void
    {
        $converter = new HouseholdMeasureConverter($db);
        foreach (['meal_plan_items', 'meal_plan_substitutions'] as $table) {
            $rows = $db->query("SELECT id, food_id, amount, measure_unit FROM {$table} WHERE weight_grams IS NULL")->fetchAll(PDO::FETCH_ASSOC);
            $up = $db->prepare("UPDATE {$table} SET weight_grams = :g, weight_is_estimated = :e WHERE id = :id");
            foreach ($rows as $r) {
                $amount = (float)$r['amount'];
                if (!is_finite($amount) || $amount <= 0) {
                    continue;
                }
                try {
                    $conv = $converter->convertDetailed($amount, (string)$r['measure_unit'], (int)$r['food_id']);
                } catch (\InvalidArgumentException) {
                    continue;
                }
                $up->execute(['g' => round($conv['grams'], 2), 'e' => $conv['estimated'] ? 1 : 0, 'id' => (int)$r['id']]);
            }
        }
    }

    private function indexExists(PDO $db, string $table, string $index): bool
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :n");
            $st->execute(['n' => $index]);
            return (bool)$st->fetchColumn();
        }
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :n');
        $st->execute(['t' => $table, 'n' => $index]);
        return (int)$st->fetchColumn() > 0;
    }

    private function addColumnIfNotExists(PDO $db, string $table, string $column, string $typeDef): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (strcasecmp($col['name'], $column) === 0) {
                    return;
                }
            }
        } else {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
            $st->execute(['t' => $table, 'c' => $column]);
            if ((int)$st->fetchColumn() > 0) {
                return;
            }
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$typeDef}");
    }
}
