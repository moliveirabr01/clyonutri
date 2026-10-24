<?php

declare(strict_types=1);

// NI2-74 — conversão de porções e cálculo nutricional: regras, confirmação do peso estimado, precisão e
// arredondamento, nutrientes sem análise e documentação igual ao código. Banco SQLite em memória (só as tabelas usadas).
//   php tests/ni2_74_portion_conversion_test.php

require __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\Nutrition\NutritionCalculator;
use ClyoNutri\Domain\Nutrition\QuantityFormatter;
use ClyoNutri\Domain\Nutrition\UnitConfirmationRequiredException;

$pass = 0; $fail = 0;
function ck(string $name, bool $ok, mixed $detail = null): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === null ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
}
function err(callable $fn): ?Throwable
{
    try { $fn(); return null; } catch (Throwable $e) { return $e; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE foods (id INTEGER PRIMARY KEY, name TEXT, serving_unit TEXT, serving_size_g REAL, nutrient_flags TEXT,
    energy_kcal REAL, protein_g REAL, carbohydrate_g REAL, lipid_g REAL, fiber_g REAL)');
$db->exec('CREATE TABLE food_household_measures (id INTEGER PRIMARY KEY, food_id INTEGER, measure_name TEXT, grams_equivalent REAL)');
$db->exec("INSERT INTO foods VALUES (1, 'Arroz, integral, cozido', 'g', 100, NULL, 124, 2.6, 25.8, 1.0, 2.7)");
$db->exec("INSERT INTO foods VALUES (2, 'Pão de queijo da casa', 'unidade', 40, NULL, 335, 5.1, 34.2, 19.6, 0)");
$db->exec("INSERT INTO foods VALUES (3, 'Leite, condensado', 'g', 100, 'fiber_g=NA', 313, 7.7, 57.0, 6.7, 0)");
$db->exec("INSERT INTO foods VALUES (4, 'Óleo, de soja', 'g', 100, 'protein_g=NA;carbohydrate_g=NA;fiber_g=NA;lipid_g=Tr', 884, 0, 0, 100, 0)");
$db->exec("INSERT INTO food_household_measures (food_id, measure_name, grams_equivalent) VALUES (1, 'Colher de sopa', 25), (2, 'unidade pequena', 20)");
$food = static fn(int $id) => $db->query("SELECT * FROM foods WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
$conv = new HouseholdMeasureConverter($db);
$calc = new NutritionCalculator($db);

echo "== Regras de conversão (ordem documentada)\n";
$c = $conv->convertDetailed(250, 'g', 1, $food(1));
ck('Massa é exata: 250 g = 250 g', $c['grams'] === 250.0 && !$c['estimated'] && $c['source'] === 'mass', $c);
ck('kg e mg: 0,2 kg = 200 g; 500 mg = 0,5 g', $conv->convertToGrams(0.2, 'kg') === 200.0 && $conv->convertToGrams(500, 'mg') === 0.5);
$c = $conv->convertDetailed(2, 'colher de sopa', 1, $food(1));
ck('Medida cadastrada do alimento vence a tabela: 2 colheres de sopa de arroz = 50 g (não 30 g), exata', $c['grams'] === 50.0 && !$c['estimated'] && $c['source'] === 'food_measure', $c);
$c = $conv->convertDetailed(1, 'unidade', 2, $food(2));
ck('Porção do próprio alimento: 1 unidade de pão de queijo = 40 g (antes: 100 g genéricos), exata', $c['grams'] === 40.0 && !$c['estimated'] && $c['source'] === 'food_serving', $c);
$c = $conv->convertDetailed(3, 'Unidades Pequenas', 2, $food(2));
ck('Escrita livre no plural/maiúsculas casa com a medida cadastrada: 3 "Unidades Pequenas" = 60 g', $c['grams'] === 60.0 && $c['source'] === 'food_measure', $c);
foreach (['Colheres de Sopa', 'xicara de chá', 'XÍCARA', 'xícaras', 'colher sopa'] as $u) {
    $e = err(fn() => $conv->convertDetailed(1, $u, 3, $food(3)));
    ck("\"{$u}\" é reconhecida (pede confirmação, não diz \"não reconhecida\")", $e instanceof UnitConfirmationRequiredException, $e?->getMessage());
}

echo "== Incompatibilidade: peso genérico só com confirmação\n";
$e = err(fn() => $calc->calculateForPortion($food(1), 1, 'xícara', 1));
ck('Sem confirmação: erro explícito (UnitConfirmationRequiredException) com alimento, unidade, peso padrão e medidas exatas',
    $e instanceof UnitConfirmationRequiredException && str_contains($e->getMessage(), '«Arroz, integral, cozido»') && str_contains($e->getMessage(), '«xícara»')
    && str_contains($e->getMessage(), '150 g') && str_contains($e->getMessage(), '1 Colher de sopa = 25 g') && str_contains($e->getMessage(), 'Confirmo o peso estimado'), $e?->getMessage());
ck('A exceção diz o peso que seria usado (150 g)', $e instanceof UnitConfirmationRequiredException && $e->conversion['grams'] === 150.0);
$r = $calc->calculateForPortion($food(1), 1, 'xícara', 1, true);
ck('Com confirmação: 1 xícara = 150 g, marcada como estimada, 186 kcal', $r['weight_grams'] === 150.0 && $r['weight_is_estimated'] === true && $r['energy_kcal'] === 186.0, $r);
$e = err(fn() => $calc->calculateForPortion($food(4), 15, 'ml', 4));
ck('ml deixa de ser "exato": óleo em ml sem medida cadastrada pede confirmação e cita a densidade', $e instanceof UnitConfirmationRequiredException && str_contains($e->getMessage(), 'densidade'), $e?->getMessage());
$e = err(fn() => $calc->calculateForPortion($food(1), 100, 'ml', 1));
ck('100 ml de arroz cozido não vira 100 g sem confirmação', $e instanceof UnitConfirmationRequiredException);
$e = err(fn() => $calc->calculateForPortion($food(1), 1, 'pitada', 1, true));
ck('Unidade desconhecida continua erro mesmo com confirmação ("não reconhecida")', $e instanceof InvalidArgumentException && !($e instanceof UnitConfirmationRequiredException) && str_contains($e->getMessage(), 'não reconhecida'), $e?->getMessage());
foreach ([0, -1, NAN, INF] as $bad) {
    $e = err(fn() => $calc->calculateForPortion($food(1), $bad, 'g', 1));
    ck('Quantidade inválida (' . var_export($bad, true) . ') → erro', $e instanceof InvalidArgumentException);
}
$e = err(fn() => $conv->convertDetailed(1, '   ', 1, $food(1)));
ck('Unidade vazia → erro explícito', $e instanceof InvalidArgumentException && str_contains($e->getMessage(), 'Informe a unidade'));

echo "== Precisão e arredondamento\n";
$f = ['energy_kcal' => 1.005, 'protein_g' => 2.675, 'carbohydrate_g' => 0.125, 'lipid_g' => 1.0049, 'fiber_g' => 0];
$r = $calc->calculateForPortion($f, 100, 'g');
ck('Meio para cima nas bordas de ponto flutuante: 1,005 → 1,01; 2,675 → 2,68; 0,125 → 0,13; 1,0049 → 1,0',
    $r['energy_kcal'] === 1.01 && $r['protein_g'] === 2.68 && $r['carbohydrate_g'] === 0.13 && $r['lipid_g'] === 1.0, $r);
$r = $calc->calculateForPortion($food(1), 33.33, 'g', 1);
ck('33,33 g de arroz: 41,33 kcal; 0,87 g proteína; 0,9 g fibra (2 casas)', $r['energy_kcal'] === 41.33 && $r['protein_g'] === 0.87 && $r['fiber_g'] === 0.9, $r);
$runs = array_map(fn() => json_encode($calc->calculateForPortion($food(1), 0.5, 'xícara', 1, true)), range(1, 5));
ck('Determinístico: 5 execuções iguais', count(array_unique($runs)) === 1);
$tot = $calc->calculateMealTotals([
    ['food' => ['energy_kcal' => 0.5], 'amount' => 1, 'measure_unit' => 'g'],
    ['food' => ['energy_kcal' => 0.5], 'amount' => 1, 'measure_unit' => 'g'],
    ['food' => ['energy_kcal' => 0.5], 'amount' => 1, 'measure_unit' => 'g'],
]);
ck('Total = soma dos itens arredondados (3 × 0,01 = 0,03; documentado)', $tot['energy_kcal'] === 0.03, $tot);
$items = [['food' => $food(1), 'food_id' => 1, 'amount' => 75, 'measure_unit' => 'g'], ['food' => $food(2), 'food_id' => 2, 'amount' => 2, 'measure_unit' => 'unidade'], ['food' => $food(3), 'food_id' => 3, 'amount' => 30, 'measure_unit' => 'g']];
$a = $calc->calculateMealTotals($items); $b = $calc->calculateMealTotals(array_reverse($items));
ck('Ordem dos itens não muda o total', json_encode($a) === json_encode($b), [$a, $b]);
ck('Quantidade guardada com 2 casas antes do cálculo: 33,333 → 33,33; 0,125 → 0,13; "abc" → inválida',
    MealPlanService::normalizeAmount('33.333') === 33.33 && MealPlanService::normalizeAmount(0.125) === 0.13 && MealPlanService::normalizeAmount('abc') < 0);
ck('Tela mostra a quantidade como digitada: 0,5 · 1,5 · 2 · 33,33 · 0,13',
    QuantityFormatter::amount(0.5) === '0,5' && QuantityFormatter::amount('1.50') === '1,5' && QuantityFormatter::amount(2) === '2' && QuantityFormatter::amount(33.33) === '33,33' && QuantityFormatter::amount(0.125) === '0,13');

echo "== Nutrientes sem análise (NA, *, em branco)\n";
$r = $calc->calculateForPortion($food(3), 30, 'g', 3);
ck('Leite condensado: fibra NA entra como 0 e vem em unknown_nutrients', $r['fiber_g'] === 0.0 && $r['unknown_nutrients'] === ['fiber_g'], $r);
$r = $calc->calculateForPortion($food(4), 10, 'g', 4);
ck('Óleo: NA de proteína/carboidrato/fibra listados; "Tr" (traços) de lipídio não', $r['unknown_nutrients'] === ['protein_g', 'carbohydrate_g', 'fiber_g'], $r['unknown_nutrients']);
ck('Total do conjunto junta os nutrientes incompletos', $calc->calculateMealTotals($items)['unknown_nutrients'] === ['fiber_g']);

echo "== Documentação igual ao código\n";
$doc = (string)file_get_contents(__DIR__ . '/../docs/clinical/CONVERSAO_DE_PORCOES_NI2-74.md');
preg_match('/<!-- tabela-unidades:inicio -->(.*?)<!-- tabela-unidades:fim -->/s', $doc, $m);
$docRows = [];
foreach (explode("\n", trim($m[1] ?? '')) as $line) {
    $cells = array_map('trim', explode('|', trim($line, " |")));
    if (count($cells) >= 2 && is_numeric(str_replace(['.', ','], ['', '.'], $cells[1]))) {
        $docRows[$cells[0]] = (float)str_replace(['.', ','], ['', '.'], $cells[1]);
    }
}
$codeRows = [];
foreach (HouseholdMeasureConverter::standardTable() as $row) {
    $codeRows[$row['unit']] = $row['grams'];
}
ck('Tabela de unidades do documento = tabela do código (' . count($codeRows) . ' unidades)', $docRows == $codeRows && count($codeRows) >= 15, ['doc' => $docRows, 'code' => $codeRows]);
foreach (['Precisão e arredondamento', 'meio para cima', 'Confirmo o peso estimado', 'densidade da água', 'Nutrientes sem análise', 'sódio'] as $needle) {
    ck("Documento explica: {$needle}", stripos($doc, $needle) !== false);
}

echo "\nNI2-74 (conversão): {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
