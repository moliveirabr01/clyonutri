<?php

declare(strict_types=1);

/**
 * Migration 34: chave externa estável da TACO no catálogo de alimentos.
 *
 * - taco_code: "Número do Alimento" da TACO 4ª edição (1..597). Único (NULL permitido para os demais).
 * - nutrient_flags: valores originais não numéricos da TACO (Tr, NA, *, em branco), ex.: "fiber_g=NA".
 *   As colunas de nutrientes são NOT NULL; esses casos ficam 0 e a marcação preserva o significado.
 * Idempotente: só cria o que faltar.
 */
class AddTacoCodeToFoods
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $cols = [];
        if ($mysql) {
            foreach ($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods'") as $r) {
                $cols[] = $r['COLUMN_NAME'];
            }
        } else {
            foreach ($db->query('PRAGMA table_info(foods)') as $r) {
                $cols[] = $r['name'];
            }
        }
        if (!in_array('taco_code', $cols, true)) {
            $db->exec('ALTER TABLE foods ADD COLUMN taco_code INTEGER NULL');
        }
        if (!in_array('nutrient_flags', $cols, true)) {
            $db->exec('ALTER TABLE foods ADD COLUMN nutrient_flags VARCHAR(255) NULL');
        }
        if ($mysql) {
            $has = (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'foods' AND INDEX_NAME = 'uq_foods_taco_code'")->fetchColumn();
            if ($has === 0) {
                $db->exec('ALTER TABLE foods ADD UNIQUE INDEX uq_foods_taco_code (taco_code)');
            }
        } else {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_foods_taco_code ON foods (taco_code)');
        }
    }
}
