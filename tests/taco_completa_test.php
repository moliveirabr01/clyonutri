<?php

declare(strict_types=1);

/**
 * TACO completa (4ª ed., NEPA/UNICAMP): migration 34 + TacoCatalogImporter num SQLite isolado.
 * Idempotência, vínculo dos alimentos de referência, isolamento das clínicas, Tr/NA/"*",
 * valores conferidos com o PDF oficial, busca e cálculo por 100 g.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Database\CatalogSeeder;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\TacoCatalogImporter;
use ClyoNutri\Domain\Nutrition\NutritionCalculator;

$passed = 0; $failed = 0;
function check(bool $c, string $m): void { global $passed, $failed; echo ($c ? '  [PASS] ' : '  [FAIL] ') . $m . "\n"; $c ? $passed++ : $failed++; }
$root = realpath(__DIR__ . '/..');

$csv = "$root/database/seeds/taco/taco_4a_edicao_tabela1.csv";
check(hash_file('sha256', $csv) === 'b6c3df78a344585f73c1ee341138b288c808ce5939cd72d976be3b15deb7b536', 'CSV da TACO íntegro (SHA-256 registrado no relatório)');

$file = sys_get_temp_dir() . '/taco_test_' . getmypid() . '.sqlite';
@unlink($file);
$db = new PDO('sqlite:' . $file);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
(new Migrator($db, "$root/database/migrations"))->run();
$cnt = fn(string $sql) => (int)$db->query($sql)->fetchColumn();

// Estado anterior típico: só os 12 de referência (seedTacoReference) + 1 alimento de clínica com nome igual.
(new ClyoNutri\Domain\Food\FoodCatalogService($db))->seedTacoReference();
$db->exec('PRAGMA foreign_keys = OFF');
$db->exec("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, is_custom)
           VALUES (999, 'Arroz branco cozido', 'Cereais', 'CUSTOM', 111, 1, 1, 1, 1, 1)");
$refIds = $db->query("SELECT id FROM foods WHERE clinic_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check(count($refIds) === 12, '12 alimentos de referência antes da importação');

$r1 = (new CatalogSeeder($db))->run();
$snap = fn() => $db->query("SELECT GROUP_CONCAT(id || '|' || IFNULL(taco_code,'') || '|' || name || '|' || energy_kcal || '|' || protein_g || '|' || lipid_g || '|' || carbohydrate_g || '|' || fiber_g || '|' || IFNULL(nutrient_flags,''), ';') FROM (SELECT * FROM foods ORDER BY id)")->fetchColumn();
$s1 = $snap();
$r2 = (new TacoCatalogImporter($db))->run();
$r3 = (new CatalogSeeder($db))->run();
check($snap() === $s1, 'Rodar de novo (importador e seeder) não muda nada');
check($r2['inserted'] === 0 && $r3 === 0, 'Segunda execução não insere nada');
check($cnt('SELECT COUNT(*) FROM foods WHERE taco_code IS NOT NULL') === 594, '594 alimentos com nº TACO (593 importáveis + leite desnatado de referência)');
check($cnt('SELECT COUNT(*) FROM foods WHERE clinic_id IS NULL') === 12 + 582, 'Globais: 12 de referência + 582 novos');
check($cnt("SELECT COUNT(DISTINCT taco_code) FROM foods WHERE taco_code IS NOT NULL") === 594, 'nº TACO único');
$kept = $db->query("SELECT id FROM foods WHERE id IN (" . implode(',', $refIds) . ") AND taco_code IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
check(count($kept) === 12, 'Os 12 de referência continuam com o mesmo id e foram vinculados ao nº TACO');
$c = $db->query("SELECT * FROM foods WHERE clinic_id = 999")->fetch();
check($c && $c['taco_code'] === null && (float)$c['energy_kcal'] === 111.0 && $c['source'] === 'CUSTOM', 'Alimento da clínica intocado');
$ref = $db->query("SELECT * FROM foods WHERE name = 'Aveia em flocos' AND clinic_id IS NULL")->fetch();
check((int)$ref['taco_code'] === 7 && (float)$ref['serving_size_g'] === 30.0 && $ref['serving_unit'] === 'colher de sopa', 'Referência mantém porção/unidade (Aveia, nº 7)');
$alface = $db->query("SELECT * FROM foods WHERE taco_code = 77")->fetch();
check($alface['name'] === 'Alface americana crua' && (float)$alface['protein_g'] === 0.6, 'Alface (nº 77) recebe a proteína oficial 0,6 g');
$leite = $db->query("SELECT * FROM foods WHERE taco_code = 457")->fetch();
check((float)$leite['energy_kcal'] === 35.0 && str_contains((string)$leite['nutrient_flags'], 'energy_kcal=*'), 'Leite desnatado UHT ("*" na TACO) mantém os valores e recebe a marcação');
check($cnt('SELECT COUNT(*) FROM foods WHERE taco_code IN (450, 458, 591)') === 0, 'Alimentos com energia "*" não são inseridos (450, 458, 591)');

// Valores conferidos com o PDF oficial (kcal, proteína, lipídios, carboidrato, fibra).
$pdf = [
    1 => [124, 2.6, 1.0, 25.8, 2.7], 15 => [324, 4.4, 12.7, 47.9, 0.7], 69 => [24, 0.7, 0.1, 6.1, 2.3],
    88 => [77, 0.6, 0.1, 18.4, 2.2], 259 => [884, 0, 100.0, 0, 0], 263 => [596, 0, 67.4, 0, 0],
    280 => [140, 24.0, 3.6, 1.2, 0], 387 => [246, 12.0, 15.6, 13.9, 0], 472 => [216, 0, 0, 0, 0],
    474 => [41, 0.6, 0, 3.3, 0], 516 => [0, 0, 0, 0, 0], 561 => [76, 4.8, 0.5, 13.6, 8.5], 589 => [643, 14.5, 63.5, 15.1, 7.9],
];
$bad = 0;
foreach ($pdf as $code => $v) {
    $f = $db->query("SELECT energy_kcal, protein_g, lipid_g, carbohydrate_g, fiber_g FROM foods WHERE taco_code = $code")->fetch(PDO::FETCH_NUM);
    foreach ($v as $i => $x) { if (abs((float)$f[$i] - $x) > 0.001) { $bad++; echo "    nº $code coluna $i: {$f[$i]} != $x\n"; } }
}
check($bad === 0, count($pdf) . ' alimentos conferidos com o PDF (incluindo Tr, NA, "*" e células em branco)');
$fl = fn($code) => (string)$db->query("SELECT nutrient_flags FROM foods WHERE taco_code = $code")->fetchColumn();
check($fl(263) === 'protein_g=Tr;fiber_g=NA' && $fl(280) === 'fiber_g=*' && $fl(387) === 'fiber_g=em_branco', 'Marcações Tr / NA / * / em branco preservadas');
check($db->query("SELECT name FROM foods WHERE taco_code = 474")->fetchColumn() === 'Cerveja, pilsen', 'Nota de rodapé removida do nome (Cerveja, pilsen)');

foreach (['arroz' => 10, 'feijão' => 10, 'banana' => 5] as $t => $min) {
    $n = $cnt("SELECT COUNT(*) FROM foods WHERE (is_custom = 0) AND name LIKE '%$t%'");
    check($n >= $min, "Busca '$t' encontra $n alimentos globais");
}
$calc = new NutritionCalculator($db);
$f = $db->query("SELECT * FROM foods WHERE taco_code = 561")->fetch();
$p = $calc->calculateForPortion($f, 150, 'g');
check($p['energy_kcal'] == 114.0 && $p['protein_g'] == 7.2 && $p['fiber_g'] == 12.75, 'Calculadora: 150 g de feijão carioca cozido = 114 kcal, 7,2 g proteína, 12,75 g fibra');

// Migration 34 idempotente
(new AddTacoCodeToFoods())->up($db);
check(true, 'Migration 34 pode rodar de novo sem erro');
@unlink($file);
echo "\nResultado: $passed aprovados, $failed falhas\n";
exit($failed === 0 ? 0 : 1);
