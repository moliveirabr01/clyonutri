<?php
// Fixture do teste real da NI2-73 (busca no catálogo de alimentos). Dados fictícios.
// Só roda em banco isolado: SQLite em /tmp/ni2-73-* ou MySQL ni2_73_test_*.
// Base: fixture da NI2-91 (clínica 1 com Nutri Um dona, Nutri Equipe nutricionista e recepção; clínica 2).
// Acrescenta: TACO 4ª ed. completa (CSV oficial do repositório), alimentos da clínica 1 com medidas caseiras,
// um alimento exclusivo da clínica 2 e, no modo "bulk", uma base representativa de outras clínicas.
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_73_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-73-')) {
    throw new RuntimeException('Isolated test database required');
}
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    passthru('php ' . escapeshellarg(__DIR__ . '/ni2_91_fixture.php') . ' prepare > /dev/null', $rc);
    if ($rc !== 0) {
        throw new RuntimeException('base fixture');
    }
    $db = Connection::getInstance();
    $stats = (new \ClyoNutri\Domain\Food\TacoCatalogImporter($db))->run();
    $ins = $db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom)
        VALUES (?, ?, ?, 'CUSTOM', ?, ?, ?, ?, 0, ?, ?, 1)");
    $ins->execute([1, 'Pão de queijo da casa', 'Panificados', 335, 5.1, 34.2, 19.6, 40, 'unidade']);
    $paoId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent) VALUES (?, 'unidade pequena', 20), (?, 'unidade média', 40)")->execute([$paoId, $paoId]);
    $ins->execute([2, 'Bolo exclusivo da clínica dois', 'Panificados', 380, 5, 50, 18, 100, 'g']);
    echo json_encode(['ok' => true, 'taco' => $stats, 'pao_id' => $paoId]);
} elseif ($mode === 'bulk') {
    // bulk <clínicas> <alimentos por clínica> <alimentos extras da clínica 1>: base representativa (nomes da TACO + variações).
    $db = Connection::getInstance();
    [$clinics, $per, $own] = [(int)$argv[2], (int)$argv[3], (int)$argv[4]];
    $names = $db->query("SELECT name, category FROM foods WHERE clinic_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    $suffix = ['caseiro', 'da feira', 'orgânico', 'light', 'integral', 'temperado', 'sem sal', 'congelado', 'artesanal', 'da casa'];
    $db->beginTransaction();
    $c = $db->prepare("INSERT INTO clinics (id, uuid, name) VALUES (?, ?, ?)");
    $f = $db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom)
        VALUES (?, ?, ?, 'CUSTOM', ?, 3, 20, 5, 1, 100, 'g', 1)");
    $n = 0;
    for ($k = 0; $k < $clinics; $k++) {
        $cid = 1000 + $k;
        $c->execute([$cid, 'n73-c' . $cid, 'Clínica sintética ' . $cid]);
        for ($j = 0; $j < $per; $j++) {
            [$name, $cat] = $names[($k * 7 + $j * 13) % count($names)];
            $f->execute([$cid, $name . ' ' . $suffix[($k + $j) % 10], $cat, 50 + ($j % 300)]);
            $n++;
        }
    }
    for ($j = 0; $j < $own; $j++) {
        [$name, $cat] = $names[($j * 11) % count($names)];
        $f->execute([1, $name . ' ' . $suffix[$j % 10] . ' ' . $j, $cat, 80 + $j % 200]);
        $n++;
    }
    $db->commit();
    echo json_encode(['ok' => true, 'inserted' => $n, 'foods_total' => (int)$db->query('SELECT COUNT(*) FROM foods')->fetchColumn()]);
} elseif ($mode === 'raw-insert') {
    // Simula o SQL manual da TACO: linha nova sem search_text.
    $db = Connection::getInstance();
    $db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom)
        VALUES (NULL, ?, 'Frutas e derivados', 'TACO', 52, 0.3, 13.8, 0.2, 2.4, 100, 'g', 0)")->execute([$argv[2]]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
} elseif ($mode === 'raw-bulk') {
    // Simula uma importação manual grande: N linhas novas sem search_text, numa transação.
    $db = Connection::getInstance();
    $n = (int)($argv[2] ?? 5000);
    $db->beginTransaction();
    $st = $db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom)
        VALUES (NULL, ?, 'Outros', 'TACO', 50, 1, 1, 1, 0, 100, 'g', 0)");
    for ($i = 0; $i < $n; $i++) {
        $st->execute(["Alimento importado manualmente {$i}, cru"]);
    }
    $db->commit();
    echo json_encode(['ok' => true, 'inserted' => $n]);
} elseif ($mode === 'recipe') {
    // Última receita da clínica 1 com os ingredientes (nome do alimento), para conferir o que foi salvo.
    $db = Connection::getInstance();
    $r = $db->query("SELECT id, title FROM recipes WHERE clinic_id = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $ing = $r ? $db->query('SELECT f.name, ri.amount, ri.measure_unit FROM recipe_ingredients ri JOIN foods f ON f.id = ri.food_id WHERE ri.recipe_id = ' . (int)$r['id'] . ' ORDER BY ri.id')->fetchAll(PDO::FETCH_ASSOC) : [];
    echo json_encode(['recipe' => $r, 'ingredients' => $ing]);
} elseif ($mode === 'draft-plan') {
    // Plano em rascunho da Bruno (paciente 2) com o Nutri Um (dono) e uma refeição, para abrir o seletor de alimentos.
    $db = Connection::getInstance();
    $db->exec("INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status) VALUES (1, 2, 1, 'Plano fictício NI2-73', 'draft')");
    $plan = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO meal_plan_meals (meal_plan_id, name, time_of_day, ordering) VALUES (?, 'Almoço', '12:00', 1)")->execute([$plan]);
    echo json_encode(['ok' => true, 'plan_id' => $plan]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*(SELECT|EXPLAIN)\s/i', $q)) {
        throw new RuntimeException('Somente SELECT');
    }
    echo json_encode(Connection::getInstance()->query($q)->fetchAll(PDO::FETCH_ASSOC));
} else {
    throw new RuntimeException('Unknown fixture mode');
}
