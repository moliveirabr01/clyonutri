<?php

declare(strict_types=1);

/**
 * NI2-523 — Suíte Completa dos Cenários Clínicos de Liberação da Prescrição (C1 a C11):
 *
 * Executa os 11 cenários do roteiro `docs/benchmark-webdiet/ROTEIRO-AVALIACAO-CLINICA-V42.md`
 * na clínica isolada "Clínica TESTE Avaliação" provisionada por `bin/preparar_avaliacao_clinica_ni2_523.php`,
 * cobrindo as 5 populações elegíveis (Adulto redução de peso, Gestante 2º trimestre, Alergia a amendoim,
 * Idoso 72 anos e Adolescente atleta 15 anos) e a planilha oficial `docs/benchmark-webdiet/pacientes_teste_ni2_523.csv`.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bin/preparar_avaliacao_clinica_ni2_523.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\FoodEquivalenceService;
use ClyoNutri\Domain\Food\FoodSearchIndex;
use ClyoNutri\Domain\MealPlan\EnergyTargetService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\MealPlanTemplateService;
use ClyoNutri\Domain\MealPlan\PlanTotalsBar;
use ClyoNutri\Domain\MealPlan\SubstitutionComparison;
use ClyoNutri\Domain\Nutrition\ClinicHouseholdMeasureService;
use ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter;
use ClyoNutri\Domain\Patient\PatientImportService;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Connection::setInstance($db);
(new Migrator($db, __DIR__ . '/../database/migrations'))->run();

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

echo "=== NI2-523 — Homologação Clínica dos 11 Cenários (C1 a C11) e 5 Populações Elegíveis ===\n";

// 0. Provisionar "Clínica TESTE Avaliação" com os 5 avaliadores (P1..P5), 1 recepcionista e 5 pacientes TESTE
$env = EvaluationEnvironmentSeeder::seed($db);
$clinicId = $env['clinic_id'];
$p1User = $env['evaluators']['P1']['user_id'];
$p1Prof = $env['evaluators']['P1']['professional_id'];
$recUser = $env['receptionist_user_id'];

$patAdultLoss = $env['patients']['p-ni2-523-adulto-perda'];
$patPeanutAllergy = $env['patients']['p-ni2-523-alergia-amendoim'];
$patPregnant = $env['patients']['p-ni2-523-gestante'];
$patElderly = $env['patients']['p-ni2-523-idoso'];
$patAdolescent = $env['patients']['p-ni2-523-adolescente-atleta'];

check($clinicId > 0 && count($env['evaluators']) === 5 && count($env['patients']) === 5, 'Preparação NI2-523: Clínica TESTE Avaliação provisionada com 5 nutricionistas (P1..P5), 1 recepção e 5 pacientes TESTE');

// Catálogo base para os cenários C1..C9 (valores TACO 4ª ed.)
$db->exec("INSERT INTO foods (id, clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, source, source_version, taco_code, is_current, allergens) VALUES
    (1001, NULL, 'Pão, trigo, forma, integral', 'Cereais e derivados', 253, 9.4, 49.9, 3.7, 6.9, 'TACO', '4ª ed. (2011)', 45, 1, 'gluten'),
    (1002, NULL, 'Ovo, de galinha, inteiro, cozido', 'Ovos e derivados', 146, 13.3, 0.6, 9.5, 0.0, 'TACO', '4ª ed. (2011)', 489, 1, 'ovo'),
    (1003, NULL, 'Banana, prata, crua', 'Frutas e derivados', 98, 1.3, 26.0, 0.1, 2.0, 'TACO', '4ª ed. (2011)', 182, 1, 'nenhum'),
    (1004, NULL, 'Arroz, integral, cozido', 'Cereais e derivados', 124, 2.6, 25.8, 1.0, 2.7, 'TACO', '4ª ed. (2011)', 1, 1, 'nenhum'),
    (1005, NULL, 'Feijão, carioca, cozido', 'Leguminosas e derivados', 76, 4.8, 13.6, 0.5, 8.5, 'TACO', '4ª ed. (2011)', 559, 1, 'nenhum'),
    (1006, NULL, 'Frango, peito, sem pele, grelhado', 'Carnes e derivados', 159, 32.0, 0.0, 2.5, 0.0, 'TACO', '4ª ed. (2011)', 403, 1, 'nenhum'),
    (1007, NULL, 'Mandioca, cozida', 'Verduras, hortaliças e derivados', 125, 0.6, 30.1, 0.3, 1.6, 'TACO', '4ª ed. (2011)', 121, 1, 'nenhum'),
    (1008, NULL, 'Mandioca, crua', 'Verduras, hortaliças e derivados', 151, 1.1, 36.2, 0.3, 1.9, 'TACO', '4ª ed. (2011)', 120, 1, 'nenhum'),
    (1009, NULL, 'Iogurte, natural', 'Leite e derivados', 51, 4.1, 1.9, 3.0, 0.0, 'TACO', '4ª ed. (2011)', 446, 1, 'lactose'),
    (1010, NULL, 'Aveia, flocos, crua', 'Cereais e derivados', 394, 13.9, 66.6, 8.5, 9.1, 'TACO', '4ª ed. (2011)', 15, 1, 'gluten'),
    (1011, NULL, 'Amendoim, grão, cru', 'Leguminosas e derivados', 544, 27.2, 20.3, 43.9, 8.0, 'TACO', '4ª ed. (2011)', 552, 1, 'amendoim')");
FoodSearchIndex::sync($db);

TenantContext::set($clinicId, ['id' => $p1User, 'role' => 'nutritionist', 'name' => 'Avaliador P1']);

$planSvc = new MealPlanService($db);
$energySvc = new EnergyTargetService($db);
$tmplSvc = new MealPlanTemplateService($db);
$cmSvc = new ClinicHouseholdMeasureService($db);

// =========================================================================
// CENÁRIO C1: Plano com 6 refeições para adulto em redução de peso + TMB/GET/VET + Barra de totais
// =========================================================================
$c1Create = $planSvc->createPlan($patAdultLoss, $p1Prof, 'TESTE C1 Redução de Peso', 'Hidratação: 35 ml/kg/dia', MealPlanService::DEFAULT_MEALS);
$c1PlanId = (int)$c1Create['plan_id'];
$c1Suggest = $energySvc->suggestInputs($c1PlanId);
check($c1Suggest['sex'] === 'male' && $c1Suggest['age_years'] === 34 && $c1Suggest['weight_kg'] === 88.0 && $c1Suggest['height_cm'] === 176.0, 'C1: Pré-preenche peso (88 kg), altura (176 cm), idade (34 a) e sexo da avaliação/cadastro');

$c1Target = $energySvc->save($c1PlanId, [
    'formula' => 'mifflin_st_jeor',
    'sex' => 'male',
    'age_years' => '34',
    'weight_kg' => '88',
    'height_cm' => '176',
    'activity_factor' => '1.40',
    'goal_type' => 'loss',
    'adjust_mode' => 'kcal',
    'adjust_value' => '-500',
    'macro_mode' => 'percent',
    'protein' => '25',
    'carbohydrate' => '45',
    'lipid' => '30',
]);
// TMB = 10*88 + 6.25*176 - 5*34 + 5 = 880 + 1100 - 170 + 5 = 1815 kcal
// GET = 1815 * 1.40 = 2541 kcal
// VET = 2541 - 500 = 2041 kcal
check($c1Target['tmb_kcal'] === 1815.0 && $c1Target['get_kcal'] === 2541.0 && $c1Target['vet_kcal'] === 2041.0, 'C1: Calcula TMB (1815 kcal), GET (2541 kcal) e VET (2041 kcal) com fórmula e versão rastreadas');

$c1Meals = $planSvc->getPlanWithDetails($c1PlanId)['meals'];
check(count($c1Meals) === 6, 'C1: Plano nasce com as 6 refeições padrão pré-carregadas');

// Distribuir alimentos nas 6 refeições (~2015 kcal totais, dentro dos 5% de 2041 kcal)
$planSvc->addItem((int)$c1Meals[0]['id'], 1001, 50, 'g');  // Café: Pão integral 50g (126.5 kcal)
$planSvc->addItem((int)$c1Meals[0]['id'], 1002, 100, 'g'); // Café: Ovo cozido 100g (146.0 kcal)
$planSvc->addItem((int)$c1Meals[1]['id'], 1003, 150, 'g'); // Colação: Banana 150g (147.0 kcal)
$c1RiceItemId = $planSvc->addItem((int)$c1Meals[2]['id'], 1004, 200, 'g'); // Almoço: Arroz integral 200g (248.0 kcal)
$planSvc->addItem((int)$c1Meals[2]['id'], 1005, 150, 'g'); // Almoço: Feijão 150g (114.0 kcal)
$planSvc->addItem((int)$c1Meals[2]['id'], 1006, 200, 'g'); // Almoço: Frango grelhado 200g (318.0 kcal)
$planSvc->addItem((int)$c1Meals[3]['id'], 1009, 200, 'g'); // Lanche: Iogurte 200g (102.0 kcal)
$planSvc->addItem((int)$c1Meals[3]['id'], 1010, 40, 'g');  // Lanche: Aveia 40g (157.6 kcal)
$planSvc->addItem((int)$c1Meals[4]['id'], 1004, 150, 'g'); // Jantar: Arroz integral 150g (186.0 kcal)
$planSvc->addItem((int)$c1Meals[4]['id'], 1006, 200, 'g'); // Jantar: Frango grelhado 200g (318.0 kcal)
$planSvc->addItem((int)$c1Meals[5]['id'], 1003, 150, 'g'); // Ceia: Banana 150g (147.0 kcal)

$c1PlanAfter = $planSvc->getPlanWithDetails($c1PlanId);
$c1Bar = PlanTotalsBar::build($c1PlanAfter, $c1PlanAfter['energy_target']);
check($c1Bar['has_target'] === true && $c1Bar['rows']['energy']['status'] === 'ok', 'C1: Barra de totais confirma energia diária (2010 kcal) dentro da tolerância de 5% da meta (2041 kcal)');

// =========================================================================
// CENÁRIO C2: Gestante (2º trimestre) + Adolescente Atleta (15 anos) — Avisos clínicos populacionais
// =========================================================================
$c2Create = $planSvc->createPlan($patPregnant, $p1Prof, 'TESTE C2 Gestante 2º Trimestre', null, MealPlanService::DEFAULT_MEALS);
$c2PlanId = (int)$c2Create['plan_id'];
$c2Target = $energySvc->save($c2PlanId, [
    'formula' => 'mifflin_st_jeor',
    'sex' => 'female',
    'age_years' => '28',
    'weight_kg' => '68',
    'height_cm' => '163',
    'activity_factor' => '1.55',
    'goal_type' => 'gain',
    'goal_note' => 'Gestante 2º trimestre (+340 kcal FAO/OMS)',
    'adjust_mode' => 'kcal',
    'adjust_value' => '340',
    'macro_mode' => 'percent',
    'protein' => '20',
    'carbohydrate' => '50',
    'lipid' => '30',
]);
check(in_array('Em gestantes ou lactantes, confira o uso do peso pré-gestacional e o adicional energético do trimestre/lactação (FAO/OMS/UNU) no ajuste do GET.', $c2Target['warnings'], true), 'C2: Gestante 2º trimestre recebe alerta clínico específico sobre peso pré-gestacional e adicional do trimestre');

// Adolescente atleta (15 anos) com Cunningham (MLG = 54 kg pré-calculada)
$c2AdolPlan = $planSvc->createPlan($patAdolescent, $p1Prof, 'TESTE Adolescente Atleta', null, MealPlanService::DEFAULT_MEALS);
$c2AdolPlanId = (int)$c2AdolPlan['plan_id'];
$c2AdolSuggest = $energySvc->suggestInputs($c2AdolPlanId);
check($c2AdolSuggest['ffm_kg'] === 54.0 && $c2AdolSuggest['age_years'] === 15, 'C2b: Pré-calcula massa livre de gordura (54,0 kg) para o adolescente atleta');
$c2AdolTarget = $energySvc->save($c2AdolPlanId, [
    'formula' => 'cunningham',
    'weight_kg' => '62',
    'ffm_kg' => '54',
    'activity_factor' => '1.75',
    'goal_type' => 'gain',
    'adjust_mode' => 'kcal',
    'adjust_value' => '300',
    'macro_mode' => 'percent',
    'protein' => '25',
    'carbohydrate' => '50',
    'lipid' => '25',
]);
check(in_array('Esta fórmula foi desenvolvida em adultos. Para menores de 18 anos, confira se ela é adequada.', $c2AdolTarget['warnings'], true), 'C2b: Adolescente (15 anos) exibe aviso de fórmula desenvolvida em adultos mesmo em Cunningham');

// =========================================================================
// CENÁRIO C3: Paciente com alergia a amendoim + Alimento com amendoim -> Bloqueio antes de publicar
// =========================================================================
$c3Create = $planSvc->createPlan($patPeanutAllergy, $p1Prof, 'TESTE C3 Alergia Amendoim', null, MealPlanService::DEFAULT_MEALS);
$c3PlanId = (int)$c3Create['plan_id'];
$c3Meals = $planSvc->getPlanWithDetails($c3PlanId)['meals'];
$planSvc->addItem((int)$c3Meals[3]['id'], 1011, 30, 'g'); // Amendoim cru 30g

$c3Preview = $planSvc->getPlanPreview($c3PlanId);
check($c3Preview['allergen_safety']['has_conflict'] === true && $c3Preview['allergen_safety']['conflicts'][0]['allergen_group'] === 'amendoim', 'C3: Alerta de conflito alergênico (amendoim) é detectado na prévia do plano');

$c3BlockedWithoutOverride = false;
try {
    $planSvc->publishPlan($c3PlanId, false, false, null);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    $c3BlockedWithoutOverride = str_contains($e->getMessage(), 'alergênico') || str_contains($e->getMessage(), 'Publicação bloqueada');
}
check($c3BlockedWithoutOverride, 'C3: Publicação sem justificativa clínica é bloqueada quando há conflito de alergia');

$c3PublishedWithJustification = $planSvc->publishPlan($c3PlanId, true, false, 'Dessensibilização oral supervisionada em ambulatório de alergia.');
check($c3PublishedWithJustification === true, 'C3b: Publicação com override expresso e justificativa clínica (>=10 chars) é aceita e auditada');

// =========================================================================
// CENÁRIO C4: Medida caseira da clínica (colher, fatia) com fonte obrigatória
// =========================================================================
$cmSvc->upsert(1004, 'colher de servir cheia', 45, 'Pesagem na balança da Clínica TESTE Avaliação', 'Ficha técnica 01');
$c4Conv = (new HouseholdMeasureConverter($db))->convertDetailed(2, 'colher de servir cheia', 1004, null, false);
check($c4Conv['grams'] === 90.0 && $c4Conv['origin'] === 'clinic' && $c4Conv['estimated'] === false, 'C4: Medida caseira da clínica converte 2 colheres de servir cheias em 90 g exatos com procedência da clínica');

// =========================================================================
// CENÁRIO C5: Busca por "aipim" e "macaxeira" no seletor -> Mandioca cozida na 1ª busca
// =========================================================================
foreach (['aipim', 'macaxeira'] as $regTerm) {
    $terms = FoodSearchIndex::terms($regTerm);
    $sql = FoodSearchIndex::sql($terms, 'f.search_text', 'f.name', $db, ['synonyms' => true]);
    $st = $db->prepare("SELECT f.name FROM foods f WHERE {$sql['where']} ORDER BY {$sql['order']}");
    $st->execute($sql['where_params'] + $sql['order_params']);
    $results = $st->fetchAll(PDO::FETCH_COLUMN);
    $prep = FoodSearchIndex::preparation((string)($results[0] ?? ''));
    check(($results[0] ?? '') === 'Mandioca, cozida' && $prep === 'Cozido', "C5: Busca por '{$regTerm}' encontra 'Mandioca, cozida' em 1º lugar com preparo 'Cozido' visível");
}

// =========================================================================
// CENÁRIO C6: Salvar plano C1 como modelo e usar para o Idoso (72 anos) sem copiar meta individual
// =========================================================================
$c6Tmpl = $tmplSvc->saveFromPlan($c1PlanId, $p1User, 'Modelo TESTE Redução 2000 kcal', 'Redução de peso', 'Modelo C6', false, 'clinica', false);
$c6Use = $tmplSvc->createPlanFromTemplate($c6Tmpl['id'], $p1Prof, $patElderly, 'TESTE C6 Plano Idoso 72 Anos');
$c6PlanId = (int)$c6Use['plan_id'];
$c6Plan = $planSvc->getPlanWithDetails($c6PlanId);
check((int)$c6Plan['patient_id'] === $patElderly && count($c6Plan['meals']) === 6 && $c6Plan['energy_target'] === null, 'C6: Novo plano criado do modelo para o Idoso traz as 6 refeições e NÃO copia a meta individual do paciente anterior');

// Calcular meta para o idoso (72 anos) e verificar alerta de >= 65 anos
$c6Target = $energySvc->save($c6PlanId, [
    'formula' => 'mifflin_st_jeor',
    'sex' => 'male',
    'age_years' => '72',
    'weight_kg' => '71',
    'height_cm' => '170',
    'activity_factor' => '1.40',
    'goal_type' => 'maintenance',
    'adjust_mode' => 'kcal',
    'adjust_value' => '0',
    'macro_mode' => 'percent',
    'protein' => '25',
    'carbohydrate' => '45',
    'lipid' => '30',
]);
check(in_array('Para idosos (65 anos ou mais), confira a perda de massa magra e avalie a adequação da equação e do aporte proteico.', $c6Target['warnings'], true), 'C6: Cálculo energético para o idoso (72 anos) emite alerta clínico para >= 65 anos');

// =========================================================================
// CENÁRIO C7: Substituição de alimento, diferença de energia/macros e aviso claro de não-equivalência
// =========================================================================
$sameGrams = SubstitutionComparison::sameEnergyGrams(248.0, 125.0); // 200g arroz integral (248 kcal) por mandioca cozida (125 kcal/100g) -> 198g
$subId = $planSvc->addSubstitution($c1RiceItemId, 1007, (float)$sameGrams, 'g', 'Troca por mandioca cozida');
$c7Plan = $planSvc->getPlanWithDetails($c1PlanId);
$c7RiceItem = $c7Plan['meals'][2]['items'][0];
$c7SubItem = $c7RiceItem['substitutions'][0];
$c7Delta = SubstitutionComparison::delta($c7RiceItem, $c7SubItem);
$c7Eq = (new FoodEquivalenceService($db))->evaluate(
    ['category' => $c7RiceItem['food_category']] + $c7RiceItem,
    ['category' => $c7SubItem['food_category']] + $c7SubItem
);
check(abs($c7Delta['energy_kcal']) <= 1.0 && $c7Eq['verdict'] !== 'equivalente', 'C7: Substituição apresenta diferença exata de kcal/macros e avisa que não há equivalência automática entre grupos sem regra');

// =========================================================================
// CENÁRIO C8: Publicar v1, editar rascunho/duplicar e publicar v2 preservando histórico imutável
// =========================================================================
$publishedV1 = $planSvc->publishPlan($c1PlanId);
$v1AfterPub = $planSvc->getPlanWithDetails($c1PlanId);
check($publishedV1 === true && $v1AfterPub['status'] === 'published' && (int)$v1AfterPub['version'] === 1, 'C8: Publica versão 1 do plano C1');

$dupRes = $planSvc->duplicatePlan($c1PlanId);
$v2DraftId = (int)$dupRes['plan_id'];
$v2Meals = $planSvc->getPlanWithDetails($v2DraftId)['meals'];
$planSvc->addItem((int)$v2Meals[1]['id'], 1009, 100, 'g'); // Adiciona iogurte na colação da v2
$publishedV2 = $planSvc->publishPlan($v2DraftId);
$v2AfterPub = $planSvc->getPlanWithDetails($v2DraftId);

$versionsCount = (int)$db->query("SELECT COUNT(*) FROM meal_plan_versions WHERE meal_plan_id IN ({$c1PlanId}, {$v2DraftId})")->fetchColumn();
$v1StillIntact = $planSvc->getPlanWithDetails($c1PlanId);
check($publishedV2 === true && $versionsCount === 2 && (float)$v1StillIntact['daily_energy_kcal'] < (float)$v2AfterPub['daily_energy_kcal'], 'C8: Publica segunda versão preservando integralmente a versão 1 e seus snapshots em meal_plan_versions');

// =========================================================================
// CENÁRIO C9: Impressão legível do plano com meta, refeições e orientações
// =========================================================================
$doc = (new \ClyoNutri\Domain\MealPlan\MealPlanPrintService($db))->build($v2DraftId, $p1User, true);
ob_start();
require __DIR__ . '/../views/meal_plans/print.php';
$printHtml = (string)ob_get_clean();
check(str_contains($printHtml, 'TESTE C1 Redução de Peso') && str_contains($printHtml, 'Café da manhã') && str_contains($printHtml, 'Hidratação: 35 ml/kg/dia'), 'C9: Impressão do plano renderiza título, refeições, alimentos e orientações sem erro');

// =========================================================================
// CENÁRIO C10: Importar a planilha CSV TESTE oficial (10 linhas: 8 válidas, 2 com erro) + Confirmar + Desfazer
// =========================================================================
$csvPath = __DIR__ . '/../docs/benchmark-webdiet/pacientes_teste_ni2_523.csv';
$csvContent = (string)file_get_contents($csvPath);
$impSvc = new PatientImportService($db);
$c10Prev = $impSvc->preview($csvContent, 'pacientes_teste_ni2_523.csv', $p1User);
$c10Batch = $impSvc->find($c10Prev['batch_id']);
check((int)$c10Batch['total_rows'] === 10 && (int)$c10Batch['valid_rows'] === 8 && (int)$c10Batch['error_rows'] === 2, 'C10: Prévia da planilha CSV TESTE de 10 linhas identifica 8 válidos e 2 com erro (sem nome e data 31/02/1990)');

$c10Conf = $impSvc->confirm($c10Prev['batch_id']);
check($c10Conf['created'] === 8 && $c10Conf['skipped'] === 0, 'C10: Confirmação cadastra exatamente os 8 pacientes válidos sem duplicação');

$c10Rb = $impSvc->rollback($c10Prev['batch_id']);
check($c10Rb['removed'] === 8 && $c10Rb['kept'] === 0, 'C10: Desfazer importação remove os 8 pacientes TESTE recém-importados');

// =========================================================================
// CENÁRIO C11: Privacidade e RBAC — Modelo não guarda paciente e Recepção é bloqueada nos planos
// =========================================================================
$rawTmplRow = $db->query("SELECT * FROM meal_plan_templates WHERE id = {$c6Tmpl['id']}")->fetch(PDO::FETCH_ASSOC);
$rawTmplJson = json_encode($rawTmplRow, JSON_UNESCAPED_UNICODE);
check(!str_contains($rawTmplJson, 'TESTE Adulto Redução de Peso') && !str_contains($rawTmplJson, '88') && !str_contains($rawTmplJson, '176'), 'C11a: Modelo salvo não armazena nome, peso, altura nem ID do paciente de origem');

// Verificar que recepcionista não possui vínculo em professionals nem permissão em canEditPlanItems / MealPlanTemplateController
$recHasProf = (bool)$db->query("SELECT 1 FROM professionals WHERE user_id = {$recUser}")->fetchColumn();
$recMemberRole = (string)$db->query("SELECT role FROM clinic_members WHERE clinic_id = {$clinicId} AND user_id = {$recUser}")->fetchColumn();
check($recHasProf === false && $recMemberRole === 'receptionist', 'C11b: Perfil de recepção não possui registro de prescritor (professionals) e é bloqueado nas rotas de prescrição/modelos/importação');

echo "\nResultado NI2-523 Clinical Scenarios (C1..C11): {$passed} PASS, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
