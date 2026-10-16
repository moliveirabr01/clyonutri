<?php

declare(strict_types=1);

// Reauditoria V2-01 — GET /receitas/{id}/prescrever não pode ler planos/anamnese de paciente de outra clínica.
// Duas clínicas sintéticas (Alfa e Beta) em SQLite isolado + HTTP real pelo front-controller.
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Recipe\RecipeService;

echo "V2-01 — leitura clínica no formulário de prescrição\n";
$fx = aud_fixture();
$db = $fx['db'];

$plan = static function (int $clinic, int $patient, int $prof, string $title) use ($db): int {
    $db->prepare("INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status) VALUES (?, ?, ?, ?, 'draft')")
        ->execute([$clinic, $patient, $prof, $title]);
    return (int)$db->lastInsertId();
};
$anamnese = static function (int $clinic, int $patient, int $prof, string $allergy, string $restriction) use ($db): void {
    $db->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, restrictions, is_draft) VALUES (?, ?, ?, 1, ?, ?, 0)")
        ->execute([$clinic, $patient, $prof, $allergy, $restriction]);
};

// Paciente A: plano + anamnese próprios. Paciente B: plano + anamnese. Paciente B2: SÓ anamnese (critério 4).
$planA = $plan($fx['clinicA'], $fx['patientA'], $fx['profA'], 'Plano visivel de A');
$anamnese($fx['clinicA'], $fx['patientA'], $fx['profA'], 'Alergia sintetica A', 'Restricao sintetica A');
$planB = $plan($fx['clinicB'], $fx['patientB'], $fx['profB'], 'Plano privado de B');
$anamnese($fx['clinicB'], $fx['patientB'], $fx['profB'], 'Alergia sintetica B', 'Restricao sintetica B');
TenantContext::set($fx['clinicB'], ['id' => $fx['userB'], 'name' => 'Dr. Beta', 'clinic_id' => $fx['clinicB']]);
$patientB2 = (int)(new \ClyoNutri\Domain\Patient\PatientRepository($db))->create(['name' => 'Paciente Beta Dois', 'email' => 'b2@aud.test'])['id'];
TenantContext::set($fx['clinicA'], ['id' => $fx['userA'], 'name' => 'Dra. Alfa', 'clinic_id' => $fx['clinicA']]);
$anamnese($fx['clinicB'], $patientB2, $fx['profB'], 'Alergia sintetica B2', 'Restricao sintetica B2');
$nonexistent = 987654;
$secretsB = ['Plano privado de B', 'Alergia sintetica B', 'Restricao sintetica B', "value=\"{$planB}\"", 'Paciente Beta'];

// ---------------------------------------------------------------- serviço (exige tenant)
$svc = new RecipeService($db);
$ctx = $svc->getPrescriptionPatientContext($fx['patientA'], $fx['clinicA']);
aud_check(count($ctx['plans']) === 1 && $ctx['plans'][0]['title'] === 'Plano visivel de A' && str_contains(implode(" ", $ctx["allergies"]), "Alergia sintetica A"), 'serviço: paciente A recebe os próprios planos e alertas');
foreach (['estrangeiro B' => $fx['patientB'], 'B só com anamnese' => $patientB2, 'inexistente' => $nonexistent, 'zero' => 0] as $label => $pid) {
    $thrown = null;
    try { $svc->getPrescriptionPatientContext($pid, $fx['clinicA']); } catch (TenantSecurityException $e) { $thrown = $e; }
    aud_check($thrown !== null, "serviço: paciente {$label} é recusado antes de qualquer leitura clínica");
}

// ---------------------------------------------------------------- HTTP real
$port = 18000 + random_int(0, 900);
$srv = aud_http_server($fx['path'], $port);
$base = $srv['base'];
$jar = tempnam(sys_get_temp_dir(), 'audjar');
[, , $html] = aud_http('GET', "{$base}/login", null, [], $jar);
preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m);
[$ls, $lh] = aud_http('POST', "{$base}/login", http_build_query(['_csrf_token' => $m[1] ?? '', 'email' => 'alfa@aud.test', 'password' => 'SenhaAlfa@2026']), [], $jar);
aud_check($ls === 302 && !str_contains($lh, 'location: /login'), 'HTTP: profissional da clínica A autenticado');

$url = static fn($pid) => "{$base}/receitas/{$fx['recipeA']}/prescrever?patient_id={$pid}";
// Critério 1: paciente B -> 404 sem dados de B
[$sB, , $bB] = aud_http('GET', $url($fx['patientB']), null, [], $jar);
aud_check($sB === 404, "critério 1: clínica A pedindo paciente B recebe {$sB}");
$leak = array_filter($secretsB, static fn($s) => str_contains($bB, $s));
aud_check($leak === [], 'critério 1: nenhuma alergia/restrição, ID ou título de plano de B no HTML');
// Critério 3: inexistente == estrangeiro
[$sN, $hN, $bN] = aud_http('GET', $url($nonexistent), null, [], $jar);
aud_check($sN === $sB && $bN === $bB, 'critério 3: ID inexistente recebe resposta idêntica (status e corpo) à do ID estrangeiro');
// Critério 4: B2 sem plano mas com anamnese
[$s2, , $b2] = aud_http('GET', $url($patientB2), null, [], $jar);
aud_check($s2 === 404 && !str_contains($b2, 'sintetica B2') && $b2 === $bB, 'critério 4: paciente B sem plano e com anamnese não revela nenhum campo clínico');
// Critério 2: paciente A legítimo
[$sA, , $bA] = aud_http('GET', $url($fx['patientA']), null, [], $jar);
aud_check($sA === 200 && str_contains($bA, 'Plano visivel de A') && str_contains($bA, 'Alergia sintetica A') && str_contains($bA, "value=\"{$planA}\""), 'critério 2: paciente A continua vendo os próprios planos e alertas');
aud_check(!array_filter($secretsB, static fn($s) => str_contains($bA, $s)), 'critério 2: formulário legítimo não contém dados de B');
// Sem patient_id: usa o primeiro paciente da própria clínica
[$s0, , $b0] = aud_http('GET', "{$base}/receitas/{$fx['recipeA']}/prescrever", null, [], $jar);
aud_check($s0 === 200 && !str_contains($b0, 'sintetica B'), 'sem patient_id: formulário abre com paciente da própria clínica');

// Critério 5: POST continua bloqueando paciente/plano estrangeiros sem resíduo
preg_match('/name="csrf_token" value="([^"]+)"/', $bA, $cm);
$count = static fn(string $t) => (int)$db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
$plansBefore = $count('meal_plans');
$rxBefore = $count('recipe_prescriptions');
foreach ([['patient_id' => $fx['patientB'], 'meal_plan_id' => 0], ['patient_id' => $fx['patientA'], 'meal_plan_id' => $planB], ['patient_id' => $nonexistent, 'meal_plan_id' => 0]] as $i => $post) {
    [$ps] = aud_http('POST', "{$base}/receitas/{$fx['recipeA']}/prescrever", http_build_query($post + ['csrf_token' => $cm[1] ?? '', 'servings' => 1, 'meal_name' => 'Almoço']), [], $jar);
    aud_check($ps === 403, "critério 5: POST estrangeiro #{$i} recusado ({$ps})");
}
aud_check($count('meal_plans') === $plansBefore && $count('recipe_prescriptions') === $rxBefore, 'critério 5: nenhum plano ou prescrição residual');

echo "V2-01: OK\n";
