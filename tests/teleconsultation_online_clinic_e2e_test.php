<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Teleconsulta (NI2-221) — v11. Exercita o CAMINHO DO CONTROLLER (TeleconsultationController) com sessão PHP,
 * CSRF e IDs divergentes users.id × professionals.id, além do serviço. Sem provedor de vídeo real:
 * o único provedor "configurado" aqui é um FALSO em memória para checar F11 (nome da sala) — não prova integração.
 * A antiga afirmação "PASS NI2-245 … integração com Daily comprovada" (classe mock) foi removida.
 */

use ClyoNutri\Application\Controllers\TeleconsultationController;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TelehealthRateLimiter;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Providers\DailyVideoProvider;
use ClyoNutri\Domain\Telehealth\TeleconsultationException;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;
use ClyoNutri\Domain\Telehealth\TelehealthService;

ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$db = Connection::getInstance();
$passed = 0;
function ok(bool $cond, string $msg): void {
    global $passed;
    if (!$cond) { ob_end_flush(); fwrite(STDERR, "FALHA: {$msg}\n"); exit(1); }
    $passed++; fwrite(STDOUT, "[PASS] {$msg}\n");
}
function run(callable $fn): array { ob_start(); http_response_code(200); $fn(); $out = (string)ob_get_clean(); return [http_response_code(), $out]; }
function asUser(int $userId, int $clinicId): void { $_SESSION['user_id'] = $userId; $_SESSION['clinic_id'] = $clinicId; TenantContext::setClinicId($clinicId); }
function csrf(): string { return Csrf::generate(); }
function col(PDO $db, string $q) { return $db->query($q)->fetchColumn(); }
function throwsStatus(callable $fn): ?int { try { $fn(); } catch (TeleconsultationException $e) { return $e->httpStatus; } return null; }

$db->beginTransaction();
try {
    $sfx = bin2hex(random_bytes(4));
    $mk = function (string $sql) use ($db): int { $db->exec($sql); return (int)$db->lastInsertId(); };
    $cA = $mk("INSERT INTO clinics (uuid, name, email, status) VALUES ('tA-{$sfx}', 'Clinica Ficticia A', 'a{$sfx}@fict.test', 'active')");
    $cB = $mk("INSERT INTO clinics (uuid, name, email, status) VALUES ('tB-{$sfx}', 'Clinica Ficticia B', 'b{$sfx}@fict.test', 'active')");
    $user = function (string $n, string $role) use ($mk, $sfx): int {
        return $mk("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('u-{$n}-{$sfx}', '{$n}', '{$n}{$sfx}@fict.test', 'x', '{$role}', 1, 'active')");
    };
    $member = fn(int $c, int $u, string $r) => $mk("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES ({$c}, {$u}, '{$r}', 'active')");
    $prof = fn(int $u) => $mk("INSERT INTO professionals (user_id, crn, crn_region) VALUES ({$u}, 'F{$u}', '3')");

    // N1: responsável; os IDs divergem. Colisão: users.id do "NC" = professionals.id do N1.
    $uN2 = $user('n2', 'nutritionist'); $member($cA, $uN2, 'nutritionist'); $pN2 = $prof($uN2);
    $uN1 = $user('n1', 'nutritionist'); $member($cA, $uN1, 'nutritionist');
    $pN1 = (int)max(col($db, 'SELECT COALESCE(MAX(id),0) FROM professionals'), col($db, 'SELECT COALESCE(MAX(id),0) FROM users')) + 1000;
    $db->exec("INSERT INTO professionals (id, user_id, crn, crn_region) VALUES ({$pN1}, {$uN1}, 'F{$uN1}', '3')");
    $uSec = $user('sec', 'receptionist'); $member($cA, $uSec, 'receptionist');
    $uNB = $user('nb', 'nutritionist'); $member($cB, $uNB, 'nutritionist'); $pNB = $prof($uNB);
    // força a colisão: cria um usuário com id = professionals.id do N1 (se livre)
    $uNC = null;
    if (!col($db, "SELECT COUNT(*) FROM users WHERE id = {$pN1}")) {
        $db->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES ({$pN1}, 'u-nc-{$sfx}', 'nc', 'nc{$sfx}@fict.test', 'x', 'nutritionist', 1, 'active')");
        $uNC = $pN1; $member($cA, $uNC, 'nutritionist'); $prof($uNC);
    }
    ok($uN1 !== $pN1, 'Cenário com users.id ≠ professionals.id para o responsável');

    $pat = $mk("INSERT INTO patients (clinic_id, uuid, name, email) VALUES ({$cA}, 'p-{$sfx}', 'Paciente Ficticio', 'p{$sfx}@fict.test')");
    $soon = date('Y-m-d H:i:s', time() + 300);
    $app = fn(string $when, string $type, int $p = 0) => $mk("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES ({$cA}, {$pat}, " . ($p ?: $pN1) . ", '{$when}', 50, '{$type}', 'scheduled')");
    $a1 = $app($soon, 'teleconsultation');
    $aPres = $app($soon, 'first_visit');
    $aCancel = $app(date('Y-m-d H:i:s', time() + 86400), 'teleconsultation');
    $aReag = $app(date('Y-m-d H:i:s', time() + 2 * 86400), 'online');

    $svc = new TeleconsultationService($db, new DailyVideoProvider(null, null));
    $ctl = new TeleconsultationController($db, $svc);

    // ---------------- F01: identidade resolvida no servidor
    ok($svc->resolveProfessionalId($uN1, $cA) === $pN1, 'F01: users.id do N1 resolve para o professionals.id dele');
    ok($svc->resolveProfessionalId($uSec, $cA) === null, 'F01: secretária não tem professionals.id clínico');
    ok($svc->resolveProfessionalId($uNB, $cA) === null, 'F01: profissional de outra clínica não resolve na clínica A');

    // ---------------- F07: GET não cria; só o responsável; POST com CSRF
    asUser($uN2, $cA);
    [$st] = run(fn() => $ctl->openFromAppointment(['appointmentId' => $a1]));
    ok($st === 403 && !col($db, "SELECT COUNT(*) FROM teleconsultation_sessions WHERE appointment_id = {$a1}"), 'F07: não responsável GET → 403 e nenhuma sessão');
    asUser($uN1, $cA);
    [$st, $html] = run(fn() => $ctl->openFromAppointment(['appointmentId' => $a1]));
    ok($st === 200 && str_contains($html, 'method="POST"') && !col($db, "SELECT COUNT(*) FROM teleconsultation_sessions WHERE appointment_id = {$a1}"), 'F07: responsável GET → 200 com botão POST, sem criar sessão');
    $_POST = [];
    [$st] = run(fn() => $ctl->createFromAppointment(['appointmentId' => $a1]));
    ok($st === 403, 'F07: POST de criação sem CSRF → 403');
    $_POST = ['_csrf' => csrf()];
    [$st] = run(fn() => $ctl->createFromAppointment(['appointmentId' => $a1]));
    [$st2] = run(fn() => $ctl->createFromAppointment(['appointmentId' => $a1]));
    $sid = (int)col($db, "SELECT id FROM teleconsultation_sessions WHERE appointment_id = {$a1}");
    ok($st === 303 && $st2 === 303 && $sid > 0 && (int)col($db, "SELECT COUNT(*) FROM teleconsultation_sessions WHERE appointment_id = {$a1}") === 1, 'F07: POST com CSRF cria; repetir é idempotente (1 sessão)');
    ok((int)col($db, "SELECT professional_id FROM teleconsultation_sessions WHERE id = {$sid}") === $pN1, 'F01: sessão gravada com o professionals.id do responsável');

    // ---------------- F19: presencial
    $_POST = ['_csrf' => csrf()];
    [$st] = run(fn() => $ctl->createFromAppointment(['appointmentId' => $aPres]));
    ok($st === 409 && !col($db, "SELECT COUNT(*) FROM teleconsultation_sessions WHERE appointment_id = {$aPres}"), 'F19: consulta presencial → 409, nenhuma sessão');

    // ---------------- F07/F20: leitura da sala
    asUser($uN2, $cA);
    [$st, $html] = run(fn() => $ctl->professionalRoom(['id' => $sid]));
    ok($st === 403 && !str_contains($html, 'Paciente Ficticio'), 'F07: não responsável não abre a sala (403, sem dados do paciente)');
    if ($uNC) {
        asUser($uNC, $cA);
        [$st] = run(fn() => $ctl->professionalRoom(['id' => $sid]));
        ok($st === 403, 'F01b: usuário cujo users.id = professionals.id do responsável → 403');
        $_POST = ['_csrf' => csrf()];
        [$st] = run(fn() => $ctl->admit(['id' => $sid]));
        ok($st === 403, 'F01b: colisão não admite (403)');
    }
    asUser($uNB, $cB);
    [$st] = run(fn() => $ctl->professionalRoom(['id' => $sid]));
    $_POST = ['_csrf' => csrf()];
    [$st2, $out2] = run(fn() => $ctl->admit(['id' => $sid]));
    ok($st === 404 && $st2 === 404 && !str_contains($out2, 'tenant') && !str_contains($out2, 'ID '), 'F20: outra clínica → 404 (não 500), sem ID na resposta');
    asUser($uN1, $cA);
    [$st, $html] = run(fn() => $ctl->professionalRoom(['id' => $sid]));
    ok($st === 200 && str_contains($html, 'Vídeo não configurado') && !str_contains($html, 'Vídeo Real Conectado') && !str_contains($html, 'LGPD Seguro') && !str_contains($html, 'tailwindcss'), 'F02/F13: sala honesta, sem selos nem Tailwind CDN');

    // ---------------- link do paciente (mostrado uma vez; só hash)
    $_POST = ['_csrf' => csrf()];
    [$st] = run(fn() => $ctl->issueLink(['id' => $sid]));
    [, $html] = run(fn() => $ctl->professionalRoom(['id' => $sid]));
    preg_match('#/teleconsulta/sala-espera/([0-9a-f]{64})#', $html, $m);
    $tok = $m[1] ?? '';
    [, $html2] = run(fn() => $ctl->professionalRoom(['id' => $sid]));
    ok($st === 303 && $tok !== '' && !preg_match('#/sala-espera/[0-9a-f]{64}#', $html2), 'F03 (parcial): link gerado e exibido UMA vez');
    ok(col($db, "SELECT patient_token_hash FROM teleconsultation_sessions WHERE id = {$sid}") === hash('sha256', $tok), 'F04: banco guarda só o SHA-256 do token');

    // ---------------- paciente via controller: troca por cookie, CSRF, consentimento, espera
    $db->exec("DELETE FROM login_attempts WHERE scope LIKE 'tele_%'");
    unset($_SESSION['user_id'], $_SESSION['clinic_id']);
    [$st] = run(fn() => $ctl->patientLinkExchange(['token' => $tok]));
    ok($st === 303 && isset($_SESSION['_tele_patient']), 'F14: link trocado por cookie e redirecionado (303) para a URL limpa');
    $_POST = []; $_SERVER['HTTP_X_CSRF_TOKEN'] = '';
    [$st] = run(fn() => $ctl->patientEnterWaiting());
    ok($st === 403, 'Paciente: POST sem CSRF → 403');
    $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf();
    $sess = $svc->getSessionForPatientCookie($sid, (int)$_SESSION['_tele_patient']['gen'], (string)$_SESSION['_tele_patient']['h']);
    $svc->submitDeviceCheck($sess, ['camera_ok' => true, 'microphone_ok' => true]);
    $term = $svc->getActiveConsentTerm();
    ok($term !== null, 'F18: termo de teleconsulta ativo existe (migração 35)');
    $sess = $svc->getSessionForPatientCookie($sid, (int)$_SESSION['_tele_patient']['gen'], (string)$_SESSION['_tele_patient']['h']);
    ok(throwsStatus(fn() => $svc->recordConsent($sess, 999999, true, '10.1.2.3', 'X')) === 409, 'F18: termo errado → 409');
    $svc->recordConsent($sess, (int)$term['id'], false, '10.1.2.3', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120');
    $svc->recordConsent($sess, (int)$term['id'], true, '10.1.2.3', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120');
    $rows = $db->query("SELECT clinic_id, granted, term_id, ip_address, user_agent FROM patient_consents WHERE patient_id = {$pat} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($rows) === 2 && (int)$rows[0]['granted'] === 0 && (int)$rows[1]['granted'] === 1 && (int)$rows[1]['clinic_id'] === $cA
        && (int)$rows[1]['term_id'] === (int)$term['id'] && $rows[1]['ip_address'] === '10.1.2.0' && $rows[1]['user_agent'] === 'Chrome/Windows',
        'F18: recusa e aceite gravados em patient_consents (clinic_id, granted, term_id, IP /24, UA resumido)');
    [$st, $out] = run(fn() => $ctl->patientEnterWaiting());
    ok($st === 200 && col($db, "SELECT status FROM teleconsultation_sessions WHERE id = {$sid}") === 'waiting_room', 'Paciente entra na espera pelo controller');
    $db->exec("UPDATE teleconsultation_sessions SET external_room_url = 'https://dummy-video.invalid/x', external_room_name = 'clyo-x' WHERE id = {$sid}");
    [$st, $out] = run(fn() => $ctl->patientStatus());
    $j = json_decode($out, true);
    ok($st === 200 && array_keys($j) === ['status', 'is_admitted'] && !str_contains($out, 'dummy-video'), 'F05: status do paciente só com o estado');

    // ---------------- F01/F08/F10 pelo controller
    asUser($uN1, $cA);
    $_POST = ['_csrf' => csrf()];
    [$st] = run(fn() => $ctl->admit(['id' => $sid]));
    ok($st === 303 && (int)col($db, "SELECT actor_id FROM teleconsultation_events WHERE session_id = {$sid} AND event_type = 'patient_admitted'") === $pN1, 'F01: responsável com IDs divergentes admite (303, antes 500); autoria = professionals.id');
    [$st] = run(fn() => $ctl->admit(['id' => $sid]));
    ok($st === 303 && (int)col($db, "SELECT COUNT(*) FROM teleconsultation_events WHERE session_id = {$sid} AND event_type = 'patient_admitted'") === 1, 'F08: admitir de novo é idempotente');
    $_POST = ['_csrf' => csrf(), 'title' => 'Evolucao v11', 'clinical_notes' => 'texto ficticio', 'goals' => ''];
    [$st] = run(fn() => $ctl->recordEvolution(['id' => $sid]));
    ok($st === 303 && (int)col($db, "SELECT professional_id FROM clinical_records WHERE patient_id = {$pat} ORDER BY id DESC LIMIT 1") === $pN1
        && col($db, "SELECT status FROM teleconsultation_sessions WHERE id = {$sid}") === 'in_progress', 'F01: evolução com autoria do responsável; admitted → in_progress');
    $fake = new class implements VideoConferenceProviderInterface {
        public array $deleted = [];
        public function isConfigured(): bool { return true; }
        public function getProviderName(): string { return 'fake_test'; }
        public function createRoom(string $roomName, int $expiresInSeconds = 7200): array { return ['room_id' => 'id-' . $roomName, 'room_name' => $roomName, 'room_url' => 'https://dummy-video.invalid/' . $roomName]; }
        public function issueMeetingToken(string $roomName, string $participantRole, string $userName, int $expiresInSeconds = 7200): array { throw new RuntimeException('não usado'); }
        public function deleteRoom(string $roomName): bool { $this->deleted[] = $roomName; return true; }
    };
    $ctlF = new TeleconsultationController($db, new TeleconsultationService($db, $fake));
    $_POST = ['_csrf' => csrf()];
    [$st] = run(fn() => $ctlF->end(['id' => $sid]));
    [$st2] = run(fn() => $ctlF->end(['id' => $sid]));
    ok($st === 303 && $st2 === 303 && (int)col($db, "SELECT COUNT(*) FROM teleconsultation_events WHERE session_id = {$sid} AND event_type = 'call_ended'") === 1, 'F08: encerrar é idempotente (1 call_ended)');
    ok(col($db, "SELECT status FROM appointments WHERE id = {$a1}") === 'scheduled', 'F10: encerrar a chamada NÃO conclui o agendamento');
    ok($fake->deleted === ['clyo-x'], 'F11: deleteRoom recebe o NOME da sala (provedor falso em memória)');
    $_POST = ['_csrf' => csrf(), 'title' => 'Adendo', 'clinical_notes' => 'adendo', 'goals' => ''];
    [$st] = run(fn() => $ctl->recordEvolution(['id' => $sid]));
    ok($st === 303 && col($db, "SELECT status FROM teleconsultation_sessions WHERE id = {$sid}") === 'completed', 'F08: evolução após encerrar é adendo e não reabre');
    $_POST = ['_csrf' => csrf()];
    [$st] = run(fn() => $ctl->admit(['id' => $sid]));
    ok($st === 409, 'F08: admitir após encerrar → 409');
    ok(throwsStatus(fn() => $svc->getSessionByPatientToken($tok)) === 404, 'F04: encerrar revoga o link do paciente');
    ok(!TeleconsultationService::canTransition('rejected', 'admitted') && !TeleconsultationService::canTransition('completed', 'in_progress'), 'F08: rejected/completed são terminais');

    // ---------------- F04: cancelar / reagendar / janela / regerar
    $sidC = $svc->createSessionForAppointment($aCancel, $uN1, $cA)['session_id'];
    $tC = $svc->issuePatientLink($sidC, $uN1, $cA)['token'];
    ok(!empty($svc->getSessionByPatientToken($tC)['too_early']), 'F04: antes da janela o link é válido mas marcado "ainda não abriu"');
    ok(throwsStatus(fn() => $svc->submitDeviceCheck($svc->getSessionByPatientToken($tC), ['camera_ok' => 1, 'microphone_ok' => 1])) === 409, 'F04: fora da janela não avança (409)');
    (new AppointmentService($db))->updateStatus($aCancel, 'cancelled', 'teste', $uN1);
    ok(throwsStatus(fn() => $svc->getSessionByPatientToken($tC)) === 404 && col($db, "SELECT status FROM teleconsultation_sessions WHERE id = {$sidC}") === 'cancelled', 'F04: cancelar o agendamento revoga o link e cancela a sessão');
    $sidR = $svc->createSessionForAppointment($aReag, $uN1, $cA)['session_id'];
    $tR = $svc->issuePatientLink($sidR, $uN1, $cA)['token'];
    (new AppointmentService($db))->edit($aReag, ['scheduled_at' => date('Y-m-d H:i', time() + 3 * 86400)], true, $uN1);
    ok(throwsStatus(fn() => $svc->getSessionByPatientToken($tR)) === 404, 'F04: reagendar revoga o link');
    ok(col($db, "SELECT type FROM appointments WHERE id = {$aReag}") === 'online', 'Reagendar sem enviar "type" preserva a modalidade (antes virava first_visit)');
    $tR2 = $svc->issuePatientLink($sidR, $uN1, $cA)['token'];
    $tR3 = $svc->issuePatientLink($sidR, $uN1, $cA)['token'];
    ok(throwsStatus(fn() => $svc->getSessionByPatientToken($tR2)) === 404 && $svc->getSessionByPatientToken($tR3)['id'] == $sidR, 'F04: um token ativo por consulta (regerar invalida o anterior)');
    $late = new TeleconsultationService($db, null, null, fn() => time() + 10 * 86400);
    ok(throwsStatus(fn() => $late->getSessionByPatientToken($tR3)) === 404, 'F04: depois do fim + 30 min o link expira');
    ok(throwsStatus(fn() => $svc->getSessionByPatientToken(str_repeat('0', 64))) === 404 && throwsStatus(fn() => $svc->getSessionByPatientToken('x')) === 404, 'F04: token inválido → 404 genérico');

    // ---------------- F14 rate limit
    $rl = new TelehealthRateLimiter($db);
    $okHits = 0;
    for ($i = 0; $i < 15; $i++) { if ($rl->hit('tele_consent', "t-{$sfx}")) { $okHits++; } }
    ok($okHits === 10 && $rl->isLocked('tele_consent', "t-{$sfx}"), 'F14: 10 consentimentos por janela, depois bloqueia');
    $later = new TelehealthRateLimiter($db, fn() => time() + 1000);
    ok($later->hit('tele_consent', "t-{$sfx}"), 'F14: bloqueio expira com a janela (sem bloqueio permanente)');
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}

// ---------------- F12 flag, F06 CSP, legado (sem banco)
$ref = new ReflectionProperty(PlatformSettings::class, 'cachedSettings');
$ref->setValue(null, ['maintenance_mode' => '0']);
ok(PlatformSettings::isTelehealthEnabled() === false, 'F12: sem a chave feature_flag_telehealth o módulo fica DESLIGADO');
$ref->setValue(null, ['feature_flag_telehealth' => '1']);
ok(PlatformSettings::isTelehealthEnabled() === true, 'F12: liga só com a chave = 1');
PlatformSettings::clearCache();
putenv('TELEHEALTH_PROVIDER_ORIGIN=');
ok(SecurityHeaders::allowTelehealthProviderOrigin() === null && str_contains(SecurityHeaders::pagePolicy(), "frame-src 'self';"), 'F06: sem TELEHEALTH_PROVIDER_ORIGIN nada muda na CSP');
putenv('TELEHEALTH_PROVIDER_ORIGIN=javascript:alert(1)');
ok(SecurityHeaders::allowTelehealthProviderOrigin() === null, 'F06: origem inválida é ignorada');
putenv('TELEHEALTH_PROVIDER_ORIGIN=https://clinica.daily.co');
ok(SecurityHeaders::allowTelehealthProviderOrigin() === 'https://clinica.daily.co' && str_contains(SecurityHeaders::pagePolicy(), "frame-src 'self' https://clinica.daily.co") && str_contains(SecurityHeaders::pagePolicy(), "connect-src 'self' https://clinica.daily.co"), 'F06: origem configurada entra em frame-src/connect-src da página que pedir');
putenv('TELEHEALTH_PROVIDER_ORIGIN');
$threw = false;
try { TenantContext::setClinicId(1); (new TelehealthService($db))->generateSession(-1); } catch (\Throwable $e) { $threw = true; }
ok($threw, 'F02: TelehealthService legado não gera mais URL fictícia');
ok(TeleconsultationService::isOnlineType('teleconsultation') && !TeleconsultationService::isOnlineType('first_visit'), 'F19: classificação online × presencial');
ok(TelehealthRateLimiter::anonymizeIp('2001:db8:1:2::5') === '2001:db8:1::', 'Minimização de IPv6 (/48)');

ob_end_flush();
echo "\nTeleconsulta v11: {$passed} verificações OK\n";
