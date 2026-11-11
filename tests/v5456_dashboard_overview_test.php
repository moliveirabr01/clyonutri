<?php
/**
 * v54.56 — Painel geral no estilo Talui: saudação/data, comparação com período anterior (neutra sem base),
 * agenda da semana sem canceladas e isolada por clínica, novos pacientes e adesão do período anterior.
 * Banco SQLite de TESTE; nenhuma conta real.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5456.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Dashboard\DashboardOverview as O;

$db = Connection::getInstance();
$tz = new DateTimeZone('America/Sao_Paulo');
$at = static fn(string $s) => new DateTimeImmutable($s, $tz);

fwrite(STDOUT, "=== Saudação e data (fuso do consultório) ===\n");
T::ok((new O($db, $at('2026-11-10 09:15')))->greeting() === 'Bom dia', '09:15 → Bom dia');
T::ok((new O($db, $at('2026-11-10 12:00')))->greeting() === 'Boa tarde', '12:00 → Boa tarde');
T::ok((new O($db, $at('2026-11-10 17:59')))->greeting() === 'Boa tarde', '17:59 → Boa tarde');
T::ok((new O($db, $at('2026-11-10 18:00')))->greeting() === 'Boa noite', '18:00 → Boa noite');
T::ok((new O($db, new DateTimeImmutable('2026-11-10 23:30:00', new DateTimeZone('UTC'))))->greeting() === 'Boa noite', '23:30 UTC = 20:30 em São Paulo → Boa noite (não usa o fuso do servidor)');
T::ok((new O($db, new DateTimeImmutable('2026-11-10 13:30:00', new DateTimeZone('UTC'))))->greeting() === 'Bom dia', '13:30 UTC = 10:30 em São Paulo → Bom dia');
T::ok((new O($db))->dateLabel('2026-11-10') === 'Terça-feira, 10 de novembro de 2026', 'data por extenso em pt-BR');
T::ok((new O($db))->dateLabel('2026-03-01') === 'Domingo, 1 de março de 2026', 'domingo e março com acento');
T::ok(O::firstName('MAICON FERREIRA DE OLIVEIRA') === 'Maicon', 'primeiro nome em caixa de título');
T::ok(O::firstName('  ') === '' && O::firstName(null) === '', 'sem nome: sem vocativo');
T::ok(O::firstName('ÁGATA souza') === 'Ágata', 'acento preservado');

fwrite(STDOUT, "=== Comparação (neutra sem base; nunca inventa valor) ===\n");
$c = O::compareCount(3, 1, 'vs. ontem'); T::ok($c['tone'] === 'up' && $c['label'] === '↑ 2 vs. ontem' && str_contains($c['sr'], '2 a mais'), 'aumento: ↑ 2 vs. ontem');
$c = O::compareCount(1, 4, 'vs. ontem'); T::ok($c['tone'] === 'down' && $c['label'] === '↓ 3 vs. ontem' && str_contains($c['sr'], '3 a menos'), 'queda: ↓ 3 vs. ontem');
$c = O::compareCount(2, 2, 'vs. ontem'); T::ok($c['tone'] === 'neutral' && $c['label'] === 'sem variação', 'igual: sem variação');
$c = O::compareCount(0, 0, 'vs. ontem'); T::ok($c['tone'] === 'neutral' && $c['label'] === 'sem variação', 'zero e zero: sem variação (sem seta)');
$c = O::compareCount(5, 0, 'vs. ontem'); T::ok($c['tone'] === 'neutral' && $c['label'] === 'sem base anterior', 'anterior 0: sem base anterior (não mostra +∞ nem %)');
$c = O::compareCount(5, null, 'vs. ontem'); T::ok($c['tone'] === 'neutral' && $c['label'] === 'sem base anterior', 'anterior desconhecido: sem base anterior');
$c = O::comparePoints(62.5, 58.3, 'vs. 30 dias anteriores'); T::ok($c['tone'] === 'up' && $c['label'] === '↑ 4,2 p.p. vs. 30 dias anteriores', 'adesão em p.p. com vírgula');
$c = O::comparePoints(50.0, 61.0, 'vs. 30 dias anteriores'); T::ok($c['tone'] === 'down' && str_starts_with($c['label'], '↓ 11,0 p.p.'), 'adesão em queda');
$c = O::comparePoints(58.3, null, 'x'); T::ok($c['tone'] === 'neutral' && $c['label'] === 'sem base anterior', 'adesão sem período anterior: neutro');
$c = O::comparePoints(null, 40.0, 'x'); T::ok($c['tone'] === 'neutral' && $c['label'] === 'sem base anterior', 'adesão sem dado atual: neutro');
$c = O::comparePoints(58.31, 58.29, 'x'); T::ok($c['label'] === 'sem variação', 'diferença < 0,05 p.p.: sem variação');

fwrite(STDOUT, "=== Agenda da semana: dados reais, sem canceladas, isolada por clínica ===\n");
$cA = Fx::clinic($db, 'Clínica TESTE Painel A');
$cB = Fx::clinic($db, 'Clínica TESTE Painel B');
$nA = Fx::user($db, $cA, 'Nutri TESTE Painel A', 'owner');
$nB = Fx::user($db, $cB, 'Nutri TESTE Painel B', 'owner');
$pA1 = Fx::patient($db, $cA, 'Paciente TESTE Ana');
$pA2 = Fx::patient($db, $cA, 'Paciente TESTE Bruno');
$pB1 = Fx::patient($db, $cB, 'Paciente TESTE Clínica B');
$ins = $db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, status, type) VALUES (?, ?, ?, ?, ?, 'follow_up')");
// Semana de 09/11/2026 (segunda) a 15/11/2026 (domingo); hoje = terça 10/11.
$ins->execute([$cA, $pA1, $nA['professional_id'], '2026-11-09 09:00:00', 'completed']);
foreach (['08:00', '09:00', '10:00', '11:00', '14:00', '15:00'] as $h) $ins->execute([$cA, $pA2, $nA['professional_id'], "2026-11-10 $h:00", 'scheduled']);
$ins->execute([$cA, $pA1, $nA['professional_id'], '2026-11-10 16:00:00', 'cancelled']);
$ins->execute([$cA, $pA1, $nA['professional_id'], '2026-11-15 10:00:00', 'confirmed']); // domingo com consulta
$ins->execute([$cA, $pA1, $nA['professional_id'], '2026-11-16 00:00:00', 'scheduled']); // segunda seguinte (fora)
$ins->execute([$cA, $pA1, $nA['professional_id'], '2026-11-08 23:59:59', 'scheduled']); // domingo anterior (fora)
$ins->execute([$cB, $pB1, $nB['professional_id'], '2026-11-10 09:30:00', 'scheduled']); // outra clínica
$ins->execute([$cA, $pB1, $nA['professional_id'], '2026-11-11 09:30:00', 'scheduled']); // paciente de outra clínica (dado inconsistente): não aparece
$o = new O($db, $at('2026-11-10 20:30'));
$w = $o->weekAgenda($cA, '2026-11-10');
$dates = array_column($w['days'], 'date');
T::ok($w['start'] === '2026-11-09' && $w['end'] === '2026-11-15', 'semana de segunda a domingo');
T::ok($dates === ['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-15'], 'sábado vazio some; domingo com consulta aparece: ' . implode(',', $dates));
T::ok($w['total'] === 8, 'total = 8 (sem cancelada, sem outra clínica, sem limites da semana): ' . $w['total']);
$tue = $w['days'][1];
T::ok($tue['is_today'] && $tue['count'] === 6 && count($tue['items']) === O::WEEK_DAY_VISIBLE_LIMIT && $tue['hidden'] === 2, 'hoje: 6 consultas, 4 visíveis, "+2 neste dia"');
T::ok(!in_array('cancelled', array_column($tue['items'], 'status'), true), 'cancelada fora da agenda');
T::ok($w['days'][0]['is_past'] && !$w['days'][0]['is_today'], 'segunda marcada como passada');
T::ok($w['days'][2]['count'] === 0, 'paciente de outra clínica não aparece na quarta (JOIN por clínica)');
$names = []; foreach ($w['days'] as $d) foreach ($d['items'] as $it) $names[] = $it['patient_name'];
T::ok(!in_array('Paciente TESTE Clínica B', $names, true), 'nenhum nome da clínica B na agenda da clínica A');
$wB = $o->weekAgenda($cB, '2026-11-10');
T::ok($wB['total'] === 1 && $wB['days'][1]['items'][0]['patient_name'] === 'Paciente TESTE Clínica B', 'clínica B vê só a própria consulta');
T::ok($w['label'] === '09 – 15 nov', 'rótulo da semana: ' . $w['label']);
$wEdge = $o->weekAgenda($cA, '2026-12-01');
T::ok($wEdge['label'] === '30 nov – 06 dez', 'semana que cruza o mês: ' . $wEdge['label']);
T::ok($wEdge['total'] === 0 && count($wEdge['days']) === 5, 'semana sem consulta: 5 dias úteis vazios (estado vazio real)');
Fx::act($nA);
$svc = new AppointmentService($db);
T::ok($svc->getWeekStats('2026-11-10')['total'] === 9, 'card da semana (getWeekStats) conta 9: inclui a consulta com paciente inconsistente');
T::ok($svc->getWeekStats('2026-11-03')['total'] === 1, 'semana anterior: 1 consulta (domingo 08/11), base real da comparação');
$st = $svc->getTodayStats('2026-11-09');
T::ok($st['total_today'] === 1, 'ontem: 1 consulta, base real da comparação "vs. ontem"');

fwrite(STDOUT, "=== Novos pacientes e adesão do período anterior ===\n");
$now = $at('2026-11-10 20:30');
$o2 = new O($db, $now);
$db->exec("UPDATE patients SET created_at = '2020-01-01 00:00:00'");
$mk = function (int $clinic, string $name, string $created) use ($db): void {
    $db->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status, created_at) VALUES (?, ?, ?, '1990-01-01', 'F', 'active', ?)")
        ->execute([$clinic, bin2hex(random_bytes(8)), $name, $created]);
};
$mk($cA, 'Novo TESTE 1', '2026-11-05 10:00:00');
$mk($cA, 'Novo TESTE 2', '2026-10-20 10:00:00');
$mk($cA, 'Anterior TESTE 1', '2026-10-01 10:00:00');
$mk($cB, 'Novo TESTE B', '2026-11-06 10:00:00');
$np = $o2->newPatients($cA, 30);
T::ok($np === ['current' => 2, 'previous' => 1], 'clínica A: 2 novos nos últimos 30 dias, 1 nos 30 anteriores: ' . json_encode($np));
T::ok($o2->newPatients($cB, 30) === ['current' => 1, 'previous' => 0], 'clínica B isolada (1 novo, sem base anterior)');
$ck = $db->prepare('INSERT INTO checkins (clinic_id, patient_id, meal_adherence_pct, checkin_date) VALUES (?, ?, ?, ?)');
T::ok($o2->previousAdherence($cA, 30) === null, 'sem check-in no período anterior: null (selo neutro)');
$ck->execute([$cA, $pA1, 60, date('Y-m-d', strtotime('-40 days'))]);
$ck->execute([$cA, $pA2, 70, date('Y-m-d', strtotime('-45 days'))]);
$ck->execute([$cA, $pA1, 90, date('Y-m-d', strtotime('-5 days'))]);   // período atual: não entra
$ck->execute([$cA, $pA1, 10, date('Y-m-d', strtotime('-70 days'))]);  // fora das duas janelas
$ck->execute([$cB, $pB1, 0, date('Y-m-d', strtotime('-40 days'))]);   // outra clínica
$ck->execute([$cA, $pA2, null, date('Y-m-d', strtotime('-41 days'))]); // sem resposta de adesão
T::ok($o2->previousAdherence($cA, 30) === 65.0, 'adesão anterior da clínica A = 65,0 (só a janela [-60, -30), só a clínica A)');
T::ok($o2->previousAdherence($cB, 30) === 0.0, 'clínica B: 0,0 é dado real (não confunde com "sem dado")');
$cur = (new \ClyoNutri\Domain\Engagement\EngagementService($db))->getClinicAdherenceMetrics($cA, 30);
T::ok($cur['has_data'] && (float)$cur['average_pct'] === 90.0, 'adesão atual (serviço existente) = 90,0');
$cmp = O::comparePoints((float)$cur['average_pct'], $o2->previousAdherence($cA, 30), 'vs. 30 dias anteriores');
T::ok($cmp['label'] === '↑ 25,0 p.p. vs. 30 dias anteriores', 'selo do card de adesão: ' . $cmp['label']);

fwrite(STDOUT, "=== Contrato da tela (fonte) ===\n");
$src = (string)file_get_contents(dirname(__DIR__) . '/src/Application/Controllers/DashboardController.php');
foreach (['attention-panel', 'attention-count', 'attention-item', 'attention-more', 'attention-see-all', 'appointments-today-count', 'appointments-week-count', 'active-patients-count', 'average-adherence-value', 'draft-patients-count', 'draft-plans-total', 'quick-new-patient', 'practice-readiness', 'activation-banner', 'onboarding-banner', 'dashboard-trial-expired'] as $tid) {
    T::ok(str_contains($src, 'data-testid="' . $tid . '"'), "data-testid $tid preservado");
}
T::ok(str_contains($src, 'private const DASHBOARD_ATTENTION_LIMIT = 4;'), 'lista acionável mostra 4 itens');
T::ok(!preg_match('/(Dra\.|Dr\.)\s+[A-Z][a-z]+\s+[A-Z]/', substr($src, (int)strpos($src, 'dashboard-v5456'), (int)strpos($src, 'public function patients()') - (int)strpos($src, 'dashboard-v5456'))), 'nenhum nome fixo na nova tela');
$css = (string)file_get_contents(dirname(__DIR__) . '/public/assets/css/dashboard-v54-56.css');
T::ok(!preg_match('/#[0-9a-fA-F]{3,8}\b/', (string)preg_replace('#/\*.*?\*/#s', '', $css)), 'CSS novo sem cor hex (só tokens da marca)');
$shell = (string)file_get_contents(dirname(__DIR__) . '/views/layouts/professional-shell.php');
T::ok(str_contains($shell, 'dashboard-v54-56.css?v=v54-56') && str_contains($shell, '$dashboardV5456Styles'), 'CSS do painel só carregado no Painel geral');
T::done();
