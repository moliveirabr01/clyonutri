<?php
// Banco para os testes HTTP/navegador da v54 (dados TESTE). Reproduz a conta auditada: disponibilidade
// só em working_days (formato antigo) e agendamento online ligado. Não vai no zip.
declare(strict_types=1);
require __DIR__ . '/_v54_bootstrap.php';

$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$o = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner');
$ob = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner');
$p = Fx::patient($pdo, $cA, 'Paciente TESTE Antigo');
$pdo->prepare('UPDATE patients SET notes = ? WHERE id = ?')->execute(['Alergia a amendoim (TESTE)', $p]);
$p2 = Fx::patient($pdo, $cA, 'Paciente TESTE Dois');
$now = date('Y-m-d H:i:s');
foreach ([[$cA, $o], [$cB, $ob]] as [$cid, $u]) {
    $pdo->prepare('INSERT INTO onboarding_progress (clinic_id, user_id, current_step, is_completed, practice_preferences, created_at, updated_at) VALUES (?, ?, 5, 1, ?, ?, ?)')
        ->execute([$cid, $u['user_id'], json_encode(['working_days' => ['seg', 'ter', 'qua', 'qui', 'sex'], 'default_duration_minutes' => 60]), $now, $now]);
    $pdo->prepare('INSERT INTO booking_settings (clinic_id, is_enabled, allow_presential, allow_telehealth) VALUES (?, 1, 1, 1)')->execute([$cid]);
}
Fx::act($o);
// Alimentos TESTE
$food = static function (string $name, float $kcal, float $p, float $c, float $l) use ($pdo): int {
    $pdo->prepare("INSERT INTO foods (name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom, is_current) VALUES (?, 'TESTE', 'TACO', ?, ?, ?, ?, 1, 100, 'g', 0, 1)")->execute([$name, $kcal, $p, $c, $l]);
    return (int)$pdo->lastInsertId();
};
$fPeanut = $food('Amendoim, grão, cru', 544, 27.2, 20.3, 43.9);
$fBread = $food('Pão, trigo, francês', 300, 8, 58.6, 3.1);
// Plano rascunho com 1 refeição e meta, como na auditoria (259 de 1.724 kcal)
$ms = new \ClyoNutri\Domain\MealPlan\MealPlanService($pdo);
$plan = $ms->createPlan($p2, $o['professional_id'], 'TESTE Plano Fase 1');
$planId = (int)($plan['plan_id'] ?? $plan['id']);
$meal = $ms->addMeal($planId, 'Café da Manhã', '07:30');
$ms->addItem($meal, $fBread, 50, 'g');
(new \ClyoNutri\Domain\MealPlan\EnergyTargetService($pdo))->save($planId, ['formula' => 'mifflin_st_jeor', 'sex' => 'female', 'age_years' => '36', 'weight_kg' => '68', 'height_cm' => '165', 'activity_factor' => '1.55', 'goal_type' => 'loss', 'adjust_mode' => 'kcal', 'adjust_value' => '-400', 'macro_mode' => 'percent', 'protein' => '25', 'carbohydrate' => '50', 'lipid' => '25']);
// Plano vazio (cabeçalho UX-017)
$empty = $ms->createPlan($p2, $o['professional_id'], 'TESTE Plano Vazio');
$emptyId = (int)($empty['plan_id'] ?? $empty['id']);
// Solicitação de exames emitida (laudos) e rascunho (emitir direto)
$ers = new \ClyoNutri\Domain\LaboratoryExam\ExamRequestService($pdo);
$r = $ers->saveDraft($p2, $o['user_id'], ['exam_ids' => [Fx::examId($pdo, 'GLICEMIA_JEJUM'), Fx::examId($pdo, 'FERRITINA_SERICA')], 'clinical_justification' => 'TESTE: avaliar glicemia e ferro para ajustar o plano.']);
$ers->submitForReview((int)$r['id'], $o['user_id']); $ers->issue((int)$r['id'], $o['user_id'], true);
$draft = $ers->saveDraft($p2, $o['user_id'], ['exam_ids' => [Fx::examId($pdo, 'GLICEMIA_JEJUM')], 'clinical_justification' => 'TESTE: avaliar glicemia para ajustar o plano.']);
// Consulta amanhã para o paciente 2 (sugestão de vínculo no exame)
$pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (?, ?, ?, ?, 60, 'scheduled', 'follow_up')")->execute([$cA, $p2, $o['professional_id'], date('Y-m-d', strtotime('+2 days')) . ' 10:00:00']);
$apptId = (int)$pdo->lastInsertId();
echo json_encode(['plan' => $planId, 'emptyPlan' => $emptyId, 'foodPeanut' => $fPeanut, 'foodBread' => $fBread, 'request' => (int)$r['id'], 'items' => array_map(fn($i) => (int)$i['id'], $r['items']), 'draftRequest' => (int)$draft['id'], 'appointment' => $apptId] + ['clinicA' => $cA, 'clinicB' => $cB, 'owner' => $o['user_id'], 'prof' => $o['professional_id'], 'ownerB' => $ob['user_id'], 'profB' => $ob['professional_id'], 'patient' => $p, 'patient2' => $p2]);
