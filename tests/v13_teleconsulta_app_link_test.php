<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * v13 (compatibilidade do app): código de acesso de uso único, próxima consulta sem consultas passadas,
 * sala recusada para consulta passada, preflight CORS 204 e tipo video_status do app alinhado ao servidor.
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationStateException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationTicketUsedException;
use ClyoNutri\Domain\Telehealth\Providers\PeerToPeerWebRtcProvider;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;

date_default_timezone_set('America/Sao_Paulo');
$appKey = 'test-app-key-' . str_repeat('v', 24);
putenv('APP_KEY=' . $appKey); $_ENV['APP_KEY'] = $appKey;
$db = Connection::getInstance();
// banco de teste pode ser anterior à migração 039: o Migrator é idempotente
(new \ClyoNutri\Core\Database\Migrator($db, dirname(__DIR__) . '/database/migrations'))->run();
$failures = 0;
function check(bool $c, string $m): void { if (!$c) throw new RuntimeException("FALHA: {$m}"); }
function throwsClass(string $class, callable $fn, string $m): void
{
    try { $fn(); } catch (Throwable $e) { if ($e instanceof $class) return; throw new RuntimeException("FALHA: {$m} (" . get_class($e) . ': ' . $e->getMessage() . ')'); }
    throw new RuntimeException("FALHA: {$m} (nada lançado)");
}
function step(string $n, callable $fn): void { global $failures; try { $fn(); echo "[PASS] {$n}\n"; } catch (Throwable $e) { $failures++; echo "[FAIL] {$n} — {$e->getMessage()}\n"; } }

echo "\n=== v13: LINK DO APP DE USO ÚNICO, PRÓXIMA CONSULTA, PREFLIGHT ===\n\n";
$db->beginTransaction();
$s = bin2hex(random_bytes(4));
$q = static function (string $sql, array $p = []) use ($db): int { $db->prepare($sql)->execute($p); return (int)$db->lastInsertId(); };
$clinic = $q("INSERT INTO clinics (uuid, name, email) VALUES (?, 'Clínica v13', ?)", ["v13-{$s}", "v13{$s}@ex.test"]);
$u = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Nutri v13', ?, 'x', 'nutritionist', 1, 'active')", ["u13-{$s}", "u13{$s}@ex.test"]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'nutritionist', 'active')", [$clinic, $u]);
$pro = $q("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-3 1', 'CRN-3')", [$u]);
$patient = $q("INSERT INTO patients (clinic_id, uuid, name, email) VALUES (?, ?, 'Paciente v13', ?)", [$clinic, "p13-{$s}", "p13{$s}@ex.test"]);
$appt = static fn(string $at, string $status = 'scheduled') => $q("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (?, ?, ?, ?, 50, 'online', ?)", [$clinic, $patient, $pro, $at, $status]);
// 14:00 em São Paulo = 17:00 UTC; janela 16:45–18:20 UTC.
$past = $appt('2026-11-20 09:00:00');      // passou (fim + tolerância 10:20 local)
$recent = $appt('2026-11-20 13:30:00');    // começou há pouco: ainda dentro da tolerância às 14:05
$main = $appt('2026-11-20 14:00:00');
$utc = new DateTimeZone('UTC');
$during = new DateTimeImmutable('2026-11-20 17:05:00', $utc);
$svc = new TeleconsultationService($db, new PeerToPeerWebRtcProvider($db, $appKey), null, $during);
TenantContext::setClinicId($clinic);

step('item 4: web recusa criar sala para consulta passada (fim + tolerância)', function () use ($svc, $past, $pro, $clinic, $db) {
    throwsClass(TeleconsultationStateException::class, fn() => $svc->scheduleSession($past, $pro, $clinic), 'consulta passada não pode ganhar sala');
    $n = $db->prepare('SELECT COUNT(*) FROM teleconsultation_sessions WHERE appointment_id = ?'); $n->execute([$past]);
    check((int)$n->fetchColumn() === 0, 'nenhuma sessão criada');
});

step('item 4: próxima consulta ignora as que já passaram e mantém a que está na tolerância', function () use ($db, $patient, $past, $recent, $main) {
    $portal = new PatientPortalService($db, new DateTimeImmutable('2026-11-20 17:05:00', new DateTimeZone('UTC')));
    $next = $portal->getNextAppointment($patient);
    check((int)$next['id'] === $recent, "esperada #{$recent} (dentro da tolerância), veio #" . ($next['id'] ?? 'null'));
    $later = new PatientPortalService($db, new DateTimeImmutable('2026-11-20 18:00:00', new DateTimeZone('UTC'))); // 15:00 local
    check((int)$later->getNextAppointment($patient)['id'] === $main, 'às 15:00 a de 13:30 (fim 14:20+30) já passou → mostra a de 14:00');
    $gone = new PatientPortalService($db, new DateTimeImmutable('2026-11-21 12:00:00', new DateTimeZone('UTC')));
    check($gone->getNextAppointment($patient) === null, 'no dia seguinte nenhuma é "próxima"');
});

$ctx = [];
step('item 3: código do app é de uso único, novo a cada pedido e preso ao navegador', function () use ($svc, $main, $pro, $clinic, $patient, &$ctx) {
    $created = $svc->scheduleSession($main, $pro, $clinic);
    $t1 = $svc->issuePortalTicket($patient, $main, $clinic);
    $t2 = $svc->issuePortalTicket($patient, $main, $clinic);
    check(TeleconsultationService::isSingleUseTicket((string)$t1) && $t1 !== $t2, 'bilhetes q. distintos a cada pedido');
    [$session, $cookie] = $svc->redeemPatientLink($t1);
    check((int)$session['id'] === (int)$created['session_id'] && str_starts_with($cookie, $t1 . '~'), 'troca devolve cookie bilhete~chave');
    check((int)$svc->getSessionByPatientToken($cookie)['id'] === (int)$created['session_id'], 'cookie vale');
    throwsClass(TeleconsultationTicketUsedException::class, fn() => $svc->redeemPatientLink($t1), 'segundo uso recusado');
    throwsClass(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken($t2), 'bilhete puro não serve como cookie');
    throwsClass(TeleconsultationTicketUsedException::class, fn() => $svc->getSessionByPatientToken($t1 . '~' . str_repeat('A', 43)), 'chave de navegador forjada');
    [, $cookie2] = $svc->redeemPatientLink($t2);
    check($cookie2 !== $cookie, 'novo código gera outro acesso');
    $ctx = ['session' => (int)$created['session_id'], 'cookie' => $cookie, 't3' => $svc->issuePortalTicket($patient, $main, $clinic)];
});

step('item 3: link do e-mail segue reutilizável (p.) e revogação/TTL continuam valendo', function () use ($svc, $main, $pro, $clinic, $patient, $appKey, $db, &$ctx) {
    $email = $svc->issuePortalTicket($patient, $main, $clinic, 30 * 24 * 3600, false);
    check(str_starts_with((string)$email, 'p.') && !TeleconsultationService::isSingleUseTicket((string)$email), 'e-mail usa bilhete p.');
    $svc->redeemPatientLink($email); $svc->redeemPatientLink($email);
    $svc->issueInvite($ctx['session'], $pro, $clinic); // novo convite = nova geração
    throwsClass(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken($ctx['cookie']), 'cookie de geração antiga revogado');
    throwsClass(TenantSecurityException::class, fn() => $svc->redeemPatientLink($ctx['t3']), 'código ainda não usado de geração antiga revogado');
    $late = new TeleconsultationService($db, new PeerToPeerWebRtcProvider($db, $appKey), null, new DateTimeImmutable('2026-11-20 19:00:00', new DateTimeZone('UTC')));
    $fresh = $svc->issuePortalTicket($patient, $main, $clinic);
    throwsClass(Throwable::class, fn() => $late->redeemPatientLink($fresh), 'código vencido após a janela');
});

step('item 2: preflight OPTIONS responde 204 sem virar 500 no ApiErrorBoundary', function () {
    $root = dirname(__DIR__);
    $b = file_get_contents($root . '/src/Core/Http/ApiErrorBoundary.php');
    check(str_contains($b, '$status === 204 || $status === 304'), 'boundary ignora respostas sem corpo');
    $i = file_get_contents($root . '/public/index.php');
    check(preg_match("/=== 'OPTIONS'\\) \\{\\s*header\\('Access-Control-Max-Age: 600'\\);\\s*header_remove\\('Content-Type'\\);\\s*http_response_code\\(204\\);/", $i) === 1, 'OPTIONS devolve 204 sem Content-Type JSON');
    // CORS concedido só para origem configurada ou mesmo host
    putenv('MOBILE_WEB_ORIGINS=https://app.ex.test'); $_ENV['MOBILE_WEB_ORIGINS'] = 'https://app.ex.test'; $_SERVER['HTTP_HOST'] = 'clyonutri.com';
    check(\ClyoNutri\Core\Auth\MobileWebSession::allowedOrigin('https://app.ex.test'), 'origem configurada permitida');
    check(!\ClyoNutri\Core\Auth\MobileWebSession::allowedOrigin('https://evil.example'), 'origem estranha recusada');
});

step('item 1: tipo video_status do app cobre exatamente os valores do servidor', function () {
    $root = dirname(__DIR__);
    preg_match_all("/(?<!\\[)'([a-z_]+)'(?!\\])/", (string)(preg_match("/\\\$row\\['video_status'\\] = (.+);/", file_get_contents($root . '/src/Domain/PatientPortal/PatientPortalService.php'), $m) ? $m[1] : ''), $srv);
    preg_match("/video_status\\?: ([^;]+);/", file_get_contents($root . '/mobile/src/services/api.ts'), $ts);
    preg_match_all("/'([a-z_]+)'/", $ts[1] ?? '', $app);
    $a = array_unique($srv[1]); sort($a); $b = array_unique($app[1]); sort($b);
    check($a === $b && $a !== [], 'servidor ' . json_encode($a) . ' vs app ' . json_encode($b));
});

$db->rollBack();
echo $failures ? "\n{$failures} FALHA(S)\n" : "\nv13 APROVADO\n";
exit($failures ? 1 : 0);
