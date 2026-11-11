<?php
/** NI2-624: alergia histórica contraditória exige revisão e continua protegendo. Dados TESTE, duas clínicas. Fora do zip. */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5413b.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Clinical\AllergenSafetyValidator as V;
use ClyoNutri\Domain\Clinical\AllergyHistoryReview as R;

$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$u = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner'); $uB = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner');
Fx::act($u);
$an = static function (int $p, int $v, ?string $allergies, string $assessment, int $draft = 0) use ($pdo, $cA, $u): void {
    $pdo->prepare('INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$cA, $p, $u['professional_id'], $v, $allergies, $assessment, $draft, date('Y-m-d H:i:s', strtotime("2026-10-0$v 10:00"))]);
};
$pdo->exec("INSERT INTO foods (name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom, is_current) VALUES ('Amendoim TESTE, torrado', 'TESTE', 'TACO', 580, 25, 20, 45, 8, 100, 'g', 0, 1)");
$peanut = (int)$pdo->lastInsertId();
$v = new V($pdo);

echo "== contradição entre versões concluídas\n";
$p1 = Fx::patient($pdo, $cA, 'Paciente TESTE Contradição');
$an($p1, 1, 'Amendoim (urticária)', 'recorded');
$an($p1, 2, 'Nega alergias', 'denied');
$s = $v->evaluatePatientAllergyState($p1);
T::ok($s['state'] === V::STATE_CONFIRMED && $s['review_required'] && in_array('amendoim', $s['allergen_groups'], true), 'v1 amendoim + v2 nega: não vira "negado"; amendoim continua e exige revisão');
T::ok(str_contains($s['review_findings'][0]['message'], 'anamnese v1') && str_contains($s['state_label'], 'Exige revisão'), 'mensagem diz qual versão registrou: ' . $s['review_findings'][0]['message']);
$val = $v->validateFoodsForPatient($p1, [$peanut]);
T::ok($val['has_conflict'] && in_array('amendoim', $val['patient_allergens'], true), 'trava de alérgenos bloqueia amendoim no plano');
$sum = (new \ClyoNutri\Domain\Anamnesis\AnamnesisService($pdo))->getClinicalSummaryForPrescription($p1);
T::ok(in_array('ALERGIA_REVISAO', array_column($sum['critical_alerts'], 'type'), true) && $sum['allergy_state'] === 'recorded', 'resumo clínico: alerta "Exige revisão clínica" e estado com alergia');

echo "== alergia trocada (v2 registra outra)\n";
$p2 = Fx::patient($pdo, $cA, 'Paciente TESTE Troca');
$an($p2, 1, 'amendoim', 'recorded'); $an($p2, 2, 'camarão', 'recorded');
$s = $v->evaluatePatientAllergyState($p2);
T::ok($s['review_required'] && count($s['review_findings']) === 1 && $s['review_findings'][0]['group'] === 'amendoim' && in_array('crustaceos', $s['allergen_groups'], true), 'v2 camarão sem amendoim: protege os dois, revisão só do amendoim');

echo "== sem contradição\n";
$p3 = Fx::patient($pdo, $cA, 'Paciente TESTE Mantém');
$an($p3, 1, 'amendoim', 'recorded'); $an($p3, 2, 'amendoim e camarão', 'recorded');
T::ok(!$v->evaluatePatientAllergyState($p3)['review_required'], 'versão nova repete a alergia: sem revisão');
$p4 = Fx::patient($pdo, $cA, 'Paciente TESTE Só cadastro');
$pdo->prepare("UPDATE patients SET allergies = 'leite' WHERE id = ?")->execute([$p4]);
T::ok(!$v->evaluatePatientAllergyState($p4)['review_required'] && in_array('lactose', $v->evaluatePatientAllergyState($p4)['allergen_groups'], true), 'só cadastro, sem anamnese: o cadastro é a fonte, sem revisão');
$p5 = Fx::patient($pdo, $cA, 'Paciente TESTE Antes negava');
$an($p5, 1, 'Nega alergias', 'denied'); $an($p5, 2, 'ovo', 'recorded');
T::ok(!$v->evaluatePatientAllergyState($p5)['review_required'], 'antes negava e agora registra: atualização normal, sem revisão');

echo "== cadastro × anamnese\n";
$p6 = Fx::patient($pdo, $cA, 'Paciente TESTE Cadastro');
$pdo->prepare("UPDATE patients SET allergies = 'leite de vaca' WHERE id = ?")->execute([$p6]);
$an($p6, 1, 'Nega alergias', 'denied');
$s = $v->evaluatePatientAllergyState($p6);
T::ok($s['review_required'] && $s['review_findings'][0]['source'] === 'cadastro' && in_array('lactose', $s['allergen_groups'], true), 'cadastro com leite e anamnese que nega: revisão, lactose protegida');

echo "== rascunho não cria nem resolve contradição\n";
$p7 = Fx::patient($pdo, $cA, 'Paciente TESTE Rascunho');
$an($p7, 1, 'amendoim', 'recorded'); $an($p7, 2, 'Nega alergias', 'denied', 1);
T::ok(!$v->evaluatePatientAllergyState($p7)['review_required'] && in_array('amendoim', $v->evaluatePatientAllergyState($p7)['allergen_groups'], true), 'v2 rascunho que nega: a v1 concluída vale (NI2-605), sem revisão');

echo "== descarte registrado\n";
$rv = new R($pdo);
T::throws(fn() => $rv->dismiss($cA, $p1, 'amendoim', $u['user_id'], 'ok'), 'descartar sem motivo recusado', 'motivo');
$rv->dismiss($cA, $p1, 'amendoim', $u['user_id'], 'Teste de provocação TESTE negativo');
$s = $v->evaluatePatientAllergyState($p1);
T::ok(!$s['review_required'] && $s['state'] === V::STATE_DENIED, 'descartada com motivo: revisão encerrada, volta ao que a vigente diz');
$audit = $pdo->query("SELECT details FROM audit_logs WHERE action = 'ALLERGY_HISTORY_DISMISSED'")->fetchColumn();
$d = json_decode((string)$audit, true);
T::ok($d['group'] === 'amendoim' && $d['through_version'] === 2 && str_contains($d['reason'], 'provocação') && $d['actor_user_id'] === $u['user_id'], 'descarte fica no histórico: grupo, versão, autor, motivo');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM anamneses WHERE patient_id = $p1")->fetchColumn() === 2, 'nenhuma versão apagada ou alterada');
$an($p1, 3, 'amendoim', 'recorded'); $an($p1, 4, 'Nega alergias', 'denied');
T::ok($v->evaluatePatientAllergyState($p1)['review_required'], 'nova versão volta a registrar amendoim e a seguinte nega: revisão reabre');

echo "== multi-clínica\n";
Fx::act($uB);
T::throws(fn() => $v->evaluatePatientAllergyState($p2), 'clínica B não lê o estado do paciente da A');
$dB = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'ALLERGY_HISTORY_DISMISSED'")->fetchColumn();
try { $rv->dismiss($cB, $p2, 'amendoim', $uB['user_id'], 'Tentativa TESTE de outra clínica'); } catch (\Throwable $e) {}
Fx::act($u);
T::ok($v->evaluatePatientAllergyState($p2)['review_required'], 'descarte gravado pela clínica B não vale para a A');
T::done();
