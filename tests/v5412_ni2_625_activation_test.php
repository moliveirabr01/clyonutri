<?php
/** NI2-625 (v54.12): checklist de ativação conferido no banco. Dados TESTE. Fora do zip. */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5412.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Booking\BookingSetupState as S;
use ClyoNutri\Domain\Onboarding\ActivationChecklist as A;

$byKey = static fn(array $r) => array_column($r['items'], null, 'key');
$f = ['profile' => true, 'booking_state' => S::OK, 'patients' => 1, 'appointments' => 1, 'plans' => 1];

echo "== regra\n";
$r = A::build($f);
T::ok($r['ready'] && $r['done'] === 6 && $r['applicable'] === 6 && $r['pct'] === 100 && $r['next'] === null, 'tudo feito: pronto, 6 de 6, 100%');
$r = A::build(['booking_state' => S::HOURS_UNCONFIRMED] + $f); $k = $byKey($r);
T::ok(!$r['ready'] && $k['hours']['state'] === 'pending' && $k['link']['state'] === 'pending' && $r['pct'] === 66 && $r['pct'] < 100, 'link ligado sem horários: não pronto, nunca 100% (' . $r['pct'] . '%)');
T::ok($r['next']['key'] === 'hours' && str_contains($r['next']['action_url'], 'secao=horarios'), 'próximo passo: confirmar horários');
$r = A::build(['booking_state' => S::NO_SLOTS] + $f); $k = $byKey($r);
T::ok($r['ready'] && $k['hours']['state'] === 'done' && $k['link']['state'] === 'attention' && str_contains($k['link']['detail'], 'sem vaga livre'), 'agenda cheia/bloqueada: configuração válida, com aviso (não é falta de configuração)');
$r = A::build(['booking_state' => S::DISABLED] + $f); $k = $byKey($r);
T::ok($r['ready'] && $k['hours']['state'] === 'not_applicable' && $k['link']['state'] === 'not_applicable' && $r['applicable'] === 4 && $r['pct'] === 100, 'agendamento online desligado: horários e link não se aplicam');
$r = A::build(['patients' => 0, 'appointments' => 0, 'plans' => 0] + $f);
T::ok($r['ready'] && $r['done'] === 3 && $r['next']['key'] === 'patient', 'sem paciente, consulta e plano: pronto para atender (primeiros passos não bloqueiam)');
$r = A::build(['profile' => false, 'booking_state' => S::NO_HOURS] + $f);
T::ok(!$r['ready'] && $r['next']['key'] === 'profile', 'obrigatório pendente vem antes: perfil primeiro');

echo "== banco\n";
$c = Fx::clinic($pdo, 'Clínica TESTE Ativação');
$u = Fx::user($pdo, $c, 'Nutri TESTE Ativação', 'owner', null, null);
$c2 = Fx::clinic($pdo, 'Clínica TESTE Outra');
$u2 = Fx::user($pdo, $c2, 'Nutri TESTE Outra', 'owner');
$pdo->prepare('INSERT INTO booking_settings (clinic_id, is_enabled, allow_presential, allow_telehealth) VALUES (?, 0, 1, 1)')->execute([$c]);
$chk = new A($pdo);
$r = $chk->forUser($c, $u['user_id']); $k = $byKey($r);
T::ok($k['profile']['state'] === 'pending' && !$r['ready'], 'sem CRN: perfil pendente, não pronto');
$pdo->prepare("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, '54321', 'CRN-6')")->execute([$u['user_id']]);
$u['professional_id'] = (int)$pdo->lastInsertId();
$r = $chk->forUser($c, $u['user_id']); $k = $byKey($r);
T::ok($r['ready'] && $k['patient']['state'] === 'pending' && $k['appointment']['state'] === 'pending' && $k['plan']['state'] === 'pending', 'CRN preenchido: pronto; paciente, consulta e plano a fazer');
// dados de outra clínica não contam
$px = Fx::patient($pdo, $c2, 'Paciente TESTE Outra');
Fx::appointment($pdo, $c2, $px, $u2['professional_id']);
$r = $chk->forUser($c, $u['user_id']); $k = $byKey($r);
T::ok($k['patient']['state'] === 'pending' && $k['appointment']['state'] === 'pending', 'paciente e consulta de outra clínica não contam');
$p = Fx::patient($pdo, $c, 'Paciente TESTE Ativação');
$aid = Fx::appointment($pdo, $c, $p, $u['professional_id']);
$pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$aid]);
$r = $chk->forUser($c, $u['user_id']); $k = $byKey($r);
T::ok($k['patient']['state'] === 'done' && $k['appointment']['state'] === 'pending', 'paciente conta; consulta cancelada não conta');
Fx::appointment($pdo, $c, $p, $u['professional_id']);
$r = $chk->forUser($c, $u['user_id']);
T::ok($byKey($r)['appointment']['state'] === 'done' && $r['done'] === 3 && $r['applicable'] === 4, 'consulta marcada: 3 de 4 (link desligado)');
$pdo->prepare('UPDATE booking_settings SET is_enabled = 1 WHERE clinic_id = ?')->execute([$c]);
$r = $chk->forUser($c, $u['user_id']);
T::ok(!$r['ready'] && $r['applicable'] === 6 && $r['next']['key'] === 'hours', 'liga o link sem horários: volta a não pronto, próximo = horários');
T::done();
