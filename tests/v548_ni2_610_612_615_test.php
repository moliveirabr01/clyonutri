<?php
/** NI2-610, NI2-612 e NI2-615 (v54.8): regras de domínio. Dados TESTE. Fora do zip. */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v548.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Booking\BookingCalendarService as Cal;
use ClyoNutri\Domain\Booking\BookingSetupState as S;
use ClyoNutri\Domain\Booking\NationalHolidays as NH;
use ClyoNutri\Domain\Dashboard\PendingWorkSummary as PW;

echo "== NI2-612 estado do agendamento (um aviso por pendência)\n";
$yes = static fn() => true; $no = static fn() => false;
T::ok(S::classify(false, ['working_days' => ['seg']], $no) === S::DISABLED, 'desligado: sem aviso');
T::ok(S::classify(true, ['working_days' => ['seg']], $no) === S::HOURS_UNCONFIRMED, 'conta antiga: horários a confirmar (não "sem horário livre")');
T::ok(S::classify(true, ['weekly_availability' => ['seg' => ['enabled' => false]]], $no) === S::NO_HOURS, 'nenhum dia ativo');
T::ok(S::classify(true, ['weekly_availability' => ['seg' => ['enabled' => true]]], $no) === S::NO_SLOTS, 'dias ativos e sem horário livre em 14 dias');
T::ok(S::classify(true, ['weekly_availability' => ['seg' => ['enabled' => true]]], $yes) === S::OK && S::message(S::OK) === null, 'tudo certo: sem aviso');
T::ok(S::actionLabel(S::HOURS_UNCONFIRMED) === 'Ativar agendamento online', 'ação "Ativar agendamento online"');

echo "== NI2-615 feriados\n";
T::ok(NH::easter(2026)->format('Y-m-d') === '2026-04-05' && NH::easter(2027)->format('Y-m-d') === '2027-03-28', 'Páscoa 2026 e 2027');
$h26 = array_column(NH::forYear(2026), 'name', 'date');
T::ok(count($h26) === 10 && ($h26['2026-04-03'] ?? '') === 'Sexta-feira da Paixão' && ($h26['2026-11-20'] ?? '') !== '' && ($h26['2026-11-02'] ?? '') === 'Finados', '2026: 10 feriados, iguais à Portaria MGI nº 11.460/2025');
T::ok(!isset($h26['2026-02-16']) && !isset($h26['2026-06-04']), 'pontos facultativos (Carnaval, Corpus Christi) não entram');
T::ok(Cal::holidayLabel('Feriado nacional', ' Finados ') === 'Feriado nacional: Finados' && Cal::holidayLabel('Feriado municipal', '') === 'Feriado municipal', 'rótulo com nome opcional');
T::ok(Cal::splitHolidayLabel('Feriado estadual: Data Magna') === ['Feriado estadual', 'Data Magna'] && Cal::splitHolidayLabel('Feriado nacional') === ['Feriado nacional', ''], 'rótulo separado para edição');
T::throws(fn() => Cal::holidayLabel('Feriado nacional', 'Paciente 123'), 'nome com números recusado');
T::throws(fn() => Cal::holidayLabel('Feriado qualquer', 'X'), 'tipo fora da lista recusado');

$c = Fx::clinic($pdo, 'Clínica TESTE'); $o = Fx::user($pdo, $c, 'Nutri TESTE', 'owner'); Fx::act($o);
$cal = new Cal($pdo);
[$n, $skip] = $cal->importNationalHolidays($c, null, 2027, ['2027-01-01', '2027-03-26']);
T::ok($n === 2 && $skip === 0, 'importa as datas escolhidas');
[$n, $skip] = $cal->importNationalHolidays($c, null, 2027, ['2027-01-01']);
T::ok($n === 0 && $skip === 1, 'não duplica o mesmo feriado');
T::throws(fn() => $cal->importNationalHolidays($c, null, 2027, ['2027-02-08']), 'data fora da lista oficial é recusada', 'não é feriado nacional');
T::throws(fn() => $cal->importNationalHolidays($c, null, 2027, []), 'sem seleção é recusado');
$lbl = $pdo->query("SELECT label FROM booking_calendar_exceptions WHERE rule_date = '2027-03-26'")->fetchColumn();
T::ok($lbl === 'Feriado nacional: Sexta-feira da Paixão', 'gravado com o nome: ' . $lbl);

echo "== NI2-610 trabalho pela metade\n";
$p = Fx::patient($pdo, $c, 'Paciente TESTE');
$pdo->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, is_draft, created_at) VALUES (?, ?, ?, 1, 1, '2026-10-30 10:00:00')")->execute([$c, $p, $o['professional_id']]);
$pdo->prepare("INSERT INTO onboarding_progress (clinic_id, user_id, current_step, is_completed, practice_preferences, created_at, updated_at) VALUES (?, ?, 5, 1, ?, datetime('now'), datetime('now'))")->execute([$c, $o['user_id'], json_encode(['working_days' => ['seg']])]);
$pdo->prepare('INSERT INTO booking_settings (clinic_id, is_enabled) VALUES (?, 1)')->execute([$c]);
$pw = new PW($pdo);
$items = $pw->items($c, $o['professional_id'], true);
$types = array_column($items, 'type');
T::ok(in_array('draft_anamnesis', $types, true) && in_array('booking_setup', $types, true), 'clínico vê anamnese em rascunho e agendamento pendente');
$an = $items[array_search('draft_anamnesis', $types, true)];
T::ok(str_contains($an['description'], '30/10/2026') && $an['action_label'] === 'Continuar', 'anamnese com data e "Continuar"');
$sec = array_column($pw->items($c, $o['professional_id'], false), 'type');
T::ok($sec === ['booking_setup'], 'perfil não clínico não vê rascunhos clínicos');
$other = Fx::clinic($pdo, 'Clínica TESTE B');
T::ok(array_filter($pw->items($other, 0, true), fn($i) => $i['type'] === 'draft_anamnesis') === [], 'outra clínica não vê o rascunho');
$sorted = PW::sortByPriority([['type' => 'a', 'severity' => 'info'], ['type' => 'b', 'severity' => 'danger'], ['type' => 'c', 'severity' => 'warning'], ['type' => 'd', 'severity' => 'info']]);
T::ok(array_column($sorted, 'type') === ['b', 'c', 'a', 'd'], 'ordem por prioridade, estável');

echo "== NI2-612 seção da aba Agenda\n";
use ClyoNutri\Application\Controllers\SettingsController as SC;
T::ok(SC::agendaSection('link', '') === 'link' && SC::agendaSection('', 'calendar_import') === 'feriados' && SC::agendaSection('', 'calendar_policy') === 'regras' && SC::agendaSection('x', '') === 'horarios', 'seção por parâmetro e por formulário');
T::done();
