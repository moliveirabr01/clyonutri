<?php

declare(strict_types=1);

// Regressão do code review NI2-9 (19/10/2026): cada cenário reproduzia um defeito no HEAD 496f4ff.
// C1 alérgenos fail-closed · A1 autorização única de prescrição · A2 autoria/justificativa na auditoria
// A3 peso persistido (sem subconsulta aproximada) · M1 paciente lê o snapshot · M2 só rascunho publica
// M3 peso estimado sinalizado · M4 mutações com guarda de status · B1 quantidade finita e ≤ 5 kg.
session_start();
ob_start();
require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Application\Api\PatientApiController;
use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\MealPlan\MealPlanService;

$db = Connection::getInstance();
// Banco de testes já existente não recebe migrações novas automaticamente: aplica as pendentes (idempotente).
(new ClyoNutri\Core\Database\Migrator($db, __DIR__ . '/../database/migrations'))->run();
$passed = 0;
$failed = 0;
$log = [];
function check(bool $ok, string $msg): void
{
    global $passed, $failed, $log;
    $ok ? $passed++ : $failed++;
    $log[] = ($ok ? '  [PASS] ' : '  [FAIL] ') . $msg;
}
function throws(callable $fn, string $needle = ''): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }
}
$finally = null;
register_shutdown_function(function () {
    global $passed, $failed, $log, $finally;
    if (is_callable($finally)) {
        $finally();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo "REGRESSÃO CODE REVIEW NI2-9\n" . implode("\n", $log) . "\n";
    echo "  PASSOU: {$passed}\n  FALHOU: {$failed}\n";
    if ($failed > 0) {
        exit(1);
    }
});

// ---------- Fixture: clínica 951, nutricionista com CRN, gestor sem CRN, paciente celíaca ----------
$db->exec("INSERT OR IGNORE INTO clinics (id, uuid, name, document) VALUES (951,'c951','Clínica Regressão','95100000000101')");
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role, status) VALUES (951,'u951','Dra. Ana','ana951@rev.test','x','nutritionist','active')");
$db->exec("INSERT OR IGNORE INTO users (id, uuid, name, email, password_hash, role, status) VALUES (952,'u952','Bruno Gestor','bruno952@rev.test','x','nutritionist','active')");
$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role, status) VALUES (951,951,'nutritionist','active')");
$db->exec("INSERT OR IGNORE INTO clinic_members (clinic_id, user_id, role, status) VALUES (951,952,'admin','active')");
$db->exec("INSERT OR IGNORE INTO professionals (id, user_id, crn, crn_region) VALUES (951,951,'CRN-9501','CRN-3')");
$db->exec("DELETE FROM patients WHERE uuid IN ('p951','p952')");
$db->exec("INSERT INTO patients (clinic_id, uuid, name, status) VALUES (951,'p951','Paciente Celíaca','active')");
$pid = (int)$db->lastInsertId();
$db->exec("INSERT INTO patients (clinic_id, uuid, name, status) VALUES (951,'p952','Paciente Kiwi','active')");
$pidKiwi = (int)$db->lastInsertId();
$db->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, is_draft) VALUES (951,$pid,951,1,'Doença celíaca. Alergia a frutos do mar.',0)");
$db->exec("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, is_draft) VALUES (951,$pidKiwi,951,1,'Alergia a kiwi',0)");
$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, is_custom, allergens) VALUES (9951,951,'Pão caseiro da clínica','Pães','custom',300,9,55,4,2,1,NULL)");
$db->exec("INSERT OR IGNORE INTO foods (id, clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, is_custom, allergens) VALUES (9952,951,'Arroz cozido verificado','Cereais','custom',128,2.5,28,0.2,1.6,1,'nenhum')");
$db->exec("UPDATE foods SET name='Pão caseiro da clínica', energy_kcal=300 WHERE id=9951");
$cracker = (int)$db->query("SELECT id FROM foods WHERE name = 'Biscoito, salgado, cream cracker' LIMIT 1")->fetchColumn();
$egg = (int)$db->query("SELECT id FROM foods WHERE name LIKE 'Ovo de galinha cozido%' LIMIT 1")->fetchColumn();

TenantContext::set(951, ['id' => 951, 'role' => 'nutritionist', 'name' => 'Dra. Ana']);
$_SESSION['user_id'] = 951;
$_SESSION['clinic_id'] = 951;
$svc = new MealPlanService($db);
$v = new AllergenSafetyValidator($db);

$newPlan = function (int $patientId, string $title, int $foodId, float $amount = 50, string $unit = 'g') use ($svc): int {
    $p = (int)$svc->createPlan($patientId, 951, $title, null)['plan_id'];
    $m = $svc->addMeal($p, 'Refeição', '12:00');
    $svc->addItem($m, $foodId, $amount, $unit);
    return $p;
};

// ---------- C1: alérgenos fail-closed ----------
check($v->getPatientAllergens($pid) === ['gluten', 'crustaceos', 'moluscos'], 'C1 "doença celíaca" → glúten; "frutos do mar" → crustáceos e moluscos');
$r = $v->validateFoodsForPatient($pid, [$cracker]);
check($r['has_conflict'] && $r['conflicts'][0]['allergen_group'] === 'gluten', 'C1 cream cracker conflita com glúten (pelo nome do produto)');
$r = $v->validateFoodsForPatient($pid, [9951]);
check($r['has_conflict'], 'C1 "Pão caseiro" de clínica sem alérgenos declarados conflita com glúten pelo nome');
$r = $v->validateFoodsForPatient($pid, [9952]);
check(!$r['requires_confirmation'], 'C1 alimento verificado sem alérgenos ("nenhum") não exige confirmação');
$tomato = (int)$db->query("SELECT id FROM foods WHERE clinic_id IS NULL AND allergens IS NULL AND name LIKE 'Tomate%' LIMIT 1")->fetchColumn();
$r = $v->validateFoodsForPatient($pid, [$tomato]);
check($r['unverified_foods'] !== [] && $r['requires_confirmation'], 'C1 alimento sem informação de alérgenos fica "não verificado" e exige confirmação');
$r = $v->validateFoodsForPatient($pidKiwi, [9952]);
check($r['unmapped_allergy_text'] && $r['requires_confirmation'], 'C1 alergia não reconhecida ("kiwi") exige confirmação');
$pCracker = $newPlan($pid, 'Plano cream cracker', $cracker, 30);
check(throws(fn() => $svc->publishPlan($pCracker, false), 'conflito de alergênicos'), 'C1 plano com cream cracker para paciente celíaca NÃO publica sem override');
check($db->query("SELECT status FROM meal_plans WHERE id = {$pCracker}")->fetchColumn() === 'draft', 'C1 bloqueio desfaz a transação: plano continua rascunho');

// ---------- A2: override exige justificativa e registra autor ----------
check(throws(fn() => $svc->publishPlan($pCracker, true), 'justificativa'), 'A2 override sem justificativa é recusado');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$res = $svc->publishPlan($pCracker, true, true, 'Cream cracker sem glúten da marca conferida com a paciente.');
check($res['status'] === 'published', 'A2 override com justificativa publica');
$audit = $db->query("SELECT user_id, ip_address, details FROM audit_logs WHERE action = 'MEAL_PLAN_PUBLISHED' AND resource_id = '{$pCracker}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$details = json_decode((string)$audit['details'], true);
check((int)$audit['user_id'] === 951 && $audit['ip_address'] === '203.0.113.9', 'A2 auditoria da publicação grava usuário e IP');
check(($details['allergen_override'] ?? null) === true && str_contains((string)$details['allergen_override_reason'], 'marca conferida'), 'A2 auditoria grava override e justificativa');
$ver = $db->query("SELECT published_by_user_id, allergen_override_reason, payload_json FROM meal_plan_versions WHERE meal_plan_id = {$pCracker}")->fetch(PDO::FETCH_ASSOC);
check((int)$ver['published_by_user_id'] === 951 && $ver['allergen_override_reason'] !== null, 'A2 versão publicada guarda autor e justificativa');
check(json_decode($ver['payload_json'], true)['status'] === 'published', 'A2 snapshot registra status publicado (não "draft")');

// ---------- M2: só rascunho publica; versão única ----------
$pVerified = $newPlan($pid, 'Plano verificado', 9952, 150);
$svc->publishPlan($pVerified);
check($db->query("SELECT status FROM meal_plans WHERE id = {$pCracker}")->fetchColumn() === 'superseded', 'M2 versão anterior vira superseded');
check(throws(fn() => $svc->publishPlan($pCracker, true, false, 'Tentativa de republicar plano antigo.'), 'Somente rascunhos'), 'M2 plano superseded não é republicado');
check(throws(fn() => $svc->publishPlan($pVerified), 'já está publicado'), 'M2 plano publicado não é publicado de novo');
$uq = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'uq_meal_plan_versions_number'")->fetchColumn();
check((int)$uq === 1, 'M2 índice único (clinic_id, patient_id, version_number) existe');
check(throws(fn() => $db->exec("INSERT INTO meal_plan_versions (clinic_id, meal_plan_id, patient_id, professional_id, version_number, title, payload_json, published_at)
    VALUES (951, {$pVerified}, {$pid}, 951, 2, 'dup', '{}', CURRENT_TIMESTAMP)")), 'M2 número de versão repetido é rejeitado pelo banco');

// ---------- M4: mutações em plano publicado falham no próprio SQL ----------
$mealOfPublished = (int)$db->query("SELECT id FROM meal_plan_meals WHERE meal_plan_id = {$pVerified} LIMIT 1")->fetchColumn();
$itemOfPublished = (int)$db->query("SELECT id FROM meal_plan_items WHERE meal_id = {$mealOfPublished} LIMIT 1")->fetchColumn();
check(throws(fn() => $svc->addMeal($pVerified, 'Nova', '10:00'), 'imutável'), 'M4 addMeal em plano publicado é recusado');
check(throws(fn() => $svc->addItem($mealOfPublished, 9952, 10, 'g'), 'imutável'), 'M4 addItem em plano publicado é recusado');
check(throws(fn() => $svc->deleteItem($itemOfPublished), 'imutável'), 'M4 deleteItem em plano publicado é recusado');
check(throws(fn() => $svc->deleteMeal($mealOfPublished), 'imutável'), 'M4 deleteMeal em plano publicado é recusado');
check((int)$db->query("SELECT COUNT(*) FROM meal_plan_items WHERE meal_id = {$mealOfPublished}")->fetchColumn() === 1, 'M4 itens do plano publicado continuam intactos');

// ---------- M1: paciente lê o snapshot publicado ----------
$pSnap = $newPlan($pid, 'Plano snapshot', 9951, 50);
$svc->publishPlan($pSnap, true, false, 'Pão caseiro sem glúten preparado pela própria clínica.');
$db->exec("UPDATE foods SET name = 'Pão RENOMEADO', energy_kcal = 999 WHERE id = 9951");
$api = (new PatientApiController($db))->getDailyMealPlan($pid);
$apiItem = $api['meals'][0]['items'][0] ?? [];
check(($apiItem['food_name'] ?? '') === 'Pão caseiro da clínica', 'M1 API do paciente mostra o alimento como publicado, mesmo após renomear no catálogo');
check(abs((float)($apiItem['energy_kcal'] ?? 0) - 150.0) < 0.01, 'M1 energia do item na API é a da porção prescrita (50 g = 150 kcal), não por 100 g');
check(($api['version'] ?? null) === 3 && $api['status'] === 'published' && $apiItem['quantity'] == 50 && $apiItem['unit'] === 'g', 'M1 contrato do app preservado (version, status, quantity, unit)');
$db->exec("UPDATE foods SET name = 'Pão caseiro da clínica', energy_kcal = 300 WHERE id = 9951");

// ---------- A3 + M3: peso persistido; medida genérica marcada como estimada ----------
$pW = (int)$svc->createPlan($pid, 951, 'Plano pesos', null)['plan_id'];
$mW = $svc->addMeal($pW, 'Café', '08:00');
$iEgg = $svc->addItem($mW, $egg, 2, 'unidade');
$iRice = $svc->addItem($mW, 9952, 120, 'g');
$row = fn(int $id) => $db->query("SELECT weight_grams, weight_is_estimated FROM meal_plan_items WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
check((float)$row($iEgg)['weight_grams'] === 200.0 && (int)$row($iEgg)['weight_is_estimated'] === 1, 'A3/M3 "2 unidade" de ovo grava 200 g e marca peso estimado');
check((float)$row($iRice)['weight_grams'] === 120.0 && (int)$row($iRice)['weight_is_estimated'] === 0, 'A3 item em gramas grava o peso exato');
$preview = $svc->getPlanPreview($pW);
check(count($preview['estimated_weights']) === 1, 'M3 prévia lista os itens com peso estimado');
$src = (string)file_get_contents(__DIR__ . '/../src/Domain/MealPlan/MealPlanService.php');
check(!str_contains($src, 'measure_grams_equivalent') && !str_contains($src, "'%' ||"), 'A3 subconsulta aproximada com || removida');
$print = (new ClyoNutri\Domain\MealPlan\MealPlanPrintService($db))->build($pW, 951, true);
$qty = $print['meals'][0]['items'][0]['quantity'] ?? '';
check(str_contains($qty, '~200g, peso estimado'), "A3 impressão mostra o peso gravado: '{$qty}'");

// ---------- B1: quantidade finita e ≤ 5 kg ----------
check(throws(fn() => $svc->addItem($mW, 9952, INF, 'g')), 'B1 quantidade infinita recusada');
check(throws(fn() => $svc->addItem($mW, 9952, 6000, 'g'), '5 kg'), 'B1 6 kg recusado');
check(throws(fn() => $svc->addSubstitution($iRice, 9952, INF, 'g')), 'B1 substituição infinita recusada');

// ---------- A1: autorização única de prescrição (controller) ----------
$asUser = function (int $userId): void {
    $_SESSION['user_id'] = $userId;
    TenantContext::set(951, ['id' => $userId, 'role' => 'nutritionist']);
};
$callCtl = function (string $method, array $params = [], array $post = []): array {
    $_POST = $post;
    http_response_code(200);
    ob_start();
    try {
        (new DashboardController())->{$method}($params);
    } catch (Throwable $e) {
    }
    $out = (string)ob_get_clean();
    return [http_response_code(), $out];
};
$asUser(952);
$plansBefore = (int)$db->query("SELECT COUNT(*) FROM meal_plans WHERE clinic_id = 951")->fetchColumn();
$_POST = ['patient_id' => (string)$pid, 'title' => 'Plano do gestor'];
http_response_code(200);
ob_start();
(new DashboardController())->createMealPlan();
ob_end_clean();
check(http_response_code() === 403 && (int)$db->query("SELECT COUNT(*) FROM meal_plans WHERE clinic_id = 951")->fetchColumn() === $plansBefore, 'A1 usuário sem CRN não cria plano (403, nada gravado)');
$asUser(951);
$pDraft = $newPlan($pid, 'Rascunho para A1', 9952, 100);
$asUser(952);
[$code] = $callCtl('publishMealPlan', ['id' => (string)$pDraft], ['allow_allergen_override' => '1', 'allergen_override_reason' => 'Tentativa sem CRN de publicar.']);
check($code === 403 && $db->query("SELECT status FROM meal_plans WHERE id = {$pDraft}")->fetchColumn() === 'draft', 'A1 usuário sem CRN não publica (403, plano segue rascunho)');
$mealDraft = (int)$db->query("SELECT id FROM meal_plan_meals WHERE meal_plan_id = {$pDraft} LIMIT 1")->fetchColumn();
foreach ([['addMealToPlan', ['id' => (string)$pDraft], ['name' => 'X']], ['addItemToPlanMeal', ['id' => (string)$pDraft], ['meal_id' => (string)$mealDraft, 'food_id' => '9952', 'amount' => '10']], ['deleteMealFromPlan', ['id' => (string)$pDraft, 'mealId' => (string)$mealDraft], []]] as [$m, $params, $post]) {
    [$code] = $callCtl($m, $params, $post);
    check($code === 403, "A1 {$m} pelo usuário sem CRN → 403");
}
check((int)$db->query("SELECT COUNT(*) FROM meal_plan_meals WHERE meal_plan_id = {$pDraft}")->fetchColumn() === 1, 'A1 nenhuma alteração gravada pelo usuário sem CRN');
// Último cenário: createMealPlan com sucesso redireciona e encerra (exit); a conferência roda no shutdown.
$asUser(951);
$finally = function () use ($db) {
    $author = $db->query("SELECT professional_id FROM meal_plans WHERE clinic_id = 951 AND title = 'Plano da Dra. Ana' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check((int)$author === 951, 'A1 plano criado pela nutricionista é assinado por ela mesma');
};
$_POST = ['patient_id' => (string)$pid, 'title' => 'Plano da Dra. Ana'];
(new DashboardController())->createMealPlan();
