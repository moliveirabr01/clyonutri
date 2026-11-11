<?php
/**
 * v54.1 — correções do code review do pacote v54 (NI2-583). SQLite isolado, só dados TESTE. Fora do zip.
 */
declare(strict_types=1);
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Booking\PublicBookingSlug;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Patient\PatientAllergyRecord;
use ClyoNutri\Domain\Patient\PatientRepository;

$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$o = Fx::user($pdo, $cA, 'Fábio TESTE', 'owner');
$ob = Fx::user($pdo, $cB, 'Fábio TESTE', 'owner');
$now = date('Y-m-d H:i:s');
foreach ([[$cA, $o], [$cB, $ob]] as [$cid, $u]) {
    $pdo->prepare('INSERT INTO onboarding_progress (clinic_id, user_id, current_step, is_completed, practice_preferences, created_at, updated_at) VALUES (?, ?, 5, 1, ?, ?, ?)')
        ->execute([$cid, $u['user_id'], json_encode(['working_days' => ['seg', 'ter', 'qua', 'qui', 'sex'], 'default_duration_minutes' => 60]), $now, $now]);
    $pdo->prepare('INSERT INTO booking_settings (clinic_id, is_enabled, allow_presential, allow_telehealth) VALUES (?, 1, 1, 1)')->execute([$cid]);
}
$pdo->prepare("UPDATE clinics SET address_street = 'Rua TESTE', address_number = '10', address_city = 'Recife', address_state = 'PE' WHERE id = ?")->execute([$cA]);
Fx::act($o);

echo "== Migrações da v54.1\n";
$files = array_map('basename', glob(dirname(__DIR__) . '/database/migrations/*.php'));
T::ok(!array_filter($files, fn($f) => str_contains($f, '000074_') || str_contains($f, '000076_')), 'migrações 74 (horários) e 76 (medidas) fora do pacote');
T::ok(in_array('2026_11_02_000077_add_booking_slug_and_public_address.php', $files, true), 'migração 77 presente');
T::ok(\ClyoNutri\Core\Database\SchemaInfo::hasColumn($pdo, 'professionals', 'booking_slug'), 'coluna professionals.booking_slug');

echo "== B2 alergia: cadastro, fonte única e observações\n";
$repo = new PatientRepository($pdo);
$p1 = $repo->create(['name' => 'Paciente TESTE Nega', 'allergies' => 'amendoim', 'allergy_assessment' => 'denied']);
T::ok($p1['allergies'] === null && $p1['allergy_assessment'] === 'denied', 'cadastro com "Nega alergias" não grava texto de alergia junto');
$p2 = $repo->create(['name' => 'Paciente TESTE Amendoim', 'allergies' => 'Amendoim']);
T::ok($p2['allergy_assessment'] === 'recorded', 'texto de alergia sem avaliação vira "recorded"');
$an = new AnamnesisService($pdo);
$an->saveAnamnesis((int)$p1['id'], $o['professional_id'], ['allergy_assessment' => 'recorded', 'allergies' => 'Amendoim', 'chief_complaint' => 'TESTE']);
$sum = $an->getClinicalSummaryForPrescription((int)$p1['id']);
T::ok($sum['allergy_state'] === 'recorded' && str_contains((string)$sum['allergies'], 'Amendoim'), 'anamnese posterior com amendoim vence o "Nega alergias" do cadastro (selo usa o resumo)');
$val = new AllergenSafetyValidator($pdo);
T::ok(in_array('amendoim', $val->getPatientAllergens((int)$p1['id']), true), 'trava de alérgeno vê amendoim da anamnese');
$sum2 = $an->getClinicalSummaryForPrescription((int)$p2['id']);
T::ok($sum2['allergy_state'] === 'recorded' && str_contains($sum2['allergy_label'], 'Cadastro'), 'sem anamnese, alergia do cadastro aparece com origem "Cadastro"');
$p3 = Fx::patient($pdo, $cA, 'Paciente TESTE Observação');
$pdo->prepare('UPDATE patients SET notes = ? WHERE id = ?')->execute(['Nega alergias. Gosta de frutas.', $p3]);
T::ok($val->getPatientAllergens($p3) === [], 'observação "Nega alergias" não vira alergia');
$st = $val->evaluatePatientAllergyState($p3);
T::ok($st['state'] === 'not_evaluated', 'paciente só com observação continua "não avaliado" (sem conflito em todo alimento)');
T::ok(PatientAllergyRecord::read($pdo, $cA, $p3)['notes_mention_allergy'] === true, 'observação que cita alergia gera só o aviso "confira"');
$pdo->prepare('UPDATE patients SET notes = ? WHERE id = ?')->execute(['Não tem alergia a amendoim.', $p3]);
T::ok($val->getPatientAllergens($p3) === [], '"não tem alergia a amendoim" nas observações não vira conflito de amendoim');

echo "== B4 link público\n";
$slugSvc = new PublicBookingSlug($pdo);
$sA = $slugSvc->ensure($o['professional_id'], 'Fábio TESTE');
$sB = $slugSvc->ensure($ob['professional_id'], 'Fábio TESTE');
T::ok($sA !== null && $sB !== null && $sA !== $sB, 'mesmo nome em clínicas diferentes: apelidos diferentes');
T::ok(str_starts_with((string)$sA, 'fabio-teste-'), 'acento removido ("Fábio" vira "fabio"): ' . $sA);
T::ok($slugSvc->ensure($o['professional_id'], 'Outro nome') === $sA, 'apelido é estável (não muda se o nome mudar)');
$bs = new BookingService($pdo);
$pa = $bs->publicProfessional((string)$sA);
$pb = $bs->publicProfessional((string)$sB);
T::ok($pa && $pa['id'] === $o['professional_id'] && $pb && $pb['id'] === $ob['professional_id'], 'cada apelido abre o próprio profissional');
T::ok($bs->publicProfessional('fabio-teste') === null, 'só o nome não abre nenhum profissional');
T::ok($bs->publicProfessional('nutri-' . $o['professional_id']) === null, '"nutri-{id}" não abre mais');
T::ok($pa['clinic_address'] === null, 'endereço não aparece por padrão');
T::ok($pa['default_price'] === null, 'sem valor salvo, sem "R$ 250,00" inventado');
$bs->updateSettings($cA, ['public_show_address' => true]);
T::ok(str_contains((string)$bs->publicProfessional((string)$sA)['clinic_address'], 'Rua TESTE'), 'com a opção ligada, endereço aparece');
T::ok($bs->getSettings($cA)['patient_show_address'] === true, 'opção do cartão da consulta não foi alterada');

echo "== UX-001 horários antigos sem confirmação\n";
$before = $pdo->query('SELECT practice_preferences FROM onboarding_progress WHERE clinic_id = ' . $cA)->fetchColumn();
$nextMon = date('Y-m-d', strtotime('next monday'));
T::ok($bs->getAvailableSlots($cA, $o['professional_id'], $nextMon, 60, 'presential') === [], 'conta só com dias marcados: link público sem horários até confirmar');
T::ok($bs->hasAvailableSlotsInNextDays($cA, $o['professional_id'], 14) === false, 'aviso de "sem horários" continua correto');
$aps = new AppointmentService($pdo);
$aps->practicePreferences($o['professional_id']);
T::ok($pdo->query('SELECT practice_preferences FROM onboarding_progress WHERE clinic_id = ' . $cA)->fetchColumn() === $before, 'ler preferências não grava 08–18 no banco');
$pat = Fx::patient($pdo, $cA, 'Paciente TESTE Agenda');
$late = $aps->schedule($pat, $o['professional_id'], $nextMon . ' 19:00:00', 'follow_up', 60, null, false);
T::ok(!empty($late['id']), 'agenda interna da conta antiga continua aceitando 19:00 (sem trava nova)');

echo "== Regressões da v53 restauradas\n";
$ev = $pdo->prepare("SELECT COUNT(*) FROM notification_events WHERE clinic_id = ?");
$ev->execute([$cA]);
T::ok((int)$ev->fetchColumn() >= 1, 'agendar pela agenda volta a pôr a confirmação na fila (AppointmentConfirmationPublisher)');
T::ok(!method_exists(\ClyoNutri\Domain\LaboratoryExam\ExamRequestService::class, 'issueRequest'), 'issueRequest (código morto) removido');
$notifier = file_get_contents(dirname(__DIR__) . '/src/Core/Auth/ProfessionalSecurityNotifier.php');
T::ok(!str_contains($notifier, 'ClyoNutri foi') && !str_contains($notifier, '/verificar-email/'), 'e-mails com "Clyo Nutri" e sem link para rota inexistente');
$dash = file_get_contents(dirname(__DIR__) . '/src/Application/Controllers/DashboardController.php');
T::ok(str_contains($dash, 'confirme os parâmetros registrados'), 'aviso clínico das dobras restaurado');
// v54.25 (NI2-220 I9): a marcação da anamnese saiu do controlador para views/anamnesis/form_page.php.
T::ok(str_contains($dash . file_get_contents(dirname(__DIR__) . '/views/anamnesis/form_page.php'), 'mesma versão nos dois lados'), 'aviso do comparador de anamnese restaurado');
T::ok(str_contains($dash, "isset(\$_GET['availability_preview'])"), 'prévia de disponibilidade da agenda restaurada');
T::ok(!preg_match('/<script>\s*\(function/', $dash), 'sem <script> em linha sem nonce na agenda');
T::ok(!preg_match('/\son(change|click|input)="/', $dash . file_get_contents(dirname(__DIR__) . '/views/patients/edit.php')), 'sem handler em linha (onchange/onclick)');

echo "== UX-004 publicação\n";
$ms = new MealPlanService($pdo);
$p4 = Fx::patient($pdo, $cA, 'Paciente TESTE Plano');
$pdo->prepare("INSERT INTO foods (name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom, is_current) VALUES ('Pão TESTE', 'TESTE', 'TACO', 300, 8, 58.6, 3.1, 1, 100, 'g', 0, 1)")->execute();
$bread = (int)$pdo->lastInsertId();
$plan = $ms->createPlan($p4, $o['professional_id'], 'TESTE Plano');
$planId = (int)($plan['plan_id'] ?? $plan['id']);
$ms->addItem($ms->addMeal($planId, 'Café', '07:30'), $bread, 50, 'g');
(new \ClyoNutri\Domain\MealPlan\EnergyTargetService($pdo))->save($planId, ['formula' => 'mifflin_st_jeor', 'sex' => 'female', 'age_years' => '36', 'weight_kg' => '68', 'height_cm' => '165', 'activity_factor' => '1.55', 'goal_type' => 'loss', 'adjust_mode' => 'kcal', 'adjust_value' => '-400', 'macro_mode' => 'percent', 'protein' => '25', 'carbohydrate' => '50', 'lipid' => '25']);
T::throws(fn() => $ms->publishPlan($planId), 'plano com 1 refeição e 9% da meta não publica sem motivo', 'abaixo da meta');
T::throws(fn() => $ms->publishPlan($planId, false, false, null, true, 'curto'), 'motivo curto recusado', 'mínimo de 10');
$res = $ms->publishPlan($planId, false, true, null, true, 'TESTE: transição, completar no retorno.');
T::ok(!empty($res), 'com motivo, publica');
$snap = $pdo->query("SELECT payload_json FROM meal_plan_versions WHERE meal_plan_id = {$planId} ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok(is_string($snap) && str_contains($snap, 'TESTE: transição'), 'motivo gravado na versão publicada');

T::done();
