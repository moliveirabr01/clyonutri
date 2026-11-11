<?php

declare(strict_types=1);

/**
 * Suíte Automatizada — NI2-514 Fase 2 (v42):
 * - NI2-519: Modelos de plano reutilizáveis (MealPlanTemplateService) e comparação de substituição (SubstitutionComparison)
 * - NI2-520: Sinônimos regionais, preparo e métrica de buscas sem resultado (FoodSearchIndex + FoodSearchController)
 * - NI2-521: Barra de totais e adequação (PlanTotalsBar)
 * - NI2-522: Importação de pacientes via CSV com prévia, deduplicação (+55 DDI M3), desfazer e expurgo no cron (M2)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Controllers\FoodSearchController;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\Food\FoodSearchIndex;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\MealPlanTemplateService;
use ClyoNutri\Domain\MealPlan\PlanTotalsBar;
use ClyoNutri\Domain\MealPlan\SubstitutionComparison;
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

echo "=== NI2-514 Fase 2 (P1) — Modelos, Sinônimos, Totais e Importação CSV de Pacientes ===\n";

$db->exec("INSERT INTO clinics (id, uuid, name, status) VALUES (1, 'c-f2-1', 'Clínica Fase 2', 'active')");
$db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, status, is_verified) VALUES
    (10, 'u-f2-10', 'Dra. Clara', 'clara@f2.test', 'x', 'nutritionist', 'active', 1)");
$db->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 10, 'owner', 'active')");
$db->exec("INSERT INTO professionals (id, user_id, crn, crn_region, specialty) VALUES (100, 10, '33333', 'CRN-3', 'Clínica')");
$db->exec("INSERT INTO patients (id, clinic_id, uuid, name, email, phone, status) VALUES
    (1000, 1, 'p-f2-1000', 'Paciente Com DDI', 'ddi@paciente.test', '+55 (11) 98888-7777', 'active')");

$db->exec("INSERT INTO foods (id, clinic_id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, source, taco_code, is_current) VALUES
    (601, NULL, 'Mandioca, cozida', 'Verduras, hortaliças e derivados', 125, 0.6, 30.1, 0.3, 1.6, 'TACO', 101, 1),
    (602, NULL, 'Batata, inglesa, cozida', 'Verduras, hortaliças e derivados', 52, 1.2, 11.9, 0.0, 1.3, 'TACO', 102, 1)");
FoodSearchIndex::sync($db);

TenantContext::set(1, ['id' => 10, 'role' => 'nutritionist', 'name' => 'Dra. Clara']);

// 1. FoodSearchIndex: sinônimos regionais e preparo
check(in_array('mandioca', FoodSearchIndex::synonymsOf('aipim'), true), 'Sinônimo regional: aipim resolve para mandioca e macaxeira');
check(FoodSearchIndex::preparation('Mandioca, cozida') === 'Cozido', 'Extrai forma de preparo (Cozido) do nome do alimento');
FoodSearchController::recordSearch($db, ['kombucha', 'artesanal'], 0);
$zeroCount = (int)$db->query("SELECT zero_results FROM food_search_metrics WHERE clinic_id = 1 AND term = 'kombucha artesanal'")->fetchColumn();
check($zeroCount === 1, 'Registra busca sem resultado na métrica diária da clínica');

// 2. SubstitutionComparison & PlanTotalsBar
$sameG = SubstitutionComparison::sameEnergyGrams(125, 52);
check($sameG === 240.0, 'SubstitutionComparison::sameEnergyGrams calcula gramas para a mesma energia (240 g)');
$delta = SubstitutionComparison::delta(['energy_kcal' => 125, 'protein_g' => 0.6, 'carbohydrate_g' => 30.1, 'lipid_g' => 0.3], ['energy_kcal' => 124.8, 'protein_g' => 2.9, 'carbohydrate_g' => 28.6, 'lipid_g' => 0.0]);
check($delta['energy_kcal'] === -0.2 && str_contains(SubstitutionComparison::label($delta), 'kcal'), 'SubstitutionComparison::delta e label formatam diferença de macros');

$bar = PlanTotalsBar::build(['daily_energy_kcal' => 1800, 'protein_g' => 90, 'carbohydrate_g' => 220, 'lipid_g' => 60, 'fiber_g' => 25], ['vet_kcal' => 1800, 'protein_g' => 90, 'carbohydrate_g' => 200, 'lipid_g' => 60]);
check($bar['has_target'] === true && $bar['rows']['energy']['status'] === 'ok' && $bar['rows']['carbohydrate']['status'] === 'acima', 'PlanTotalsBar classifica adequação (ok dentro de 5%, acima quando > 105%)');

// 3. MealPlanTemplateService: salvar como modelo e criar plano a partir do modelo
$planSvc = new MealPlanService($db);
$plan = $planSvc->createPlan(1000, 100, 'Plano Base Hipertrofia', 'Beber 3L de água', [
    ['name' => 'Almoço', 'time_of_day' => '12:30', 'notes' => 'Prato principal', 'items' => [
        ['food_id' => 601, 'amount' => 100, 'measure_unit' => 'g', 'substitutions' => [
            ['food_id' => 602, 'amount' => 240, 'measure_unit' => 'g'],
        ]],
    ]],
]);
$tmplSvc = new MealPlanTemplateService($db);
$savedTmpl = $tmplSvc->saveFromPlan((int)$plan['plan_id'], 10, 'Modelo Raízes', 'Hipertrofia', 'Modelo padrão de teste', false);
check($savedTmpl['id'] > 0 && $savedTmpl['meals'] === 1 && $savedTmpl['items'] === 1, 'Salva plano como modelo reutilizável sem copiar paciente');

$fromTmpl = $tmplSvc->createPlanFromTemplate($savedTmpl['id'], 100, null, 'Plano do Modelo');
check((int)$fromTmpl['plan_id'] > 0 && $fromTmpl['skipped'] === [], 'Cria novo plano a partir do modelo sem alterar o modelo original');

// 4. PatientImportService (NI2-522): prévia, deduplicação com DDI +55 (M3), confirmação, rollback e expurgo no cron (M2)
$impSvc = new PatientImportService($db);
$csv = "Nome;E-mail;Celular;Data de nascimento;Gênero;Observações\n"
    . "Maria Nova;maria.nova@teste.com;(11) 97777-6666;12/04/1995;Feminino;Sem restrições\n"
    . "Duplicada DDI;outro@teste.com;(11) 98888-7777;10/10/1990;Feminino;Mesmo telefone sem +55\n"
    . ";;11911112222;;;Linha sem nome\n";

$prev = $impSvc->preview($csv, 'pacientes.csv', 10);
$batch = $impSvc->find($prev['batch_id']);
check((int)$batch['valid_rows'] === 1 && (int)$batch['skipped_rows'] === 1 && (int)$batch['error_rows'] === 1, 'M3: Prévia CSV valida linhas e detecta duplicata de telefone mesmo quando o cadastro no banco tem +55');

$conf = $impSvc->confirm($prev['batch_id']);
check($conf['created'] === 1, 'Confirma importação CSV criando apenas os pacientes válidos');
$rawLeft = (int)$db->query("SELECT COUNT(*) FROM patient_import_rows WHERE batch_id = {$prev['batch_id']} AND raw_json IS NOT NULL")->fetchColumn();
check($rawLeft === 0, 'LGPD: apaga raw_json das linhas logo após confirmar a importação');

$rb = $impSvc->rollback($prev['batch_id']);
check($rb['removed'] === 1 && $rb['kept'] === 0, 'Desfazer importação remove paciente recém-importado que ainda não tem vínculo clínico');

// M2: criar prévia abandonada há 25h e expurgar via purgeAllStalePreviews
$prevStale = $impSvc->preview("Nome;E-mail\nTeste Abandonado;abandonado@teste.com\n", 'stale.csv', 10);
$db->prepare("UPDATE patient_import_batches SET created_at = ? WHERE id = ?")
    ->execute([date('Y-m-d H:i:s', time() - 25 * 3600), $prevStale['batch_id']]);
$purged = PatientImportService::purgeAllStalePreviews($db);
check($purged === 1 && $impSvc->find($prevStale['batch_id']) === null, 'M2: purgeAllStalePreviews expurga prévias abandonadas há mais de 24h de todas as clínicas');

echo "\nResultado NI2-514 Fase 2: {$passed} PASS, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
