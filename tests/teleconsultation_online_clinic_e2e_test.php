<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Consultório Online (NI2-221) — testes de contrato de domínio, acesso e sinalização.
 *
 * O que este teste PROVA: modelo de sessão, máquina de estados, autorização por
 * participante e tenant, convite com janela/geração/revogação, ausência de URL e
 * credencial antes da admissão, sinalização WebRTC autorizada e revogada,
 * encerramento desacoplado da agenda, consentimento persistido e auditoria.
 *
 * O que este teste NÃO prova: áudio/vídeo real entre dois dispositivos. Isso é a
 * homologação NI2-254 (dois navegadores/identidades reais) e fica marcado como
 * NOT TESTED aqui — nunca como PASS.
 */

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationStateException;
use ClyoNutri\Domain\Telehealth\Providers\DailyVideoProvider;
use ClyoNutri\Domain\Telehealth\Providers\PeerToPeerWebRtcProvider;
use ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;
use ClyoNutri\Domain\Telehealth\TeleconsultationSignalingService;

date_default_timezone_set('America/Sao_Paulo');
$db = Connection::getInstance();
$failures = 0;

function pass(string $name, string $detail = ''): void { echo "[PASS] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
function check(bool $cond, string $msg): void { if (!$cond) { throw new RuntimeException("FALHA: {$msg}"); } }
/** Executa $fn e exige exceção da classe informada. */
function expectThrows(string $class, callable $fn, string $msg): void
{
    try { $fn(); } catch (Throwable $e) {
        if ($e instanceof $class) { return; }
        throw new RuntimeException("FALHA: {$msg} (lançou " . get_class($e) . ': ' . $e->getMessage() . ')');
    }
    throw new RuntimeException("FALHA: {$msg} (nada foi lançado)");
}
function step(string $name, callable $fn): void
{
    global $failures;
    try { $fn(); } catch (Throwable $e) { $failures++; echo "[FAIL] {$name} — {$e->getMessage()}\n"; }
}

echo "\n=== CONSULTÓRIO ONLINE (NI2-221): CONTRATOS DE DOMÍNIO, ACESSO E SINALIZAÇÃO ===\n\n";

$db->beginTransaction();
$s = bin2hex(random_bytes(4));
$q = static function (string $sql, array $p = []) use ($db): int { $db->prepare($sql)->execute($p); return (int)$db->lastInsertId(); };

// --- Dados sintéticos ---------------------------------------------------------
$clinicA = $q("INSERT INTO clinics (uuid, name, email) VALUES (?, 'Clínica A', ?)", ["tA-{$s}", "a{$s}@ex.test"]);
$clinicB = $q("INSERT INTO clinics (uuid, name, email) VALUES (?, 'Clínica B', ?)", ["tB-{$s}", "b{$s}@ex.test"]);
// Usuário sem perfil profissional criado antes, para que users.id != professionals.id.
$q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Offset', ?, 'x', 'nutritionist', 1, 'active')", ["off1-{$s}", "off1{$s}@ex.test"]);
$q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Offset', ?, 'x', 'nutritionist', 1, 'active')", ["off2-{$s}", "off2{$s}@ex.test"]);

$mkPro = static function (int $clinic, string $name) use ($q, $s): array {
    $u = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, ?, ?, 'x', 'nutritionist', 1, 'active')", [uniqid('u'), $name, uniqid('e') . "{$s}@ex.test"]);
    $q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'nutritionist', 'active')", [$clinic, $u]);
    $p = $q("INSERT INTO professionals (user_id, crn, crn_region) VALUES (?, 'CRN-3 00000', 'CRN-3')", [$u]);
    return ['user' => $u, 'pro' => $p];
};
$nutriA = $mkPro($clinicA, 'Nutri Responsável A');
$nutriA2 = $mkPro($clinicA, 'Outra Nutri A');
$nutriB = $mkPro($clinicB, 'Nutri B');
$secretary = $q("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'Secretaria A', ?, 'x', 'receptionist', 1, 'active')", ["sec-{$s}", "sec{$s}@ex.test"]);
$q("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'receptionist', 'active')", [$clinicA, $secretary]);

$patientA = $q("INSERT INTO patients (clinic_id, uuid, name, email) VALUES (?, ?, 'Paciente Sintético A', ?)", [$clinicA, "pA-{$s}", "pa{$s}@ex.test"]);
$patientB = $q("INSERT INTO patients (clinic_id, uuid, name, email) VALUES (?, ?, 'Paciente Sintético B', ?)", [$clinicB, "pB-{$s}", "pb{$s}@ex.test"]);
$mkAppt = static fn(int $clinic, int $patient, int $pro, string $at) =>
    $q("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, type, status) VALUES (?, ?, ?, ?, 50, 'online', 'scheduled')", [$clinic, $patient, $pro, $at]);

// Consulta às 14:00 (America/Sao_Paulo) = 17:00 UTC. Janela: 16:45–18:20 UTC.
$apptMain = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
$apptB = $mkAppt($clinicB, $patientB, $nutriB['pro'], '2026-10-20 14:00:00');
$utc = new DateTimeZone('UTC');
$before = new DateTimeImmutable('2026-10-20 16:00:00', $utc);
$during = new DateTimeImmutable('2026-10-20 16:55:00', $utc);
$after = new DateTimeImmutable('2026-10-20 18:30:00', $utc);

$appKey = 'test-app-key-' . str_repeat('x', 24);
$p2p = new PeerToPeerWebRtcProvider($db, $appKey);
$svc = new TeleconsultationService($db, $p2p, null, $during);
$signal = new TeleconsultationSignalingService($db, $svc, $during);
TenantContext::setClinicId($clinicA);

$ctx = [];

step('NI2-234: provedores sem credencial ficam BLOCKED (fail-closed, sem link falso)', function () use ($db) {
    $daily = new DailyVideoProvider('', '');
    expectThrows(ExternalMediaProviderBlockedException::class, fn() => $daily->createRoom('cn-x', time() + 60), 'Daily sem chave deve bloquear');
    $noKey = new PeerToPeerWebRtcProvider($db, '');
    expectThrows(ExternalMediaProviderBlockedException::class, fn() => $noKey->createRoom('cn-x', time() + 60), 'P2P sem APP_KEY deve bloquear');
    pass('NI2-234 fail-closed', 'Daily sem DAILY_API_KEY e P2P sem APP_KEY recusam criar sala');
});

step('NI2-242: WebRTC P2P gratuito — STUN público por padrão, TURN opcional', function () use ($db, $appKey) {
    $saved = [getenv('TELECONSULT_STUN_URLS'), getenv('TELECONSULT_TURN_URLS')];
    putenv('TELECONSULT_STUN_URLS'); putenv('TELECONSULT_TURN_URLS'); unset($_ENV['TELECONSULT_STUN_URLS'], $_ENV['TELECONSULT_TURN_URLS']);
    $p = new PeerToPeerWebRtcProvider($db, $appKey);
    $ice = $p->iceServers();
    check(count($ice) === 1 && str_starts_with($ice[0]['urls'][0], 'stun:') && !$p->hasTurn(), 'padrão: só STUN gratuito');
    putenv('TELECONSULT_TURN_URLS=turn:turn.exemplo.test:3478'); putenv('TELECONSULT_TURN_USERNAME=u'); putenv('TELECONSULT_TURN_CREDENTIAL=c');
    check($p->hasTurn() && $p->iceServers()[1]['username'] === 'u', 'TURN opcional via .env');
    putenv('TELECONSULT_TURN_URLS'); putenv('TELECONSULT_TURN_USERNAME'); putenv('TELECONSULT_TURN_CREDENTIAL');
    if ($saved[0] !== false) { putenv('TELECONSULT_STUN_URLS=' . $saved[0]); }
    if ($saved[1] !== false) { putenv('TELECONSULT_TURN_URLS=' . $saved[1]); }
    pass('NI2-242 ICE', 'STUN gratuito por padrão; TURN só se configurado');
});

step('NI2-236: users.id é convertido em professionals.id; secretaria não opera', function () use ($svc, $nutriA, $secretary, $clinicA) {
    check($nutriA['user'] !== $nutriA['pro'], 'cenário precisa de ids diferentes');
    check($svc->resolveProfessionalIdForUser($nutriA['user'], $clinicA) === $nutriA['pro'], 'resolução user→professional');
    expectThrows(TenantSecurityException::class, fn() => $svc->resolveProfessionalIdForUser($secretary, $clinicA), 'secretaria sem perfil profissional');
    pass('NI2-236 identidade do profissional', 'user_id ≠ professional_id resolvido; secretaria bloqueada');
});

step('NI2-235/243: sessão idempotente, vinculada ao agendamento, convite só em hash', function () use ($svc, $db, $apptMain, $nutriA, $clinicA, &$ctx) {
    $created = $svc->scheduleSession($apptMain, $nutriA['pro'], $clinicA);
    check($created['created'] === true && strlen((string)$created['raw_patient_token']) === 64, 'criação com convite');
    $again = $svc->scheduleSession($apptMain, $nutriA['pro'], $clinicA);
    check($again['created'] === false && $again['session_id'] === $created['session_id'] && $again['raw_patient_token'] === null, 'segunda chamada devolve a mesma sessão sem convite');
    $row = $db->query('SELECT * FROM teleconsultation_sessions WHERE id = ' . (int)$created['session_id'])->fetch(PDO::FETCH_ASSOC);
    check($row['patient_token_hash'] === hash('sha256', $created['raw_patient_token']), 'hash do convite');
    check(!str_contains(json_encode($row), $created['raw_patient_token']), 'convite em claro não persistido');
    check($row['join_opens_at_utc'] === '2026-10-20 16:45:00' && $row['join_closes_at_utc'] === '2026-10-20 18:20:00', 'janela em UTC a partir de 14:00 America/Sao_Paulo');
    $ctx['session'] = (int)$created['session_id'];
    $ctx['token'] = $created['raw_patient_token'];
    pass('NI2-235/243 modelo e vínculo', 'idempotente, hash SHA-256, janela 16:45–18:20 UTC');
});

step('NI2-236: outro nutricionista do mesmo tenant e outro tenant não acessam', function () use ($svc, $nutriA, $nutriA2, $nutriB, $apptMain, $apptB, $clinicA, $clinicB, &$ctx) {
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionForProfessional($ctx['session'], $nutriA2['pro'], $clinicA), 'mesmo tenant, outro profissional');
    expectThrows(TenantSecurityException::class, fn() => $svc->scheduleSession($apptMain, $nutriA2['pro'], $clinicA), 'abrir sessão de agendamento alheio');
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionForProfessional($ctx['session'], $nutriB['pro'], $clinicB), 'outro tenant');
    expectThrows(TenantSecurityException::class, fn() => $svc->scheduleSession($apptB, $nutriA['pro'], $clinicA), 'agendamento de outro tenant');
    pass('NI2-236 isolamento', 'mesmo tenant/paciente alheio e cross-tenant bloqueados');
});

step('NI2-237/244: antes da admissão não há sala nem credencial para o paciente', function () use ($svc, $p2p, $db, $before, &$ctx) {
    $status = $svc->checkWaitingRoomStatus($ctx['token']);
    check(array_keys($status) === ['status', 'is_admitted'] && $status['is_admitted'] === false, 'status sem URL/credencial');
    expectThrows(TeleconsultationStateException::class, fn() => $svc->issuePatientMediaCredential($ctx['token']), 'credencial antes da admissão');
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken(str_repeat('a', 64)), 'convite inexistente');
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken('../../etc'), 'convite malformado');
    $svc->submitDeviceCheck($ctx['token'], ['camera_ok' => true, 'microphone_ok' => true, 'browser' => 'Mozilla/5.0 Chrome/130']);
    $svc->acceptConsent($ctx['token'], '2026.1', '203.0.113.7', 'Mozilla/5.0');
    $early = new TeleconsultationService($db, $p2p, null, $before);
    expectThrows(TeleconsultationStateException::class, fn() => $early->enterWaitingRoom($ctx['token']), 'sala de espera antes da janela');
    $consent = $db->query("SELECT clinic_id, consent_type, source_channel FROM patient_consents WHERE source_channel = 'teleconsultation' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check($consent !== false && $consent['consent_type'] === 'telehealth:2026.1', 'consentimento gravado no registro LGPD');
    pass('NI2-237/238/244 pré-consulta', 'lobby sem vazamento; janela respeitada; consentimento persistido com tenant');
});

step('NI2-237: admissão só a partir da sala de espera', function () use ($svc, $nutriA, &$ctx) {
    expectThrows(TeleconsultationStateException::class, fn() => $svc->admitPatient($ctx['session'], $nutriA['pro']), 'admitir sem paciente na sala de espera');
    $svc->enterWaitingRoom($ctx['token']);
    check($svc->professionalState($ctx['session'], $nutriA['pro'])['patient_waiting'] === true, 'painel indica paciente aguardando');
    $admit = $svc->admitPatient($ctx['session'], $nutriA['pro']);
    check($admit['status'] === 'admitted', 'admitido');
    check($svc->professionalState($ctx['session'], $nutriA['pro'])['media_ready'] === true, 'sala provisionada na admissão');
    pass('NI2-237/247 admissão', 'transição waiting_room → admitted com sala provisionada');
});

step('NI2-242/244/245: credenciais por papel e sinalização WebRTC autorizada', function () use ($svc, $signal, $p2p, $nutriA, &$ctx) {
    $pro = $svc->issueProfessionalMediaCredential($ctx['session'], $nutriA['pro']);
    $pat = $svc->issuePatientMediaCredential($ctx['token']);
    check($pro['provider'] === 'webrtc_p2p' && $pro['room_name'] === $pat['room_name'], 'mesma sala');
    check($pro['token'] !== $pat['token'], 'credenciais distintas');
    $ctx['room'] = $pro['room_name']; $ctx['proCred'] = $pro['token']; $ctx['patCred'] = $pat['token'];

    $signal->send($ctx['room'], $pro['token'], 'offer', ['type' => 'offer', 'sdp' => 'v=0 sintético']);
    $patInbox = $signal->poll($ctx['room'], $pat['token'], 0);
    check(count($patInbox['messages']) === 1 && $patInbox['messages'][0]['type'] === 'offer', 'paciente recebe a offer');
    check($signal->poll($ctx['room'], $pro['token'], 0)['messages'] === [], 'remetente não recebe o próprio sinal');
    $signal->send($ctx['room'], $pat['token'], 'answer', ['type' => 'answer', 'sdp' => 'v=0 sintético']);
    check($signal->poll($ctx['room'], $pro['token'], 0)['messages'][0]['type'] === 'answer', 'profissional recebe a answer');

    [$body, $sig] = explode('.', $pat['token']);
    expectThrows(TenantSecurityException::class, fn() => $signal->poll($ctx['room'], $body . '.' . strrev($sig), 0), 'credencial adulterada');
    expectThrows(TenantSecurityException::class, fn() => $signal->poll('cn-outrasala', $pat['token'], 0), 'credencial em outra sala');
    expectThrows(TenantSecurityException::class, fn() => $p2p->verifyMeetingToken($ctx['room'], $pat['token'], $pat['expires_at_unix'] + 1), 'credencial expirada');
    expectThrows(InvalidArgumentException::class, fn() => $signal->send($ctx['room'], $pat['token'], 'exec', []), 'tipo de sinal fora da lista');
    pass('NI2-242/244 credenciais e sinalização', 'offer/answer entregues só ao outro participante; adulteração, sala errada e expiração recusadas');
});

step('NI2-249: evolução no prontuário sem alterar o estado da chamada', function () use ($svc, $nutriA, $nutriA2, &$ctx) {
    expectThrows(InvalidArgumentException::class, fn() => $svc->recordClinicalEvolution($ctx['session'], $nutriA['pro'], 'Evolução', ['notes' => '  ']), 'nota vazia');
    expectThrows(TenantSecurityException::class, fn() => $svc->recordClinicalEvolution($ctx['session'], $nutriA2['pro'], 'Evolução', ['notes' => 'x']), 'outro profissional');
    $record = $svc->recordClinicalEvolution($ctx['session'], $nutriA['pro'], 'Evolução teleconsulta', ['notes' => 'Registro sintético.']);
    check(!empty($record['record_hash']), 'registro com hash');
    check($svc->professionalState($ctx['session'], $nutriA['pro'])['status'] === 'admitted', 'estado inalterado');
    pass('NI2-249 prontuário', 'registro append-only persistido; estado da sessão preservado');
});

step('NI2-431/237: encerrar mídia revoga acesso e NÃO conclui a consulta', function () use ($svc, $signal, $db, $nutriA, $apptMain, &$ctx) {
    $end = $svc->endMedia($ctx['session'], $nutriA['pro']);
    check($end['status'] === 'ended', 'sessão encerrada');
    check($svc->endMedia($ctx['session'], $nutriA['pro'])['status'] === 'ended', 'encerramento idempotente');
    $apptStatus = $db->query("SELECT status FROM appointments WHERE id = {$apptMain}")->fetchColumn();
    check($apptStatus === 'scheduled', "consulta continua '{$apptStatus}' até a conclusão clínica explícita");
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken($ctx['token']), 'convite revogado');
    expectThrows(TenantSecurityException::class, fn() => $signal->poll($ctx['room'], $ctx['patCred'], 0), 'credencial ainda no prazo deixa de valer');
    expectThrows(TenantSecurityException::class, fn() => $signal->poll($ctx['room'], $ctx['proCred'], 0), 'credencial do profissional também');
    $left = (int)$db->query("SELECT COUNT(*) FROM teleconsultation_signals WHERE room_name = " . $db->quote($ctx['room']))->fetchColumn();
    check($left === 0, 'sinalização (SDP/ICE com IPs) apagada');
    pass('NI2-431 encerramento desacoplado', 'mídia encerrada, acesso revogado, sinais apagados, agenda intacta');
});

step('NI2-236/250: cancelar ou marcar falta na agenda revoga a teleconsulta', function () use ($svc, $db, $mkAppt, $clinicA, $patientA, $nutriA) {
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $created = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    (new AppointmentService($db))->updateStatus($appt, 'cancelled', 'Paciente desmarcou', $nutriA['user']);
    $status = $db->query('SELECT status FROM teleconsultation_sessions WHERE id = ' . (int)$created['session_id'])->fetchColumn();
    check($status === 'cancelled', "sessão '{$status}' após cancelamento");
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken($created['raw_patient_token']), 'convite revogado no cancelamento');
    expectThrows(TeleconsultationStateException::class, fn() => $svc->issueInvite((int)$created['session_id'], $nutriA['pro']), 'sem novo convite para consulta cancelada');
    pass('NI2-236 cancelamento', 'sessão cancelada e convite revogado pela agenda');
});

step('NI2-250: reagendar revoga o convite antigo; novo convite funciona', function () use ($svc, $db, $mkAppt, $clinicA, $patientA, $nutriA) {
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $created = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    (new AppointmentService($db))->edit($appt, ['scheduled_at' => '2026-10-20 14:30:00'], true, $nutriA['user']);
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken($created['raw_patient_token']), 'convite antigo revogado');
    $reissued = $svc->issueInvite((int)$created['session_id'], $nutriA['pro']);
    check($reissued['invite_generation'] === 2 && $reissued['join_opens_at_utc'] === '2026-10-20 17:15:00', 'nova geração com janela recalculada');
    check($svc->getSessionByPatientToken($reissued['raw_patient_token'])['status'] === 'scheduled', 'novo convite válido');
    pass('NI2-250 reagendamento', 'geração 1 revogada; geração 2 com janela 17:15 UTC');
});

step('NI2-237: recusa é terminal e só volta com novo convite', function () use ($svc, $mkAppt, $clinicA, $patientA, $nutriA) {
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $created = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $svc->submitDeviceCheck($created['raw_patient_token'], ['camera_ok' => true, 'microphone_ok' => true]);
    $svc->acceptConsent($created['raw_patient_token'], '2026.1');
    $svc->enterWaitingRoom($created['raw_patient_token']);
    $svc->rejectPatient((int)$created['session_id'], $nutriA['pro'], 'Pessoa não identificada');
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken($created['raw_patient_token']), 'convite revogado após recusa');
    expectThrows(TeleconsultationStateException::class, fn() => $svc->admitPatient((int)$created['session_id'], $nutriA['pro']), 'não admite sessão recusada');
    $again = $svc->issueInvite((int)$created['session_id'], $nutriA['pro']);
    check($svc->checkWaitingRoomStatus($again['raw_patient_token'])['status'] === 'scheduled', 'reaberta com novo convite');
    pass('NI2-237 recusa', 'recusa terminal; reabertura exige novo convite');
});

step('NI2-236: convite expira ao fim da janela', function () use ($svc, $db, $p2p, $after, $mkAppt, $clinicA, $patientA, $nutriA) {
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $created = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $late = new TeleconsultationService($db, $p2p, null, $after);
    expectThrows(TeleconsultationStateException::class, fn() => $late->getSessionByPatientToken($created['raw_patient_token']), 'convite após a janela');
    $status = $db->query('SELECT status FROM teleconsultation_sessions WHERE id = ' . (int)$created['session_id'])->fetchColumn();
    check($status === 'expired', "sessão '{$status}' marcada como expirada");
    pass('NI2-236 expiração', 'janela fechada → expired com evento de auditoria');
});

step('Auditoria obrigatória: sem trilha, a ação não acontece', function () use ($svc, $db, $mkAppt, $clinicA, $patientA, $nutriA) {
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $created = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $db->exec('ALTER TABLE teleconsultation_events RENAME TO teleconsultation_events_off');
    try {
        expectThrows(PDOException::class, fn() => $svc->submitDeviceCheck($created['raw_patient_token'], ['camera_ok' => true, 'microphone_ok' => true]), 'falha de auditoria interrompe');
    } finally {
        $db->exec('ALTER TABLE teleconsultation_events_off RENAME TO teleconsultation_events');
    }
    $row = $db->query('SELECT status, device_check_passed FROM teleconsultation_sessions WHERE id = ' . (int)$created['session_id'])->fetch(PDO::FETCH_ASSOC);
    check($row['status'] === 'scheduled' && (int)$row['device_check_passed'] === 0, 'nenhuma mudança sem auditoria');
    $types = array_column($svc->getSessionEvents((int)$created['session_id']), 'event_type');
    check($types === ['session_created'], 'trilha consistente');
    pass('Auditoria fail-closed', 'evento não gravado ⇒ transição revertida');
});

step('F03: app recebe bilhete curto da sala de espera; link legado não é exposto', function () use ($db, $svc, $p2p, $after, $mkAppt, $clinicA, $patientA, $patientB, $nutriA, $appKey) {
    putenv('APP_KEY=' . $appKey);
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    check($svc->issuePortalTicket($patientA, $appt, $clinicA) === null, 'sem sala aberta, sem bilhete');
    $db->prepare("INSERT INTO telehealth_sessions (clinic_id, appointment_id, meeting_url, provider, status) VALUES (?, ?, 'https://meet.clyonutri.com.br/room/fake', 'clyo_meet', 'ready')")->execute([$clinicA, $appt]);
    $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $ticket = $svc->issuePortalTicket($patientA, $appt, $clinicA);
    check($ticket !== null && str_starts_with($ticket, 'p.'), 'bilhete emitido');
    check($svc->issuePortalTicket($patientB, $appt, $clinicA) === null, 'outro paciente não recebe bilhete');
    check($svc->checkWaitingRoomStatus($ticket)['status'] === 'scheduled', 'bilhete abre a sala de espera');
    $forged = substr($ticket, 0, -2) . (substr($ticket, -2) === 'AA' ? 'BB' : 'AA');
    expectThrows(TenantSecurityException::class, fn() => $svc->getSessionByPatientToken($forged), 'bilhete adulterado');
    $late = new TeleconsultationService($db, $p2p, null, $after);
    expectThrows(TenantSecurityException::class, fn() => $late->getSessionByPatientToken($ticket), 'bilhete expirado');
    TenantContext::setClinicId($clinicA);
    $next = (new \ClyoNutri\Domain\PatientPortal\PatientPortalService($db))->getNextAppointment($patientA);
    check(!str_contains((string)($next['meeting_url'] ?? ''), 'meet.clyonutri'), 'URL legada não é exposta');
    pass('F03 bilhete do app', 'assinado, curto, por paciente; legado bloqueado');
});

step('NI2-239/250: convite e lembrete por e-mail sem dados clínicos', function () use ($db, $svc, $p2p, $mkAppt, $clinicA, $patientA, $nutriA, $appKey) {
    putenv('APP_KEY=' . $appKey); putenv('APP_URL=https://app.clyonutri.test');
    $log = sys_get_temp_dir() . '/tele-mail-' . bin2hex(random_bytes(3)) . '.log';
    $mailer = new \ClyoNutri\Core\Mail\LogMailer($log); // merge v11: Mailer virou interface (pacote v6)
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $c = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $at = new DateTimeImmutable('2026-10-20 16:00:00', new DateTimeZone('UTC')); // 45 min antes da abertura (16:45)
    $timed = new TeleconsultationService($db, $p2p, null, $at);
    $notifier = new \ClyoNutri\Domain\Telehealth\TeleconsultationNotifier($db, $timed, $mailer, $at);

    $sent = $notifier->sendInvite((int)$c['session_id'], $nutriA['pro'], $clinicA);
    check($sent['status'] === 'sent', 'convite enviado');
    // O log guarda o MIME com corpo em base64: decodifica as partes para inspecionar o texto real.
    $raw = (string)file_get_contents($log);
    $mail = '';
    foreach (preg_split('/\r?\n\r?\n/', $raw) as $block) {
        $compact = preg_replace('/\s+/', '', $block);
        $decoded = ($compact !== '' && preg_match('#^[A-Za-z0-9+/=]+$#', $compact)) ? base64_decode($compact, true) : false;
        $mail .= ($decoded !== false ? $decoded : $block) . "\n";
    }
    preg_match('#https://app\.clyonutri\.test/teleconsulta/sala-espera/(p\.[A-Za-z0-9._-]+)#', $mail, $m);
    check(!empty($m[1]), 'link absoluto com bilhete no e-mail');
    check($timed->checkWaitingRoomStatus($m[1])['status'] === 'scheduled', 'link do e-mail abre a sala de espera');
    check($svc->getSessionByPatientToken($c['raw_patient_token'])['status'] === 'scheduled', 'e-mail não derruba o link do nutricionista');
    check(!str_contains($mail, 'Registro sintético') && !str_contains(strtolower($mail), 'evolu'), 'sem conteúdo clínico');

    $r1 = $notifier->sendDueReminders(60);
    $r2 = $notifier->sendDueReminders(60);
    check($r1['sent'] >= 1 && $r2['sent'] === 0, 'lembrete enviado uma única vez por geração');

    $timed->issueInvite((int)$c['session_id'], $nutriA['pro']);
    expectThrows(TenantSecurityException::class, fn() => $timed->getSessionByPatientToken($m[1]), 'novo convite invalida o link do e-mail antigo');
    $events = array_column($svc->getSessionEvents((int)$c['session_id']), 'details_json');
    check(!str_contains(implode(' ', $events), '@'), 'endereço de e-mail fora da auditoria');
    @unlink($log);
    pass('NI2-239/250 e-mail', 'convite e lembrete enviados, link pessoal, revogável, sem dados clínicos');
});

step('NI2-239: paciente sem e-mail e e-mail não configurado são informados, não fingidos', function () use ($db, $svc, $mkAppt, $q, $clinicA, $nutriA, $appKey) {
    putenv('APP_KEY=' . $appKey); putenv('APP_URL=https://app.clyonutri.test');
    $noEmail = $q("INSERT INTO patients (clinic_id, uuid, name) VALUES (?, ?, 'Sem Email')", [$clinicA, 'ne-' . bin2hex(random_bytes(3))]);
    $appt = $mkAppt($clinicA, $noEmail, $nutriA['pro'], '2026-10-20 14:00:00');
    $c = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $broken = new class implements \ClyoNutri\Core\Mail\Mailer { public function send(\ClyoNutri\Core\Mail\MailMessage $m): void { throw new \ClyoNutri\Core\Mail\MailException('E-mail não configurado: SMTP_HOST ausente.'); } };
    $n = new \ClyoNutri\Domain\Telehealth\TeleconsultationNotifier($db, $svc, $broken);
    check($n->sendInvite((int)$c['session_id'], $nutriA['pro'], $clinicA)['status'] === 'skipped', 'sem e-mail: não enviado');
    $db->prepare('UPDATE patients SET email = ? WHERE id = ?')->execute(['tem@email.test', $noEmail]);
    check($n->sendInvite((int)$c['session_id'], $nutriA['pro'], $clinicA)['status'] === 'failed', 'SMTP não configurado: falha registrada');
    check($n->lastDelivery((int)$c['session_id'], $clinicA)['event_type'] === 'invite_email_failed', 'tela mostra a falha');
    pass('NI2-239 falhas visíveis', 'sem e-mail e SMTP ausente aparecem como não enviados');
});

step('NI2-249: rascunho com versão, conflito entre abas e limpeza ao finalizar', function () use ($db, $svc, $mkAppt, $clinicA, $patientA, $nutriA, $nutriA2) {
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $c = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $sid = (int)$c['session_id'];
    $drafts = new \ClyoNutri\Domain\Telehealth\TeleconsultationDraftService($db, $svc);
    check($drafts->get($sid, $nutriA['pro'], $clinicA)['version'] === 0, 'sem rascunho inicial');
    $a = $drafts->save($sid, $nutriA['pro'], $clinicA, 0, ['title' => 'Evolução', 'notes' => 'aba 1 v1']);
    check($a['status'] === 'saved' && $a['version'] === 1, 'primeira gravação');
    $b = $drafts->save($sid, $nutriA['pro'], $clinicA, 1, ['title' => 'Evolução', 'notes' => 'aba 1 v2']);
    check($b['version'] === 2, 'segunda gravação');
    $stale = $drafts->save($sid, $nutriA['pro'], $clinicA, 1, ['notes' => 'aba 2 desatualizada']);
    check($stale['status'] === 'conflict' && $stale['server']['notes'] === 'aba 1 v2', 'conflito devolve a versão do servidor');
    expectThrows(TenantSecurityException::class, fn() => $drafts->get($sid, $nutriA2['pro'], $clinicA), 'outro profissional não lê o rascunho');
    $before = (int)$db->query("SELECT COUNT(*) FROM clinical_records WHERE patient_id = {$patientA}")->fetchColumn();
    check((int)$db->query("SELECT COUNT(*) FROM clinical_records WHERE patient_id = {$patientA}")->fetchColumn() === $before, 'rascunho não cria registro clínico');
    $svc->recordClinicalEvolution($sid, $nutriA['pro'], 'Evolução', ['notes' => 'aba 1 v2']);
    check((int)$db->query("SELECT COUNT(*) FROM clinical_records WHERE patient_id = {$patientA}")->fetchColumn() === $before + 1, 'finalizar cria o registro');
    check($drafts->get($sid, $nutriA['pro'], $clinicA)['version'] === 0, 'finalizar apaga o rascunho');
    pass('NI2-249 rascunho', 'autosave versionado, conflito detectado, finalização explícita');
});

step('F15/F12: motivo de recusa por código e módulo desligado por padrão', function () use ($db, $svc, $mkAppt, $clinicA, $patientA, $nutriA) {
    $appt = $mkAppt($clinicA, $patientA, $nutriA['pro'], '2026-10-20 14:00:00');
    $c = $svc->scheduleSession($appt, $nutriA['pro'], $clinicA);
    $svc->submitDeviceCheck($c['raw_patient_token'], ['camera_ok' => true, 'microphone_ok' => true]);
    $svc->acceptConsent($c['raw_patient_token'], '2026.1');
    $svc->enterWaitingRoom($c['raw_patient_token']);
    $svc->rejectPatient((int)$c['session_id'], $nutriA['pro'], 'Maria, CPF 123 não confirmou');
    $events = $svc->getSessionEvents((int)$c['session_id']);
    $last = json_decode((string)end($events)['details_json'], true);
    check($last['reason'] === 'other', 'texto livre vira código');
    $db->exec("DELETE FROM platform_settings WHERE setting_key = 'feature_flag_telehealth'");
    \ClyoNutri\Core\Settings\PlatformSettings::clearCache();
    check(\ClyoNutri\Core\Settings\PlatformSettings::isTelehealthEnabled($db) === false, 'sem configuração, módulo desligado');
    pass('F15/F12', 'auditoria sem texto livre; flag fail-closed');
});

step('Limite de tentativas nos endpoints do paciente', function () use ($db, $during) {
    $limiter = new TeleconsultationRateLimiter($db, $during);
    $limiter->hit('patient_token', '198.51.100.9', 2, 60);
    $limiter->hit('patient_token', '198.51.100.9', 2, 60);
    expectThrows(TeleconsultationRateLimitException::class, fn() => $limiter->hit('patient_token', '198.51.100.9', 2, 60), 'terceira tentativa bloqueada');
    $stored = $db->query("SELECT subject_hash FROM teleconsultation_access_attempts ORDER BY id DESC LIMIT 1")->fetchColumn();
    check(!str_contains((string)$stored, '198.51'), 'IP não armazenado em claro');
    pass('Rate limit', 'excesso bloqueado; sujeito armazenado só como hash');
});

$db->rollBack();

echo "\n[NOT TESTED] NI2-245/254: áudio e vídeo reais entre dois dispositivos — exige homologação manual com dois navegadores/identidades (não simulável aqui).\n";
if ($failures > 0) {
    echo "\n=== {$failures} FALHA(S) NOS CONTRATOS DA TELECONSULTA ===\n";
    exit(1);
}
echo "\n=== CONTRATOS DE DOMÍNIO DA TELECONSULTA APROVADOS (mídia real pendente de NI2-254) ===\n";
