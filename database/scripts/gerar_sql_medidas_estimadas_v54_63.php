<?php
declare(strict_types=1);
// Gera o SQL da migration 94 (medidas caseiras estimadas) para MySQL 5.7, a partir da mesma inferência do código.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Uso: php database/scripts/gerar_sql_medidas_estimadas_v54_63.php <raiz> <banco.sqlite|nomes.txt>... (nomes.txt = nomes dos alimentos globais de produção, um por linha)
$root = $argv[1];
require $root . '/vendor/autoload.php';
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter as H;
use ClyoNutri\Domain\Food\FoodSearchIndex;
$foods = [];
foreach (array_slice($argv, 2) as $src) {
    if (str_ends_with($src, '.txt')) { foreach (file($src, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) $foods[] = $l; continue; }
    $pdo = new PDO('sqlite:' . $src);
    foreach ($pdo->query("SELECT DISTINCT name FROM foods WHERE clinic_id IS NULL AND COALESCE(category,'') <> 'TESTE' AND COALESCE(is_current,1)=1")->fetchAll(PDO::FETCH_COLUMN) as $n) $foods[] = $n;
}
$foods = array_values(array_unique($foods)); sort($foods, SORT_STRING);
$q = static fn(string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
$src = $q(H::ESTIMATED_SOURCE);
$n = 0; $rows = 0;
foreach ($foods as $name) {
    $m = H::inferCommonMeasuresForNormalizedName(' ' . FoodSearchIndex::normalize((string)$name) . ' ');
    if (!$m) continue;
    $parts = [];
    foreach ($m as [$mn, $g]) { $parts[] = 'SELECT ' . $q($mn) . ' AS m, ' . number_format((float)$g, 2, '.', '') . ' AS g'; $rows++; }
    echo "INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version)\n"
        . "  SELECT f.id, v.m, v.g, {$src}, 'v54.63' FROM foods f JOIN (" . implode(' UNION ALL ', $parts) . ") v\n"
        . "  WHERE f.clinic_id IS NULL AND f.name = " . $q((string)$name) . ";\n";
    $n++;
}
fwrite(STDERR, "alimentos: $n, medidas: $rows\n");
