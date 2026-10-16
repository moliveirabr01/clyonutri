<?php

declare(strict_types=1);

// F01 — prescrição não pode criar plano para paciente de outra clínica (auditoria 14/10/2026).
session_start();
ob_start(); // mantém cabeçalhos HTTP "não enviados" para o controller poder definir o status
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Application\Controllers\RecipeController;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Recipe\RecipeService;

echo "F01 — autorização antes de criar plano na prescrição\n";
$fx = aud_fixture();
$db = $fx['db'];
$plans = static fn(): int => (int)$db->query('SELECT COUNT(*) FROM meal_plans')->fetchColumn();
$presc = static fn(): int => (int)$db->query('SELECT COUNT(*) FROM recipe_prescriptions')->fetchColumn();

// Requisição real ao controller com sessão da clínica A
$callController = static function (array $post) use ($fx): array {
    $_SESSION['user_id'] = $fx['userA'];
    $_SESSION['clinic_id'] = $fx['clinicA'];
    $_POST = $post + ['csrf_token' => Csrf::token(), 'servings' => '1', 'meal_name' => 'Almoço'];
    http_response_code(200);
    ob_start();
    (new RecipeController($fx['db']))->prescribe(['id' => (string)$fx['recipeA']]);
    $out = (string)ob_get_clean();
    return [http_response_code(), $out];
};

// 1. Paciente B sem plano
$before = $plans();
[$code, $out] = $callController(['patient_id' => (string)$fx['patientB'], 'meal_plan_id' => '0']);
aud_check(in_array($code, [403, 404], true), "paciente de outra clínica sem plano -> {$code}");
aud_check($plans() === $before, 'número de planos inalterado (nenhum INSERT antes da autorização)');
aud_check((int)$db->query("SELECT COUNT(*) FROM meal_plans WHERE patient_id = {$fx['patientB']} AND clinic_id = {$fx['clinicA']}")->fetchColumn() === 0, 'não existe plano (clínica A, paciente B)');

// 2. Plano estrangeiro existente (plano da clínica B para paciente B)
$db->prepare("INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status) VALUES (?, ?, ?, 'Plano Beta', 'active')")
    ->execute([$fx['clinicB'], $fx['patientB'], $fx['profB']]);
$planB = (int)$db->lastInsertId();
$before = $plans();
$pBefore = $presc();
[$code] = $callController(['patient_id' => (string)$fx['patientB'], 'meal_plan_id' => (string)$planB]);
aud_check(in_array($code, [403, 404], true), "plano estrangeiro informado explicitamente -> {$code}");
[$code] = $callController(['patient_id' => (string)$fx['patientA'], 'meal_plan_id' => (string)$planB]);
aud_check(in_array($code, [403, 404], true), "paciente próprio + plano de outra clínica -> {$code}");
[$code] = $callController(['patient_id' => (string)$fx['patientB'], 'meal_plan_id' => '0']);
aud_check(in_array($code, [403, 404], true), "paciente B com plano existente (busca automática) -> {$code}");
aud_check($plans() === $before && $presc() === $pBefore, 'planos e prescrições inalterados');

// 3. IDs inexistentes
$before = $plans();
[$code] = $callController(['patient_id' => '999999', 'meal_plan_id' => '0']);
aud_check(in_array($code, [403, 404], true), "paciente inexistente -> {$code}");
[$code] = $callController(['patient_id' => (string)$fx['patientA'], 'meal_plan_id' => '999999']);
aud_check(in_array($code, [403, 404], true), "plano inexistente -> {$code}");
aud_check($plans() === $before, 'nenhum plano criado com IDs inexistentes');

// 4. Profissional de outra clínica não serve como autor
$svc = new RecipeService($db);
$threw = false;
try {
    $svc->prescribeRecipeWithPlan($fx['clinicA'], $fx['profB'], $fx['patientA'], 0, $fx['recipeA']);
} catch (TenantSecurityException) {
    $threw = true;
}
aud_check($threw && $plans() === $before, 'profissional de outra clínica rejeitado sem criar plano');

// 5. Rollback: falha depois da criação do plano desfaz o plano
$threw = false;
try {
    $svc->prescribeRecipeWithPlan($fx['clinicA'], $fx['profA'], $fx['patientA'], 0, 987654); // receita inexistente
} catch (\Throwable) {
    $threw = true;
}
aud_check($threw && $plans() === $before, 'erro na prescrição faz rollback do plano criado na mesma transação');
aud_check(RecipeController::httpStatusForException(new TenantSecurityException('x', 403)) === 403, 'TenantSecurityException mapeada para 403');

// 6. Caminho autorizado cria exatamente um plano e reutiliza nas próximas prescrições
$before = $plans();
$svc->prescribeRecipeWithPlan($fx['clinicA'], $fx['profA'], $fx['patientA'], 0, $fx['recipeA']);
aud_check($plans() === $before + 1, 'fluxo autorizado cria exatamente 1 plano');
$svc->prescribeRecipeWithPlan($fx['clinicA'], $fx['profA'], $fx['patientA'], 0, $fx['recipeA']);
aud_check($plans() === $before + 1, 'segunda prescrição reutiliza o plano (clinic_id + patient_id)');
$row = $db->query("SELECT clinic_id, patient_id FROM meal_plans ORDER BY id DESC LIMIT 1")->fetch();
aud_check((int)$row['clinic_id'] === $fx['clinicA'] && (int)$row['patient_id'] === $fx['patientA'], 'plano criado pertence à clínica A / paciente A');
aud_check($presc() === $pBefore + 2, '2 prescrições gravadas');

echo "F01: OK\n";
