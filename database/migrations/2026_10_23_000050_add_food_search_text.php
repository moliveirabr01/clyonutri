<?php

declare(strict_types=1);

/**
 * Migration 50 (NI2-73): busca de alimentos por termos.
 * foods.search_text = nome sem acento, minúsculo, pontuação vira espaço (" arroz integral cozido ").
 * Preenchido aqui para o que já existe e, depois, pelo próprio app (FoodSearchIndex::sync) para linhas novas,
 * inclusive as inseridas pelo SQL manual da TACO.
 */
class AddFoodSearchText
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $has = false;
        if ($mysql) {
            $st = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'search_text'");
            $has = (int)$st->fetchColumn() > 0;
        } else {
            foreach ($db->query('PRAGMA table_info(foods)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $has = $has || $c['name'] === 'search_text';
            }
        }
        if (!$has) {
            $db->exec('ALTER TABLE foods ADD COLUMN search_text VARCHAR(255) NULL');
        }
        $idx = $mysql
            ? (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_search_text'")->fetchColumn()
            : (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_foods_search_text'")->fetchColumn();
        if ($idx === 0) {
            $db->exec('CREATE INDEX idx_foods_search_text ON foods (search_text)');
        }
        // Nome indexado: a carga da Base de Alimentos confere os alimentos de referência por nome a cada abertura.
        $idx = $mysql
            ? (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'idx_foods_name'")->fetchColumn()
            : (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_foods_name'")->fetchColumn();
        if ($idx === 0) {
            $db->exec('CREATE INDEX idx_foods_name ON foods (name)');
        }
        \ClyoNutri\Domain\Food\FoodSearchIndex::sync($db, 1000000);
    }
}
