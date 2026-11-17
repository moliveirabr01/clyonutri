<?php
/**
 * v54.61 — NI2-830 (SEC-001): consentimento verificável do paciente antes da transcrição (camada de domínio, SQLite real).
 * A prova de ponta a ponta no navegador está em tests/e2e/scenarios/ni2830.js. Dados só de TESTE.
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5461.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\RealtimeTranscriptionService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms;

$db = Connection::getInstance();
$cA = Fx::clinic($db, 'Clínica TESTE 830 A');
$cB = Fx::clinic($db, 'Clínica TESTE 830 B');
$uA = Fx::user($db, $cA, 'Nutri TESTE 830 A', 'owner');
$uB = Fx::user($db, $cB, 'Nutri TESTE 830 B', 'owner');
$pA = Fx::patient($db, $cA, 'Ana TESTE 830');
$pB = Fx::patient($db, $cA, 'Bruno TESTE 830');
$pC = Fx::patient($db, $cB, 'Carla TESTE 830');
$apA1 = Fx::appointment($db, $cA, $pA, (int)$uA['professional_id']);
$apA2 = Fx::appointment($db, $cA, $pA, (int)$uA['professional_id']);
$apB1 = Fx::appointment($db, $cA, $pB, (int)$uA['professional_id']);
$apC = Fx::appointment($db, $cB, $pC, (int)$uB['professional_id']);
Fx::act($uA);
$svc = new TranscriptionConsentService($db);
$stt = new RealtimeTranscriptionService($db, $svc);
$sha = TranscriptionConsentTerms::sha256();
$ip = '203.0.113.7';
$ua = 'Mozilla/5.0 (TESTE)';
$uid = (int)$uA['user_id'];

fwrite(STDOUT, "=== NI2-830 consentimento verificável ===\n");

// Migration 93: idempotente e com os índices exigidos
require_once dirname(__DIR__) . '/database/migrations/2026_11_13_000093_create_transcription_consent_requests.php';
(new CreateTranscriptionConsentRequests())->up($db);
(new CreateTranscriptionConsentRequests())->up($db);
T::ok(true, 'migration 93 roda duas vezes sem erro (idempotente)');
$idx = array_column($db->query("PRAGMA index_list('consultation_ai_consent_requests')")->fetchAll(), 'name');
T::ok(in_array('uq_ai_consent_req_token', $idx, true) && in_array('idx_ai_consent_req_appt', $idx, true), 'índices do token (UNIQUE) e do atendimento existem');
T::ok(!method_exists($svc, 'grantConsent'), 'o serviço não tem mais grantConsent(): nenhum caminho de código concede aceite pelo profissional');

// Critério 1: sem aceite, nada começa; linha antiga "granted" feita só pelo profissional não vale
T::ok($svc->isConsentActive($apA1, $pA) === false, 'sem aceite: isConsentActive=false');
$db->prepare("INSERT INTO consultation_ai_consents (clinic_id, appointment_id, patient_id, user_id, status, terms_version, consented_at_utc) VALUES (?, ?, ?, ?, 'granted', 'v1.0', ?)")
    ->execute([$cA, $apA1, $pA, $uid, gmdate('Y-m-d H:i:s')]);
T::ok($svc->isConsentActive($apA1, $pA) === false, 'linha legada "granted" da tabela antiga NÃO conta como aceite');
T::throws(fn() => $stt->startSession($apA1, $pA, (int)$uA['professional_id']), 'startSession sem aceite do paciente é negado', 'Consentimento');

// Pedido: o profissional recebe só um token em claro; no banco fica só o hash
$req = $svc->createRequest($apA1, $pA, $uid);
$token = (string)$req['token'];
T::ok(preg_match('/^[A-Z2-7]{20}$/', $token) === 1 && $req['consent']['state'] === 'pending', 'pedido pendente com token de 20 caracteres base32 (100 bits)');
$row = $db->query('SELECT * FROM consultation_ai_consent_requests WHERE id = ' . (int)$req['consent']['id'])->fetch();
T::ok(!str_contains(json_encode($row), $token) && strlen($row['token_hash']) === 64, 'token guardado só como hash SHA-256 (não aparece em claro em nenhuma coluna)');
T::ok($svc->isConsentActive($apA1, $pA) === false, 'pedido pendente não libera a captação');
T::throws(fn() => $stt->startSession($apA1, $pA, (int)$uA['professional_id']), 'startSession com pedido pendente é negado', 'Consentimento');
$ttl = strtotime($row['expires_at_utc'] . ' UTC') - time();
T::ok($ttl > 800 && $ttl <= 900, "validade do link ~15 min ({$ttl}s)");
T::ok(!isset($req['consent']['token_hash']) && !array_key_exists('decision_ip_hash', $req['consent']), 'visão do profissional não expõe hashes nem dados do aparelho do paciente');

// Texto errado / decisão inválida
try { $svc->decideByToken($token, 'accept', str_repeat('0', 64), $ip, $ua); T::ok(false, 'aceite com hash de texto diferente deveria falhar'); } catch (DomainException $e) { T::ok(true, 'aceite com texto desatualizado/diferente é recusado'); }
try { $svc->decideByToken($token, 'talvez', $sha, $ip, $ua); T::ok(false, 'decisão inválida deveria falhar'); } catch (DomainException $e) { T::ok(true, 'decisão diferente de accept/reject é recusada'); }
try { $svc->decideByToken('AAAAAAAAAAAAAAAAAAAA', 'accept', $sha, $ip, $ua); T::ok(false, 'token inexistente deveria falhar'); } catch (DomainException $e) { T::ok(true, 'token inexistente é recusado'); }
T::ok($svc->isConsentActive($apA1, $pA) === false, 'tentativas inválidas não criaram aceite');

// Critério 2: paciente aceita; evidência completa e mínima
try { $svc->decideByToken($token, 'accept', $sha, $ip, $ua); T::ok(false, 'aceite sem OTP deveria falhar'); } catch (DomainException $e) { T::ok(true, 'SEG360-SEC-001: aceite sem OTP é recusado'); }
$res = $svc->decideByToken($token, 'accept', $sha, $ip, $ua, (string)$req['otp']);
T::ok($res['state'] === 'accepted' && preg_match('/^[a-f0-9]{32}$/', (string)$res['revoke_token']) === 1, 'paciente aceita pelo link: estado accepted + código de revogação');
$row = $db->query('SELECT * FROM consultation_ai_consent_requests WHERE id = ' . (int)$req['consent']['id'])->fetch();
T::ok((int)$row['clinic_id'] === $cA && (int)$row['appointment_id'] === $apA1 && (int)$row['patient_id'] === $pA, 'evidência vinculada a clínica + atendimento + paciente');
T::ok($row['terms_version'] === TranscriptionConsentTerms::VERSION && $row['terms_sha256'] === $sha && $row['decision_mechanism'] === 'otp' && $row['request_origin'] === 'professional_session' && $row['decided_at_utc'] !== null, 'evidência: versão, SHA do texto, mecanismo, origem do pedido e timestamp');
T::ok($row['decision_ip_hash'] === hash_hmac('sha256', $ip, (string)(\ClyoNutri\Core\Security\Env::get('APP_KEY') ?: 'clyonutri-local')), 'IP guardado só como hash HMAC (64 hex), nunca em claro');
T::ok(!str_contains(json_encode($row), $ip), 'IP do paciente não aparece em claro na evidência');
T::ok($svc->isConsentActive($apA1, $pA) === true, 'com aceite do paciente, isConsentActive=true para este atendimento');
$vu = strtotime($row['valid_until_utc'] . ' UTC') - time();
T::ok($vu > 21000 && $vu <= 21600, 'aceite com validade de 6 h (expiração garantida)');
$audit = $db->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('AI_TRANSCRIPTION_CONSENT_REQUESTED','AI_TRANSCRIPTION_CONSENT_ACCEPTED_BY_PATIENT')")->fetchColumn();
T::ok((int)$audit === 2, 'trilha de auditoria: pedido + aceite pelo paciente');
$auditJson = (string)$db->query("SELECT details FROM audit_logs WHERE action = 'AI_TRANSCRIPTION_CONSENT_ACCEPTED_BY_PATIENT'")->fetchColumn();
T::ok(!str_contains($auditJson, 'Ana') && !str_contains($auditJson, $token), 'auditoria do aceite sem nome do paciente nem token');

// Replay: o mesmo token não vale de novo (nem para recusar)
try { $svc->decideByToken($token, 'accept', $sha, $ip, $ua); T::ok(false, 'replay deveria falhar'); } catch (DomainException $e) { T::ok(true, 'replay do mesmo token (aceitar) é recusado'); }
try { $svc->decideByToken($token, 'reject', $sha, $ip, $ua); T::ok(false, 'replay recusar deveria falhar'); } catch (DomainException $e) { T::ok(true, 'replay do mesmo token (recusar) é recusado'); }
T::ok($svc->isConsentActive($apA1, $pA) === true, 'replay não alterou o aceite vigente');

// Isolamento: outra consulta do mesmo paciente, outro paciente, outra clínica
T::ok($svc->isConsentActive($apA2, $pA) === false, 'consulta A2 do MESMO paciente não herda o aceite da A1');
T::ok($svc->isConsentActive($apB1, $pB) === false, 'paciente B não herda o aceite do paciente A');
T::ok($svc->isConsentActive($apA1, $pB) === false, 'atendimento da A1 com paciente B (inconsistência) não vale');
try { $svc->isConsentActive($apA1, $pC); T::ok(false, 'paciente de outra clínica deveria ser negado'); } catch (\Throwable $e) { T::ok(true, 'paciente de outra clínica é negado (tenant)'); }
TenantContext::set($cB, ['id' => $uB['user_id'], 'role' => 'nutritionist']);
T::ok($svc->getConsent($apC, $pC) === null, 'clínica B não enxerga pedido de aceite da clínica A (outro atendimento)');
try { $svc->createRequest($apA1, $pA, (int)$uB['user_id']); T::ok(false, 'clínica B pedindo aceite no atendimento da A deveria falhar'); } catch (\Throwable $e) { T::ok(true, 'clínica B não consegue pedir aceite para atendimento/paciente da clínica A'); }
Fx::act($uA);

// Sessão e segmentos só com aceite vigente; revogação bloqueia tudo
$s = $stt->startSession($apA1, $pA, (int)$uA['professional_id']);
$seg1 = $stt->ingestSegment((int)$s['id'], 1, 'TESTE primeiro trecho');
T::ok($seg1['status'] === 'ingested', 'com aceite válido o segmento é gravado');
$revokedByPatient = $svc->revokeByPatientToken((string)$res['revoke_token'], $ip, $ua);
T::ok($revokedByPatient['state'] === 'revoked' && $svc->isConsentActive($apA1, $pA) === false, 'paciente revoga com o código: aceite deixa de valer');
$sess = $db->query('SELECT status FROM consultation_transcription_sessions WHERE id = ' . (int)$s['id'])->fetchColumn();
T::ok($sess === 'paused', 'revogação pausa a sessão aberta na hora');
T::throws(fn() => $stt->ingestSegment((int)$s['id'], 2, 'TESTE depois da revogação'), 'novo segmento depois da revogação é negado');
T::ok((int)$db->query('SELECT COUNT(*) FROM consultation_transcription_segments WHERE session_id = ' . (int)$s['id'])->fetchColumn() === 1, 'nenhum segmento novo foi gravado após a revogação');
T::throws(fn() => $stt->resumeSession((int)$s['id']), 'retomar sessão sem aceite vigente é negado', 'Consentimento');
T::throws(fn() => $stt->startSession($apA1, $pA, (int)$uA['professional_id']), 'iniciar de novo após revogação é negado', 'Consentimento');
try { $svc->revokeByPatientToken((string)$res['revoke_token'], $ip, $ua); T::ok(false, 'revogar duas vezes deveria falhar'); } catch (DomainException $e) { T::ok(true, 'código de revogação não revoga duas vezes'); }
try { $svc->revokeByPatientToken('zzzz', $ip, $ua); T::ok(false, 'código malformado deveria falhar'); } catch (DomainException $e) { T::ok(true, 'código de revogação malformado é recusado'); }

// Expiração do pedido e do aceite (relógio do banco adiantado de verdade)
$r2 = $svc->createRequest($apA1, $pA, $uid);
$db->prepare("UPDATE consultation_ai_consent_requests SET expires_at_utc = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', time() - 5), (int)$r2['consent']['id']]);
try { $svc->decideByToken((string)$r2['token'], 'accept', $sha, $ip, $ua); T::ok(false, 'token expirado deveria falhar'); } catch (DomainException $e) { T::ok(true, 'token expirado não aceita'); }
T::ok($svc->getConsent($apA1, $pA)['state'] === 'expired', 'estado do pedido vencido é "expired"');
T::ok($svc->isConsentActive($apA1, $pA) === false, 'pedido expirado não libera captação');
$r3 = $svc->createRequest($apA1, $pA, $uid);
$svc->decideByToken((string)$r3['token'], 'accept', $sha, $ip, $ua, (string)$r3['otp']);
T::ok($svc->isConsentActive($apA1, $pA) === true, 'novo pedido aceito volta a liberar (após revogação anterior)');
$db->prepare("UPDATE consultation_ai_consent_requests SET valid_until_utc = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', time() - 5), (int)$r3['consent']['id']]);
T::ok($svc->isConsentActive($apA1, $pA) === false, 'aceite com validade vencida deixa de valer');
$s2 = $db->query("SELECT id FROM consultation_transcription_sessions WHERE appointment_id = {$apA1} ORDER BY id DESC LIMIT 1")->fetchColumn();
T::throws(fn() => $stt->ingestSegment((int)$s2, 9, 'TESTE após expirar'), 'segmento com aceite vencido é negado');

// Recusa
$r4 = $svc->createRequest($apB1, $pB, $uid);
$rej = $svc->decideByToken((string)$r4['token'], 'reject', $sha, $ip, $ua);
T::ok($rej['state'] === 'rejected' && $rej['revoke_token'] === null && $svc->isConsentActive($apB1, $pB) === false, 'paciente recusa: sem aceite e sem código de revogação');
T::throws(fn() => $stt->startSession($apB1, $pB, (int)$uA['professional_id']), 'iniciar após recusa é negado', 'Consentimento');
try { $svc->decideByToken((string)$r4['token'], 'accept', $sha, $ip, $ua); T::ok(false, 'aceitar depois de recusar com o mesmo token deveria falhar'); } catch (DomainException $e) { T::ok(true, 'mesmo token não vira aceite depois da recusa'); }

// Portal: só o próprio paciente decide
$r5 = $svc->createRequest($apB1, $pB, $uid);
try { $svc->decideByPortal((int)$r5['consent']['id'], $pA, $cA, 'accept', $sha, $ip, $ua); T::ok(false, 'paciente A decidindo pedido do paciente B deveria falhar'); } catch (DomainException $e) { T::ok(true, 'portal: paciente A não decide o pedido do paciente B'); }
try { $svc->decideByPortal((int)$r5['consent']['id'], $pB, $cB, 'accept', $sha, $ip, $ua); T::ok(false, 'clínica errada deveria falhar'); } catch (DomainException $e) { T::ok(true, 'portal: clínica diferente não decide'); }
T::ok(count(array_filter($svc->listForPatient($pB, $cA), fn($i) => $i['id'] === (int)$r5['consent']['id'])) === 1 && count(array_filter($svc->listForPatient($pA, $cA), fn($i) => $i['id'] === (int)$r5['consent']['id'])) === 0, 'portal lista o pedido só para o paciente dono');
$pv = $svc->listForPatient($pB, $cA)[0];
T::ok(!array_key_exists('patient_name', $pv) && $pv['patient_first_name'] === 'Bruno', 'página do paciente mostra só o primeiro nome');
$ok = $svc->decideByPortal((int)$r5['consent']['id'], $pB, $cA, 'accept', $sha, $ip, $ua);
T::ok($ok['state'] === 'accepted' && $svc->isConsentActive($apB1, $pB) === true, 'portal: o próprio paciente aceita');
T::ok($db->query("SELECT decision_mechanism FROM consultation_ai_consent_requests WHERE id = " . (int)$r5['consent']['id'])->fetchColumn() === 'patient_portal', 'mecanismo registrado: patient_portal');
T::ok($svc->revokeConsent($apB1, $pB, $uid) === true && $svc->isConsentActive($apB1, $pB) === false, 'profissional registra a revogação a pedido do paciente e a captação para');
T::ok($db->query("SELECT revoked_by FROM consultation_ai_consent_requests WHERE id = " . (int)$r5['consent']['id'])->fetchColumn() === 'professional', 'revogação registra quem revogou (professional)');

// Atendimento cancelado não recebe pedido
$db->exec("UPDATE appointments SET status = 'cancelled' WHERE id = {$apA2}");
try { $svc->createRequest($apA2, $pA, $uid); T::ok(false, 'pedido em atendimento cancelado deveria falhar'); } catch (DomainException $e) { T::ok(true, 'atendimento cancelado não recebe pedido de aceite'); }

T::done();
