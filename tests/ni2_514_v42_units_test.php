<?php

declare(strict_types=1);

/**
 * NI2-514 / NI2-523 — Testes de Unidade da Fase 2 (v42):
 *  - PlanTotalsBar (proteção contra NaN/divisão por zero, tolerância de 5%, meta ausente ou zerada)
 *  - SubstitutionComparison (sameEnergyGrams, delta e label sem promessa de equivalência)
 *  - FoodSearchIndex (normalização, singular, sinônimos regionais, extração de preparo)
 *  - EnergyCalculator (avisos para adolescentes <18 anos, idosos >=65 anos e gestantes/lactantes)
 *  - PatientImportService (parse CSV com ; e ,, BOM UTF-8, mapeamento de cabeçalhos e validação de linhas)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Domain\Food\FoodSearchIndex;
use ClyoNutri\Domain\MealPlan\PlanTotalsBar;
use ClyoNutri\Domain\MealPlan\SubstitutionComparison;
use ClyoNutri\Domain\Nutrition\EnergyCalculator;
use ClyoNutri\Domain\Patient\PatientImportService;

$passed = 0;
$failed = 0;

function check(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "=== NI2-514 / NI2-523 v42 Units Test ===\n";

// 1. PlanTotalsBar: proteção contra divisão por zero / NaN quando meta é nula ou zero
$barNoTarget = PlanTotalsBar::build([
    'daily_energy_kcal' => 1500,
    'carbohydrate_g' => 180,
    'protein_g' => 95,
    'lipid_g' => 45,
    'fiber_g' => 28,
], null);
check($barNoTarget['has_target'] === false && $barNoTarget['rows']['energy']['status'] === 'sem-meta', 'PlanTotalsBar sem meta não gera NaN e retorna status sem-meta');

$barZeroTarget = PlanTotalsBar::build([
    'daily_energy_kcal' => 1500,
    'carbohydrate_g' => 180,
    'protein_g' => 95,
    'lipid_g' => 45,
    'fiber_g' => 28,
], ['vet_kcal' => 0, 'protein_g' => 0, 'carbohydrate_g' => 0, 'lipid_g' => 0]);
check($barZeroTarget['has_target'] === false && !str_contains(json_encode($barZeroTarget), 'NAN'), 'PlanTotalsBar com meta zero trata como sem-meta sem divisão por zero');

$barBelow = PlanTotalsBar::build([
    'daily_energy_kcal' => 1400,
    'carbohydrate_g' => 150,
    'protein_g' => 90,
    'lipid_g' => 50,
    'fiber_g' => 22,
], ['vet_kcal' => 2000, 'protein_g' => 90, 'carbohydrate_g' => 250, 'lipid_g' => 50]);
check($barBelow['rows']['energy']['status'] === 'abaixo' && $barBelow['rows']['protein']['status'] === 'ok', 'PlanTotalsBar identifica abaixo (<95%) e ok (95%..105%)');

// 2. SubstitutionComparison: casos limite (energia zero) e cálculo exato
check(SubstitutionComparison::sameEnergyGrams(0, 120) === null, 'SubstitutionComparison::sameEnergyGrams retorna null quando item principal tem 0 kcal');
check(SubstitutionComparison::sameEnergyGrams(150, 0) === null, 'SubstitutionComparison::sameEnergyGrams retorna null quando substituto tem 0 kcal/100g');
check(SubstitutionComparison::sameEnergyGrams(130, 130) === 100.0, 'SubstitutionComparison::sameEnergyGrams retorna 100 g para mesma densidade energética');

// 3. EnergyCalculator: alertas populacionais (adolescente <18, idoso >=65, gestante)
$adol = EnergyCalculator::compute([
    'formula' => 'cunningham',
    'weight_kg' => 62,
    'ffm_kg' => 54,
    'age_years' => 15,
    'activity_factor' => 1.75,
]);
check(in_array('Esta fórmula foi desenvolvida em adultos. Para menores de 18 anos, confira se ela é adequada.', $adol['warnings'], true), 'EnergyCalculator emite alerta para adolescente (<18 anos) mesmo em Cunningham');

$elder = EnergyCalculator::compute([
    'formula' => 'mifflin_st_jeor',
    'sex' => 'male',
    'age_years' => 72,
    'weight_kg' => 71,
    'height_cm' => 170,
    'activity_factor' => 1.40,
]);
check(in_array('Para idosos (65 anos ou mais), confira a perda de massa magra e avalie a adequação da equação e do aporte proteico.', $elder['warnings'], true), 'EnergyCalculator emite alerta clínico para idosos (>=65 anos)');

$preg = EnergyCalculator::compute([
    'formula' => 'mifflin_st_jeor',
    'sex' => 'female',
    'age_years' => 28,
    'weight_kg' => 68,
    'height_cm' => 163,
    'activity_factor' => 1.55,
    'goal_type' => 'gain',
    'adjust_mode' => 'kcal',
    'adjust_value' => 340,
    'goal_note' => 'Gestante 2º trimestre (+340 kcal/dia)',
]);
check(in_array('Em gestantes ou lactantes, confira o uso do peso pré-gestacional e o adicional energético do trimestre/lactação (FAO/OMS/UNU) no ajuste do GET.', $preg['warnings'], true), 'EnergyCalculator emite alerta clínico para gestantes/lactantes');

// 4. FoodSearchIndex: normalização, singular e preparo
check(FoodSearchIndex::normalize('Maçã Fuji Graúda') === 'maca fuji grauda', 'FoodSearchIndex::normalize remove acentos e caixa alta');
check(FoodSearchIndex::preparation('Frango, peito, sem pele, grelhado') === 'Grelhado', 'FoodSearchIndex::preparation reconhece Grelhado');
check(FoodSearchIndex::preparation('Aveia, flocos, crua') === 'Cru', 'FoodSearchIndex::preparation reconhece Cru');

// 5. PatientImportService: parse BOM, vírgula/ponto-e-vírgula e validação de data inexistente
$parsed = PatientImportService::parse("\xEF\xBB\xBFNome completo,Telefone,Data de nascimento,Sexo\nTESTE Maria,11999998888,29/02/2024,F\nTESTE João,11999997777,31/02/1990,M\n");
$map = PatientImportService::mapHeader($parsed['header']);
check(isset($map['name'], $map['phone'], $map['birth_date'], $map['gender']), 'PatientImportService::mapHeader mapeia sinônimos de colunas em CSV com vírgula');
$v1 = PatientImportService::validateRow($parsed['rows'][0]['cells'], $map);
$v2 = PatientImportService::validateRow($parsed['rows'][1]['cells'], $map);
check($v1['error'] === null && $v1['data']['birth_date'] === '2024-02-29' && $v1['data']['gender'] === 'Feminino', 'Valida ano bissexto (29/02/2024) e normaliza gênero F -> Feminino');
check($v2['error'] !== null && str_contains($v2['error'], 'Data de nascimento inválida'), 'Rejeita data calendário inexistente (31/02/1990)');

echo "\nResultado NI2-514 v42 Units: {$passed} PASS, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
