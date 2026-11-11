<?php

declare(strict_types=1);

/**
 * Migration 63 (v43, NI2-519 e NI2-520): idempotente e compatível com MySQL 5.7 e SQLite.
 *
 *  1. foods ganha a procedência por linha: versão da fonte, código do alimento na fonte, data da importação,
 *     forma de preparo e is_current. Uma versão nova de uma fonte entra como linha nova e a antiga passa a
 *     is_current = 0, sem mudar os valores (planos antigos continuam apontando para ela). UNIQUE em
 *     (source, source_version, source_code): a mesma fonte e versão não entra duas vezes. Alimentos TACO
 *     com número recebem a edição usada no seed (4ª ed. rev. e ampl., NEPA-UNICAMP, 2011).
 *  2. food_household_measures ganha source e source_version (medidas importadas citam a origem).
 *  3. food_favorites: favoritos da clínica (desempate da busca).
 *  4. food_search_selections: seleções e trocas de seleção por termo, dia e alimento (métrica de seleção incorreta).
 *  5. food_equivalence_rules: regras de equivalência editáveis pela clínica, com fonte obrigatória e marcação de
 *     revisão por nutricionista. Sem regra, o sistema só mostra a diferença de nutrientes.
 *  6. meal_plan_templates ganha visibilidade (pessoal ou clínica), aprovação pela clínica, método do plano e a
 *     meta de referência (sem dados do paciente).
 *  7. meal_plans ganha template_id, plan_method e a meta de referência copiada do modelo.
 *
 * Tipos e chaves: ids BIGINT(20) no MySQL e nenhuma chave estrangeira; ids são validados em PHP.
 */
class FoodProvenanceEquivalenceAndTemplateTargets
{
    public const TACO_VERSION = '4ª ed. rev. e ampl. (2011)';

    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $mysql ? 'BIGINT(20)' : 'INTEGER';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $this->columns($db, $mysql, 'foods', [
            'source_version' => 'VARCHAR(60) NULL',
            'source_code' => 'VARCHAR(40) NULL',
            'source_imported_at' => 'DATETIME NULL',
            'preparation' => 'VARCHAR(30) NULL',
            'is_current' => 'TINYINT(1) NOT NULL DEFAULT 1',
        ]);
        $this->index($db, $mysql, 'foods', 'uq_foods_source_version_code', '(source, source_version, source_code)', true);
        $this->index($db, $mysql, 'foods', 'idx_foods_source_code', '(source, source_code)');
        // Uma linha por número TACO (a de menor id, se houver repetição); as demais ficam sem versão para o
        // UNIQUE não falhar. Nada é apagado.
        $ids = $db->query("SELECT MIN(id) AS id, taco_code FROM foods WHERE source = 'TACO' AND taco_code IS NOT NULL AND clinic_id IS NULL
            GROUP BY taco_code")->fetchAll(PDO::FETCH_ASSOC);
        // Códigos que já têm versão (segunda execução): lidos antes, em PHP, para não usar subconsulta na própria tabela
        // dentro do UPDATE (erro 1093 no MySQL 5.7).
        $done = $db->prepare("SELECT source_code FROM foods WHERE source = 'TACO' AND source_version = ? AND source_code IS NOT NULL");
        $done->execute([self::TACO_VERSION]);
        $versioned = array_fill_keys(array_map('strval', $done->fetchAll(PDO::FETCH_COLUMN)), true);
        $st = $db->prepare('UPDATE foods SET source_version = ?, source_code = ? WHERE id = ? AND source_version IS NULL AND source_code IS NULL');
        foreach ($ids as $r) {
            $code = (string)(int)$r['taco_code'];
            if (isset($versioned[$code])) {
                continue;
            }
            $st->execute([self::TACO_VERSION, $code, (int)$r['id']]);
            $versioned[$code] = true;
        }

        // Preparo lido do nome ("Arroz, integral, cozido" -> "Cozido"), uma vez; linhas novas recebem no sync da busca.
        if (class_exists(\ClyoNutri\Domain\Food\FoodSearchIndex::class)) {
            $rows = $db->query('SELECT id, name FROM foods WHERE preparation IS NULL')->fetchAll(PDO::FETCH_ASSOC);
            $up = $db->prepare('UPDATE foods SET preparation = ? WHERE id = ? AND preparation IS NULL');
            foreach ($rows as $r) {
                $prep = \ClyoNutri\Domain\Food\FoodSearchIndex::preparation((string)$r['name']);
                if ($prep !== null) {
                    $up->execute([mb_substr($prep, 0, 30), (int)$r['id']]);
                }
            }
        }

        $this->columns($db, $mysql, 'food_household_measures', [
            'source' => 'VARCHAR(80) NULL',
            'source_version' => 'VARCHAR(60) NULL',
        ]);

        $db->exec("CREATE TABLE IF NOT EXISTS food_favorites (
            id {$pk},
            clinic_id {$big} NOT NULL,
            food_id {$big} NOT NULL,
            created_by_user_id {$big} NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine}");
        $this->index($db, $mysql, 'food_favorites', 'uq_food_favorites_clinic_food', '(clinic_id, food_id)', true);

        $db->exec("CREATE TABLE IF NOT EXISTS food_search_selections (
            id {$pk},
            clinic_id {$big} NOT NULL,
            metric_date DATE NOT NULL,
            term VARCHAR(80) NOT NULL,
            food_id {$big} NOT NULL,
            selections INT NOT NULL DEFAULT 0,
            corrections INT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ){$engine}");
        $this->index($db, $mysql, 'food_search_selections', 'uq_food_search_selection', '(clinic_id, metric_date, term, food_id)', true);

        $db->exec("CREATE TABLE IF NOT EXISTS food_equivalence_rules (
            id {$pk},
            clinic_id {$big} NOT NULL,
            name VARCHAR(120) NOT NULL,
            food_groups {$text} NOT NULL,
            energy_tolerance_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,
            macro_tolerance_pct DECIMAL(5,2) NOT NULL DEFAULT 20.00,
            source_note VARCHAR(500) NOT NULL,
            review_status VARCHAR(20) NOT NULL DEFAULT 'pendente',
            reviewed_by_user_id {$big} NULL,
            reviewed_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by_user_id {$big} NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            archived_at DATETIME NULL
        ){$engine}");
        $this->index($db, $mysql, 'food_equivalence_rules', 'idx_food_equivalence_rules_clinic', '(clinic_id, status)');

        $this->columns($db, $mysql, 'meal_plan_templates', [
            'visibility' => "VARCHAR(20) NOT NULL DEFAULT 'clinica'",
            'approval_status' => "VARCHAR(20) NOT NULL DEFAULT 'nao_aprovado'",
            'approved_by_user_id' => "{$big} NULL",
            'approved_at' => 'DATETIME NULL',
            'plan_method' => "VARCHAR(20) NOT NULL DEFAULT 'alimentos'",
            'target_json' => "{$text} NULL",
        ]);
        $this->index($db, $mysql, 'meal_plan_templates', 'idx_plan_templates_visibility', '(clinic_id, visibility, created_by_user_id)');

        $this->columns($db, $mysql, 'meal_plans', [
            'template_id' => "{$big} NULL",
            'plan_method' => 'VARCHAR(20) NULL',
            'reference_target_json' => "{$text} NULL",
        ]);
        if (class_exists(\ClyoNutri\Core\Database\SchemaInfo::class)) {
            \ClyoNutri\Core\Database\SchemaInfo::reset();
        }
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS food_equivalence_rules');
        $db->exec('DROP TABLE IF EXISTS food_search_selections');
        $db->exec('DROP TABLE IF EXISTS food_favorites');
        // As colunas novas de foods, food_household_measures, meal_plan_templates e meal_plans ficam: são opcionais
        // e não atrapalham a versão anterior.
    }

    private function columns(PDO $db, bool $mysql, string $table, array $columns): void
    {
        foreach ($columns as $name => $definition) {
            if (!$this->hasColumn($db, $mysql, $table, $name)) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            }
        }
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

    private function index(PDO $db, bool $mysql, string $table, string $name, string $columns, bool $unique = false): void
    {
        if ($mysql) {
            $st = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
            $st->execute([$table, $name]);
        } else {
            $st = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?");
            $st->execute([$name]);
        }
        if ((int)$st->fetchColumn() === 0) {
            $db->exec(sprintf('CREATE %sINDEX %s ON %s %s', $unique ? 'UNIQUE ' : '', $name, $table, $columns));
        }
    }
}
