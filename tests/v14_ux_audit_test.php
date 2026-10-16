<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * v14 — auditoria de UX (UX-01..UX-10): um bloco por item, contra o banco de teste (transação revertida).
 */

use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Application\Controllers\FoodSearchController;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\I18n\LabelMap;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Appointment\ConsultationDraftService;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;
use ClyoNutri\Domain\Helpdesk\KnowledgeBaseService;

date_default_timezone_set('America/Sao_Paulo');
$db = Connection::getInstance();
(new \ClyoNutri\Core\Database\Migrator($db, dirname(__DIR__) . '/database/migrations'))->run(); // 040 em banco antigo
$failures = 0;
function check(bool $c, string $m): void { if (!$c) throw new RuntimeException("FALHA: {$m}"); }
function step(string $n, callable $fn): void { global $failures; try { $fn(); echo "[PASS] {$n}\n"; } catch (Throwable $e) { $failures++; echo "[FAIL] {$n} — " . get_class($e) . ': ' . $e->getMessage() . "\n"; } }
function render(callable $fn): string { ob_start(); try { $fn(); } finally { $out = ob_get_clean(); } return (string)$out; }
$root = dirname(__DIR__);
$ctrlSrc = (string)file_get_contents($root . '/src/Application/Controllers/DashboardController.php');

echo "\n=== v14: AUDITORIA DE UX (UX-01..UX-10) ===\n\n";
$db->beginTransaction();
$s = bin2hex(random_bytes(4));
$q = static function (string $sql, array $p = []) use ($db): int { $db->prepare($sql)->execute($p); return (int)$db->lastInsertId(); };
$clinic = $q("INSERT INTO clinics (uuid, name, email) VALUES (?, 'Clínica v14', ?)", ["v14-{$s}", "v14{$s}@ex.test"]);
$u = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Nutri v14', ?, 'x', 'nutritionist', 1, 'active')", ["u14-{$s}", "u14{$s}@ex.test"]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'nutritionist', 'active')", [$clinic, $u]);
$pro = $q("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-3 14', 'CRN-3')", [$u]);
// UX-01: paciente só com nome (sem nascimento, sexo, e-mail)
$patient = $q("INSERT INTO patients (clinic_id, uuid, name) VALUES (?, ?, 'Paciente Só Nome v14')", [$clinic, "p14-{$s}"]);
TenantContext::setClinicId($clinic); TenantContext::setUserId($u);
$_SESSION['clinic_id'] = $clinic; $_SESSION['user_id'] = $u; $_SESSION['user_role'] = 'nutritionist';
$_SESSION['user_name'] = 'Nutri v14'; $_SESSION['clinic_name'] = 'Clínica v14';
$appt = static fn(string $at, string $status = 'scheduled', string $type = 'first_visit') => $q("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (?, ?, ?, ?, 60, ?, ?)", [$clinic, $patient, $pro, $at, $type, $status]);
$ctrl = new DashboardController();

step('UX-01: peso/altura salvam para paciente só com nome, opcionais vazios e vírgula decimal', function () use ($db, $patient, $pro, $u) {
    $form = ['weight_kg' => '72,5', 'height_cm' => '1,68', 'waist_cm' => '', 'hip_cm' => '', 'neck_cm' => '', 'chest_cm' => '', 'arm_cm' => '',
        'thigh_cm' => '', 'calf_cm' => '', 'body_fat_pct' => '', 'muscle_mass_pct' => '', 'notes' => '', 'assessed_at' => '2026-10-15T10:30'];
    $svc = new AnthropometryService($db);
    $r = $svc->recordAssessment($patient, $pro, $form, $u);
    check(empty($r['duplicate']) && (int)$r['id'] > 0, 'avaliação criada');
    $row = $db->query('SELECT weight_kg, height_cm, bmi, waist_cm, whr, body_fat_pct FROM anthropometric_assessments WHERE id = ' . (int)$r['id'])->fetch(PDO::FETCH_ASSOC);
    check(abs((float)$row['weight_kg'] - 72.5) < 0.01 && abs((float)$row['height_cm'] - 168) < 0.01, 'peso 72,5 e altura 1,68 m -> 168 cm');
    check((float)$row['bmi'] > 25 && $row['waist_cm'] === null && $row['whr'] === null && $row['body_fat_pct'] === null, 'IMC calculado; métricas dependentes ficam nulas sem dados');
    // retry idêntico (falha de rede / duplo clique) não duplica
    $again = $svc->recordAssessment($patient, $pro, $form, $u);
    check(!empty($again['duplicate']) && (int)$again['id'] === (int)$r['id'], 'retry devolve a mesma avaliação');
    $n = $db->prepare('SELECT COUNT(*) FROM anthropometric_assessments WHERE patient_id = ?'); $n->execute([$patient]);
    check((int)$n->fetchColumn() === 1, 'sem duplicata');
    try { $svc->recordAssessment($patient, $pro, ['weight_kg' => '', 'height_cm' => '170'], $u); check(false, 'peso vazio deveria falhar'); }
    catch (\ClyoNutri\Domain\Anthropometry\InvalidAnthropometricDataException $e) { check(str_contains($e->getMessage(), 'Informe o peso'), 'erro acionável: ' . $e->getMessage()); }
});

step('UX-02: liberação ao app desmarcada por padrão e recuperação respeita checkbox desmarcado', function () use ($ctrlSrc, $root) {
    check(!str_contains($ctrlSrc, 'name="is_shared" value="1" checked'), 'nenhum checkbox is_shared marcado por padrão');
    $js = (string)file_get_contents($root . '/public/assets/js/clinical-journeys.js');
    check(str_contains($js, "field.checked = name in saved.values &&"), 'checkbox ausente nos valores salvos volta desmarcado');
});

step('UX-03: rascunho por consulta+usuário salva, restaura na tela, detecta conflito e some ao concluir', function () use ($db, $appt, $clinic, $u, $ctrl) {
    $id = $appt('2026-10-15 09:00:00', 'in_progress');
    $d = new ConsultationDraftService($db);
    check($d->get($id, $u, $clinic)['version'] === 0, 'sem rascunho');
    $r1 = $d->save($id, $u, $clinic, 0, ['evolution_summary' => 'Paciente relata melhora <b>intestinal</b>', 'weight_kg' => '70.2']);
    check($r1['status'] === 'saved' && $r1['version'] === 1, 'salvo v1');
    check($d->save($id, $u, $clinic, 0, ['evolution_summary' => 'outra aba'])['status'] === 'conflict', 'versão velha = conflito');
    check($d->save($id, $u, $clinic, 1, ['evolution_summary' => 'Paciente relata melhora <b>intestinal</b>. Meta: 2 L de água.'])['version'] === 2, 'v2');
    $html = render(fn() => $ctrl->consultationRoom(['id' => (string)$id]));
    check(str_contains($html, 'Meta: 2 L de água.') && str_contains($html, '&lt;b&gt;intestinal'), 'texto do rascunho restaurado (escapado) na textarea');
    check(str_contains($html, 'Rascunho recuperado') && str_contains($html, 'consulta-rascunho.js') && str_contains($html, 'data-draft-pause'), 'indicador + script de autosave/proteção de saída');
    $d->discard($id, $u, $clinic);
    check($d->get($id, $u, $clinic)['version'] === 0, 'apagado ao concluir');
    $done = $appt('2026-10-14 09:00:00', 'completed');
    try { $d->save($done, $u, $clinic, 0, ['evolution_summary' => 'x']); check(false, 'concluída não aceita rascunho'); } catch (InvalidArgumentException) {}
});

step('UX-04: reagendar mantém a consulta, registra horário anterior/novo/motivo, valida conflito; artigo de ajuda', function () use ($db, $appt, $ctrl) {
    $a = $appt('2027-03-10 09:00:00', 'confirmed');
    $b = $appt('2027-03-11 14:00:00');
    $svc = new AppointmentService($db);
    $html = render(fn() => $ctrl->rescheduleAppointmentForm(['id' => (string)$a]));
    check(str_contains($html, 'value="2027-03-10T09:00"') && str_contains($html, 'name="origin" value="reagendar"') && str_contains($html, 'action="/agenda/' . $a . '/editar"'), 'formulário pré-preenchido');
    try { $svc->edit($a, ['scheduled_at' => '2027-03-11T14:30', 'duration_minutes' => '60']); check(false, 'conflito esperado'); } catch (AppointmentConflictException) {}
    $u = $svc->edit($a, ['scheduled_at' => '2027-03-12T10:00', 'duration_minutes' => '60', 'reschedule_reason' => 'Paciente pediu']);
    check((int)$u['id'] === $a && $u['status'] === 'confirmed' && str_starts_with((string)$u['scheduled_at'], '2027-03-12 10:00'), 'mesma consulta, status mantido, novo horário');
    $last = end($u['history']);
    check(str_contains((string)$last['notes'], 'reagendada de 10/03/2027 09:00 para 12/03/2027 10:00') && str_contains((string)$last['notes'], 'Motivo: Paciente pediu'), 'trilha: ' . $last['notes']);
    $_GET = ['date' => '2027-03-12']; $ag = render(fn() => $ctrl->agenda()); $_GET = [];
    check(str_contains($ag, 'href="/agenda/' . $a . '/reagendar"'), 'ação Reagendar na linha da agenda');
    (new KnowledgeBaseService($db));
    $n = $db->query("SELECT COUNT(*) FROM helpdesk_articles WHERE slug = 'como-reagendar-uma-consulta'")->fetchColumn();
    check((int)$n === 1, 'artigo de ajuda criado uma vez');
    (new KnowledgeBaseService($db))->ensureRescheduleArticle();
    check((int)$db->query("SELECT COUNT(*) FROM helpdesk_articles WHERE slug = 'como-reagendar-uma-consulta'")->fetchColumn() === 1, 'idempotente');
});

step('UX-05: após conflito, formulário de nova consulta reabre preenchido com horário destacado', function () use ($ctrl, $patient) {
    $_POST = ['patient_id' => (string)$patient, 'scheduled_at' => '2027-03-11T14:30', 'duration_minutes' => '45', 'type' => 'follow_up', 'notes' => 'Trazer exames'];
    $m = new ReflectionMethod($ctrl, 'rememberAgendaForm'); $m->setAccessible(true);
    $m->invoke($ctrl, 'new', 'Conflito de agenda: já existe consulta às 14:00.', true);
    $_POST = []; $_GET = ['date' => '2027-03-11', 'novo' => '1', 'error' => 'conflito'];
    $html = render(fn() => $ctrl->agenda()); $_GET = [];
    check(str_contains($html, 'id="card-novo-agendamento" data-dialog="inline" class="card" style="display: block'), 'formulário aberto');
    check(str_contains($html, 'value="2027-03-11T14:30" aria-invalid="true"') && str_contains($html, 'data-conflict-highlight'), 'horário mantido e destacado');
    check(str_contains($html, 'Trazer exames') && str_contains($html, '<option value="follow_up" selected') && str_contains($html, 'value="45" selected'), 'demais campos mantidos');
    check(preg_match('#<option value="' . $patient . '" selected#', $html) === 1, 'paciente mantido');
    check(!isset($_SESSION['agenda_form_recovery']), 'consumido após exibir');
});

step('UX-06: vazio = "Não registrado"; negação explícita aparece como registrada', function () use ($ctrlSrc, $root) {
    check(LabelMap::recorded(null) === 'Não registrado' && LabelMap::recorded('  ') === 'Não registrado' && LabelMap::recorded('Nega restrições') === 'Nega restrições', 'helper');
    foreach (['Nenhuma relatada', '[Não relatado]', '[Nenhuma relatada]', "'[Não informado]'"] as $old) { check(!str_contains($ctrlSrc, $old), "texto antigo removido: {$old}"); }
    check(!str_contains((string)file_get_contents($root . '/views/patients/medical_record_print.php'), "'Não relatado'"), 'impressão do prontuário');
});

step('UX-07: abrir a consulta não inicia; início antecipado pede confirmação; horários separados', function () use ($db, $appt, $ctrl) {
    $future = date('Y-m-d H:i:s', time() + 3 * 3600);
    $id = $appt($future, 'scheduled');
    $html = render(fn() => $ctrl->consultationRoom(['id' => (string)$id]));
    check(str_contains($html, 'data-testid="consult-start-panel"') && str_contains($html, 'name="confirm_early"') && str_contains($html, 'Início antecipado'), 'painel de início com confirmação de antecipação');
    check($db->query("SELECT status FROM appointments WHERE id = {$id}")->fetchColumn() === 'scheduled', 'GET não mudou o status');
    check(str_contains($html, 'Agendada para') && str_contains($html, 'Não iniciada') && str_contains($html, 'Não concluída'), 'horários separados');
    check(AppointmentService::isEarlyStart($future) && !AppointmentService::isEarlyStart(date('Y-m-d H:i:s', time() + 10 * 60)), 'tolerância de 15 min');
    $svc = new AppointmentService($db);
    $svc->updateStatus($id, 'in_progress', 'teste');
    $t = AppointmentService::executionTimeline($svc->getAppointment($id));
    check($t['started_at'] !== null && abs(strtotime($t['started_at']) - time()) < 120 && $t['scheduled_at'] === $future, 'início real (fuso da aplicação) distinto do agendado');
});

step('UX-08: equivalentes agrupáveis por chave de apresentação; referência TACO x interno', function () {
    $base = ['energy_kcal' => 124, 'protein_g' => 2.6, 'carbohydrate_g' => 25.8, 'lipid_g' => 1.0, 'is_custom' => 0, 'source' => 'TACO'];
    $a = $base + ['id' => 2, 'name' => 'Arroz integral cozido', 'taco_code' => 1];
    $b = $base + ['id' => 301, 'name' => 'Arroz Integral Cozido TACO', 'taco_code' => null];
    $c = ['energy_kcal' => 130] + $base + ['id' => 9301, 'name' => 'Arroz integral cozido', 'taco_code' => null];
    check(FoodSearchController::equivalenceKey($a) === FoodSearchController::equivalenceKey($b), 'mesmo nome normalizado + composição = equivalentes');
    check(FoodSearchController::equivalenceKey($a) !== FoodSearchController::equivalenceKey($c), 'composição diferente não agrupa');
    check(FoodSearchController::reference($a) === 'TACO 4ª ed. nº 1' && str_starts_with(FoodSearchController::reference($b), 'Cadastro interno nº 301'), 'referências');
});

step('UX-09: tipos e números em pt-BR', function () use ($ctrlSrc) {
    foreach (['online', 'teleconsultation', 'video', 'telehealth'] as $t) { check(LabelMap::appointmentType($t) === 'Teleconsulta', $t); }
    check(LabelMap::appointmentType('first_visit') === 'Primeira Consulta' && LabelMap::appointmentType('xyz_interno') === 'Consulta', 'sem código cru');
    check(LabelMap::num(1234.56, 1, 'kg') === '1.234,6 kg' && LabelMap::num(null) === '—', 'num');
    check(preg_match('/number_format\([^;]*?,\s*\d\)\s*\?>/', $ctrlSrc) === 0, 'sem number_format sem separadores pt-BR');
    check(!str_contains($ctrlSrc, "Tipo: <?= htmlspecialchars(\$app['type']) ?>"), 'tipo cru removido da consulta');
});

step('UX-10: gênero com 3 opções únicas; legado mapeado e preservado', function () use ($root) {
    check(count(LabelMap::GENDER_OPTIONS) === 3, '3 opções');
    check(LabelMap::canonicalGender('female') === 'Feminino' && LabelMap::canonicalGender('MALE') === 'Masculino' && LabelMap::canonicalGender('other') === 'Outro' && LabelMap::canonicalGender('') === null, 'mapa legado');
    $patient = ["id" => 1, "name" => "X", "gender" => "female", "email" => null, "phone" => null, "birth_date" => null, "notes" => null, "status" => "active"];
    $errors = []; $csrfToken = 't';
    ob_start(); try { include $root . '/views/patients/edit.php'; } catch (Throwable) {} $html = (string)ob_get_clean();
    check(substr_count($html, '<option value="Feminino"') === 1 && substr_count($html, '>Masculino</option>') === 1 && !str_contains($html, 'value="female"'), 'sem duplicatas');
    check(str_contains($html, '<option value="Feminino" selected'), 'legado female seleciona Feminino');
});

$db->rollBack();
echo "\n" . ($failures === 0 ? "v14 UX: TODOS OS ITENS PASSARAM\n" : "v14 UX: {$failures} FALHA(S)\n");
exit($failures === 0 ? 0 : 1);
