<?php
/**
 * v54.26: item "Consulta" do painel de contexto clínico (NI2-685 o tinha tirado; Maicon pediu de volta).
 * Mesma regra do cabeçalho (em andamento / hoje / próxima) + última consulta concluída; isolamento por clínica
 * e por paciente; sem observações da agenda no painel; agenda indisponível não derruba o painel.
 * Dados TESTE, SQLite descartável. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5426-consulta.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\View\ClinicalContext;
use ClyoNutri\Domain\Appointment\AppointmentService;

$root = dirname(__DIR__);
$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$a = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner', '26001');
$b = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner', '26002');
$p = Fx::patient($pdo, $cA, 'Paciente TESTE Consulta');
$p2 = Fx::patient($pdo, $cA, 'Paciente TESTE Vizinho');
$pB = Fx::patient($pdo, $cB, 'Paciente TESTE Clínica B');
$ins = function (int $clinic, int $patient, int $prof, string $when, string $status, ?string $notes = null) use ($pdo): int {
    $pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, status, type, notes) VALUES (?, ?, ?, ?, ?, 'follow_up', ?)")
        ->execute([$clinic, $patient, $prof, $when, $status, $notes]);
    return (int)$pdo->lastInsertId();
};
$item = function (int $patient): string {
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/pacientes/' . $patient;
    $html = ClinicalContext::render(\ClyoNutri\Core\Database\Connection::getInstance(), $patient);
    return preg_match('#<div class="clinical-context__item" data-testid="context-appointment".*?</dd></div>#s', $html, $m) ? $m[0] : '';
};
Fx::act($a);

echo "== sem agenda\n";
$h = $item($p);
T::ok($h !== '' && str_contains($h, 'data-state="none"') && str_contains($h, 'Nenhuma consulta agendada'), 'sem consulta: item "Consulta" presente e diz "Nenhuma consulta agendada"');

echo "== próxima, hoje e em andamento (mesma regra do cabeçalho)\n";
$svc = new AppointmentService($pdo);
$next = $ins($cA, $p, $a['professional_id'], date('Y-m-d 10:00:00', strtotime('+5 days')), 'scheduled', 'NOTA TESTE PRIVADA da agenda');
$h = $item($p);
T::ok(str_contains($h, 'data-state="upcoming"') && str_contains($h, 'Próxima') && str_contains($h, date('d/m/Y', strtotime('+5 days'))), 'agendada para daqui a 5 dias: "Próxima" com data');
T::ok(!str_contains($h, 'NOTA TESTE PRIVADA'), 'observações da agenda não aparecem no painel');
T::ok($svc->careStepForPatient($p)['state'] === 'upcoming', 'cabeçalho e painel com o mesmo estado (upcoming)');
if ((int)date('H') < 23) {
    $today = $ins($cA, $p, $a['professional_id'], date('Y-m-d 23:30:00'), 'confirmed');
    $h = $item($p);
    T::ok(str_contains($h, 'data-state="start_today"') && str_contains($h, 'Hoje') && str_contains($h, '23:30'), 'confirmada para hoje: "Hoje às 23:30"');
    $pdo->exec("UPDATE appointments SET status='cancelled' WHERE id=$today");
}
$cur = $ins($cA, $p, $a['professional_id'], date('Y-m-d H:i:s', strtotime('-20 minutes')), 'in_progress');
$_SERVER['REQUEST_URI'] = '/planos/1';
$h = $item($p);
T::ok(str_contains($h, 'data-state="continue"') && str_contains($h, 'Em andamento') && str_contains($h, 'href="/consulta/' . $cur . '"'), 'em andamento: "Em andamento" e link "Abrir consulta"');
T::ok($svc->careStepForPatient($p)['state'] === 'continue', 'cabeçalho e painel com o mesmo estado (continue)');
$_SERVER['REQUEST_URI'] = '/consulta/' . $cur;
$h = $item($p);
T::ok(str_contains($h, 'Em andamento') && !str_contains($h, 'href="/consulta/'), 'na própria tela da consulta não repete o link para ela mesma');
unset($_SERVER['REQUEST_URI']);

echo "== última concluída\n";
$ins($cA, $p, $a['professional_id'], '2026-10-01 09:00:00', 'completed');
$ins($cA, $p, $a['professional_id'], '2026-09-01 09:00:00', 'completed');
$h = $item($p);
T::ok(str_contains($h, 'Última consulta concluída: 01/10/2026'), 'mostra a última consulta concluída (01/10/2026), não a anterior');

echo "== isolamento\n";
$other = $ins($cA, $p2, $a['professional_id'], date('Y-m-d H:i:s'), 'in_progress');
$h = $item($p);
T::ok(!str_contains($h, '/consulta/' . $other), 'consulta de outro paciente da mesma clínica não aparece');
$pdo->exec("UPDATE appointments SET status='cancelled' WHERE id=$cur");
$forged = $ins($cB, $p, $b['professional_id'], date('Y-m-d H:i:s'), 'in_progress');
$h = $item($p);
T::ok(!str_contains($h, '/consulta/' . $forged) && !str_contains($h, 'Em andamento'), 'agendamento gravado com outra clínica para o mesmo id de paciente é ignorado');
$bApp = $ins($cB, $pB, $b['professional_id'], date('Y-m-d H:i:s'), 'in_progress');
T::ok(ClinicalContext::render(\ClyoNutri\Core\Database\Connection::getInstance(), $pB) === '', 'paciente de outra clínica: painel inteiro vazio (nada da agenda dele)');
Fx::act($b);
$h = $item($pB);
T::ok(str_contains($h, 'href="/consulta/' . $bApp . '"') && !str_contains($h, '/consulta/' . $forged), 'clínica B vê só a própria consulta');
Fx::act($a);

echo "== agenda indisponível\n";
$pdo->exec('ALTER TABLE appointments RENAME TO appointments_v5426_off');
$html = ClinicalContext::render(\ClyoNutri\Core\Database\Connection::getInstance(), $p);
$pdo->exec('ALTER TABLE appointments_v5426_off RENAME TO appointments');
T::ok(str_contains($html, 'data-testid="context-allergies"') && str_contains($html, 'Agenda indisponível'), 'sem a tabela da agenda: painel continua com alergias e diz "Agenda indisponível"');

echo "== design system\n";
$view = (string)file_get_contents($root . '/views/patients/_clinical_context.php');
preg_match('#data-testid="context-appointment".*?</dd></div>#s', $view, $m);
T::ok(isset($m[0]) && !str_contains($m[0], 'style=') && !preg_match('/#[0-9a-fA-F]{3,6}\b/', $m[0]), 'item sem style="" e sem cor fixa (classes do painel)');
$ctrl = (string)file_get_contents($root . '/src/Application/Controllers/DashboardController.php');
T::ok(substr_count($ctrl, 'ClinicalContext::renderData(Connection::getInstance(),$planClinicalContext') === 3 && preg_match('#<!-- Modal Adicionar Refeição -->(.*?)<!-- Modal Adicionar Alimento -->#s', $ctrl, $mm) && !str_contains($mm[1], 'ClinicalContext'), 'plano: painel nos diálogos de alimento, substituição e publicação; fora do diálogo de refeição');

T::done();
