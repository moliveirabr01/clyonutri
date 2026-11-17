<?php
declare(strict_types=1);
putenv('V54_DB=' . sys_get_temp_dir() . '/ni2-889-' . getmypid() . '.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\View\UserText;
use ClyoNutri\Application\Api\PatientApiController;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\MealPlan\PlanTitle;
use ClyoNutri\Domain\Dashboard\DashboardOverview;

$c = Fx::clinic($pdo, 'TESTE 889');
$u = Fx::user($pdo, $c, 'Nutri TESTE 889', 'owner');
$p = Fx::patient($pdo, $c, 'Paciente TESTE 889');
Fx::act($u);
$svc = new MealPlanService($pdo);
$plan = $svc->createPlan($p, $u['professional_id'], 'Plano pessoal');
$id = (int)$plan['plan_id'];
$copy = $svc->duplicatePlan($id);
$row = $svc->getPlanWithDetails((int)$copy['plan_id']);
T::ok($row['title'] === 'Plano pessoal' && (int)$row['version'] === 2 && (int)$row['parent_plan_id'] === $id, 'Duplicação persiste título, versão separada e origem');
T::ok($svc->getPlanWithDetails($id)['title'] === 'Plano pessoal', 'Origem preservada');
foreach ([0 => 'Nenhuma confirmada', 1 => '1 confirmada', 2 => '2 confirmadas'] as $n => $expected) {
    T::ok(UserText::count($n, '{n} confirmada', '{n} confirmadas', 'Nenhuma confirmada') === $expected, "Painel: $n confirmadas");
    T::ok(UserText::count($n, '{n} realizada', '{n} realizadas', 'Nenhuma realizada') === [0 => 'Nenhuma realizada', 1 => '1 realizada', 2 => '2 realizadas'][$n], "Painel: $n realizadas");
}
T::ok(DashboardOverview::compareCount(1, null, 'ontem')['label'] === 'Sem dados do período anterior para comparar', 'Comparação sem período anterior');
T::ok(PlanTitle::forPatient(' [Cópia] [cópia] Plano pessoal') === 'Plano pessoal', 'Remove prefixos legados repetidos');
T::ok(PlanTitle::forPatient('[Cópia]') === 'Plano alimentar', 'Título legado vazio recebe nome legível');
$pdo->prepare('UPDATE meal_plans SET title = ? WHERE id = ?')->execute(['[Cópia] Plano pessoal', $id]);
T::throws(fn() => $svc->publishPlan($id), 'Publicação legada exige confirmação', 'Confirme o título');
T::throws(fn() => $svc->publishPlan($id, false, false, null, false, null, false, '[Cópia] Outro'), 'Prefixo ainda presente é recusado', 'Confirme o título');
T::throws(fn() => $svc->publishPlan($id, false, false, null, false, null, false, 'Título confirmado'), 'Demais validações seguem ativas após confirmar', 'sem nenhuma refeição');
T::ok($svc->getPlanWithDetails($id)['title'] === '[Cópia] Plano pessoal' && $svc->getPlanWithDetails($id)['status'] === 'draft', 'Falha desfaz alteração do título e publicação');
// Published legacy data: API live fallback and immutable snapshot both normalize output only.
$pdo->prepare("UPDATE meal_plans SET status='published' WHERE id=?")->execute([$id]);
$api = new PatientApiController($pdo);
T::ok($api->getDailyMealPlan($p)['title'] === 'Plano pessoal', 'API: plano legado sem snapshot tem título limpo');
$payload = json_encode(['id'=>$id,'title'=>'[Cópia] [Cópia] Plano do snapshot','meals'=>[]]);
$pdo->prepare("INSERT INTO meal_plan_versions (clinic_id, meal_plan_id, patient_id, version_number, title, daily_energy_kcal, payload_json, professional_id, published_at, status) VALUES (?, ?, ?, 1, ?, 0, ?, ?, CURRENT_TIMESTAMP, 'published')")->execute([$c,$id,$p,'[Cópia] Plano do snapshot',$payload,$u['user_id']]);
T::ok($api->getDailyMealPlan($p)['title'] === 'Plano do snapshot', 'API: snapshot legado tem título limpo');
T::ok(str_contains(json_decode((string)$pdo->query('SELECT payload_json FROM meal_plan_versions')->fetchColumn(), true)['title'], '[Cópia]'), 'Snapshot histórico permanece imutável');
$c2=Fx::clinic($pdo, 'TESTE outra clínica');
$u2=Fx::user($pdo,$c2,'Outro Nutri','owner');
Fx::act($u2);
T::throws(fn()=>$api->getDailyMealPlan($p), 'API bloqueia paciente de outra clínica');
T::throws(fn()=>$svc->duplicatePlan($id), 'Duplicação bloqueia plano de outra clínica');
Fx::act($u);
$legacyDraft = (int)$copy['plan_id'];
$pdo->prepare('UPDATE meal_plans SET title=? WHERE id=?')->execute(['[Cópia] Plano pessoal', $legacyDraft]);
$svc->setPlanMethod($legacyDraft, 'qualitativo');
$pdo->prepare("INSERT INTO meal_plan_meals (meal_plan_id, name, notes, ordering) VALUES (?, 'Café da manhã', 'Comer devagar e incluir frutas.', 1)")->execute([$legacyDraft]);
$published=$svc->publishPlan($legacyDraft, false, true, null, true, 'Plano qualitativo revisado para o paciente.', false, 'Plano revisado');
T::ok($published['version'] === 2 && $svc->getPlanWithDetails($legacyDraft)['title'] === 'Plano revisado', 'Publicação confirmada persiste título e versão 2');
T::ok($api->getDailyMealPlan($p)['title'] === 'Plano revisado', 'Novo snapshot entrega título confirmado ao paciente');
$profile=['timeline'=>[['type'=>'Consulta','title'=>'Consulta','id'=>88,'url'=>'/consulta/88','date'=>date('Y-m-d H:i:s'),'state'=>'Concluída','version'=>1]]];
$p=['id'=>$p]; $_GET=[];
ob_start(); require dirname(__DIR__).'/views/patients/timeline.php'; $html=ob_get_clean();
T::ok(!str_contains($html,'registro #') && str_contains($html,'title="Referência para suporte: 88"'), 'Linha do tempo renderizada preserva ID só na referência de suporte');
T::done();
