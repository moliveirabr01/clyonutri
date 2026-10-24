<?php
/**
 * NI2-92 — Registrar hidratação: regras de domínio com relógio controlado.
 * SQLite isolado e descartável (arquivo temporário), dados fictícios TESTE.
 *
 *   php tests/ni2_92_hydration_rules_test.php
 *
 * Cobre: validação de volume (negativo, zero, enorme, texto, decimal, ausente), teto diário configurável,
 * horário (futuro, retroativo até 7 dias, fuso America/Sao_Paulo e virada 21h–23h59 BRT), idempotência
 * (mesma chave, chave reaproveitada, índice único), edição/exclusão (janela de 24 h, exclusão lógica,
 * auditoria, IDOR na mesma clínica e entre clínicas), histórico paginado, meta só de anamnese finalizada,
 * visão do profissional, exportação LGPD sem a chave técnica e migração reexecutável.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Engagement\HydrationException;
use ClyoNutri\Domain\Engagement\HydrationService;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;

date_default_timezone_set('America/Sao_Paulo');
putenv('HYDRATION_DAILY_MAX_ML');
putenv('HYDRATION_BACKDATE_DAYS');

$tmpBase = tempnam(sys_get_temp_dir(), 'ni2-92-rules-');
$file = $tmpBase . '.sqlite';
register_shutdown_function(static function () use ($tmpBase, $file): void { @unlink($file); @unlink($tmpBase); });
$db = new PDO('sqlite:' . $file);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys = ON');
(new Migrator($db, __DIR__ . '/../database/migrations'))->run();

$db->exec("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'n92-c1','TESTE Clínica Um','active'),(2,'n92-c2','TESTE Clínica Dois','active')");
$db->exec("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(1,'n92-u1','TESTE Nutri Um','n92-1@example.test','x','nutritionist','active'),(2,'n92-u2','TESTE Nutri Dois','n92-2@example.test','x','nutritionist','active')");
$db->exec("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'CRN-T1','CRN-3'),(2,2,'CRN-T2','CRN-3')");
$p = $db->prepare('INSERT INTO patients(id,clinic_id,uuid,name,email,status) VALUES(?,?,?,?,?,?)');
$p->execute([1, 1, 'n92-p1', 'TESTE Ana', 'ana92@example.test', 'active']);
$p->execute([2, 1, 'n92-p2', 'TESTE Bruno', 'bruno92@example.test', 'active']);
$p->execute([3, 2, 'n92-p3', 'TESTE Carla', 'carla92@example.test', 'active']);

$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] {$name}\n"; }
    else { $fail++; echo "  [FAIL] {$name}" . ($detail !== null ? ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n"; }
}
function code(callable $fn): string {
    try { $fn(); return 'OK'; }
    catch (HydrationException $e) { return $e->errorCode . '/' . $e->httpStatus; }
    catch (TenantSecurityException $e) { return 'TENANT'; }
}
$now = new DateTimeImmutable('2026-10-21 23:30:00', new DateTimeZone('America/Sao_Paulo'));
$clock = function () use (&$now) { return $now; };
$svc = new HydrationService($db, $clock);
$asPatient = function (int $id, int $clinic) { TenantContext::set($clinic, ['id' => $id, 'role' => 'patient', 'name' => 'TESTE']); };
$count = fn(string $sql) => (int)$db->query($sql)->fetchColumn();

echo "[1] Validação do volume (M2, A1)\n";
$asPatient(1, 1);
foreach ([-500, 0, 5001, 99999999999, '99999999999', 'abc', '1e3', 250.7, '250.7', true, null, '', [], '-5'] as $bad) {
    check('recusa ' . json_encode($bad), code(fn() => HydrationService::parseAmount($bad)) === 'VALIDATION_ERROR/422');
}
foreach ([[1, 1], [5000, 5000], ['250', 250], [250.0, 250], ['250.0', 250], [' 300 ', 300]] as [$in, $out]) {
    check('aceita ' . json_encode($in) . ' → ' . $out, HydrationService::parseAmount($in) === $out);
}
check('nenhuma linha gravada pelas recusas', $count('SELECT COUNT(*) FROM hydration_logs') === 0);

echo "[2] Fuso e virada do dia (21h–23h59 BRT contam para o próprio dia)\n";
$r = $svc->create(1, 500, ['idempotency_key' => 'teste-92-boundary-1']);
check('23h30 BRT grava logged_at local 2026-10-21 23:30:00', $db->query('SELECT logged_at FROM hydration_logs WHERE id=' . $r['id'])->fetchColumn() === '2026-10-21 23:30:00');
check('dia do registro = 2026-10-21 e total de hoje = 500', $r['day'] === '2026-10-21' && $r['total_water_today'] === 500, $r);
check('logged_at devolvido com offset -03:00', $r['logged_at'] === '2026-10-21T23:30:00-03:00', $r['logged_at']);
$r2 = $svc->create(1, 200, ['logged_at' => '2026-10-22T02:15:00Z', 'idempotency_key' => 'teste-92-boundary-2']);
check('02:15Z (23:15 BRT) cai em 2026-10-21', $r2['day'] === '2026-10-21' && $r2['day_total_ml'] === 700, $r2);
$now = new DateTimeImmutable('2026-10-21 23:59:59', new DateTimeZone('America/Sao_Paulo'));
check('23h59 BRT: hoje ainda soma 700', $svc->todayTotal(1) === 700);
$now = new DateTimeImmutable('2026-10-22 00:00:01', new DateTimeZone('America/Sao_Paulo'));
check('00h00 BRT do dia seguinte: hoje zera', $svc->todayTotal(1) === 0);
$h = $svc->history(1);
check('histórico mostra 2026-10-21 com 700 ml', ($h['days'][0]['date'] ?? null) === '2026-10-21' && $h['days'][0]['total_ml'] === 700, $h['days'][0] ?? null);
$db->exec("DELETE FROM hydration_logs");

echo "[3] Horário informado (M3)\n";
$now = new DateTimeImmutable('2026-10-21 15:00:00', new DateTimeZone('America/Sao_Paulo'));
check('futuro > 5 min recusado', code(fn() => $svc->create(1, 250, ['logged_at' => '2026-10-21T15:06:00-03:00'])) === 'HYDRATION_DATE_IN_FUTURE/422');
check('futuro dentro de 5 min aceito', code(fn() => $svc->create(1, 250, ['logged_at' => '2026-10-21T15:04:00-03:00'])) === 'OK');
check('7 dias atrás (00h00 de 14/10) aceito', code(fn() => $svc->create(1, 250, ['logged_at' => '2026-10-14T00:00:00-03:00'])) === 'OK');
check('8 dias atrás recusado', code(fn() => $svc->create(1, 250, ['logged_at' => '2026-10-13T23:59:00-03:00'])) === 'HYDRATION_DATE_TOO_OLD/422');
check('sem fuso = America/Sao_Paulo', $svc->create(1, 100, ['logged_at' => '2026-10-21 08:00'])['logged_at'] === '2026-10-21T08:00:00-03:00');
foreach (['ontem', '2026-02-30T10:00:00-03:00', '2026-10-21T25:00', '21/10/2026 10:00', 1730000000] as $badDate) {
    check('data inválida recusada: ' . json_encode($badDate), code(fn() => $svc->create(1, 100, ['logged_at' => $badDate])) === 'VALIDATION_ERROR/422');
}
putenv('HYDRATION_BACKDATE_DAYS=1');
check('HYDRATION_BACKDATE_DAYS=1 recusa 3 dias atrás', code(fn() => $svc->create(1, 100, ['logged_at' => '2026-10-18T10:00:00-03:00'])) === 'HYDRATION_DATE_TOO_OLD/422');
putenv('HYDRATION_BACKDATE_DAYS');
$db->exec("DELETE FROM hydration_logs");

echo "[4] Teto diário (M1)\n";
$svc->create(1, 5000); $svc->create(1, 5000);
check('10 L no dia é aceito; +1 ml é recusado', code(fn() => $svc->create(1, 1)) === 'HYDRATION_DAILY_LIMIT/422');
check('teto vale por dia: ontem ainda aceita', code(fn() => $svc->create(1, 300, ['logged_at' => '2026-10-20T12:00:00-03:00'])) === 'OK');
putenv('HYDRATION_DAILY_MAX_ML=3000');
check('HYDRATION_DAILY_MAX_ML=3000 recusa 2,5 L + 1 L no dia 19', code(function () use ($svc) { $svc->create(1, 2500, ['logged_at' => '2026-10-19T12:00:00-03:00']); $svc->create(1, 1000, ['logged_at' => '2026-10-19T13:00:00-03:00']); }) === 'HYDRATION_DAILY_LIMIT/422');
putenv('HYDRATION_DAILY_MAX_ML');
$db->exec("DELETE FROM hydration_logs");

echo "[5] Idempotência (A6)\n";
$a = $svc->create(1, 250, ['idempotency_key' => 'toque-abc-12345']);
$b = $svc->create(1, 250, ['idempotency_key' => 'toque-abc-12345']);
check('mesma chave 2× = 1 linha, segunda é replay', $count("SELECT COUNT(*) FROM hydration_logs WHERE idempotency_key='toque-abc-12345'") === 1 && $a['id'] === $b['id'] && $b['idempotent_replay'] === true && $b['total_water_today'] === 250);
check('mesma chave com volume diferente → 409', code(fn() => $svc->create(1, 500, ['idempotency_key' => 'toque-abc-12345'])) === 'IDEMPOTENCY_KEY_REUSED/409');
check('chave inválida → 422', code(fn() => $svc->create(1, 250, ['idempotency_key' => 'x'])) === 'IDEMPOTENCY_KEY_INVALID/422');
check('mesma chave em outra paciente é outra operação', code(function () use ($svc, $asPatient) { $asPatient(2, 1); $svc->create(2, 250, ['idempotency_key' => 'toque-abc-12345']); $asPatient(1, 1); }) === 'OK');
$dupBlocked = false;
try { $db->exec("INSERT INTO hydration_logs (clinic_id,patient_id,amount_ml,logged_at,idempotency_key) VALUES (1,1,250,'2026-10-21 10:00:00','toque-abc-12345')"); } catch (PDOException $e) { $dupBlocked = true; }
check('índice único bloqueia duplicata direta no banco', $dupBlocked);
check('sem chave continua permitido (registros antigos)', code(fn() => $svc->create(1, 100)) === 'OK' && code(fn() => $svc->create(1, 100)) === 'OK');
$db->exec("DELETE FROM hydration_logs");

echo "[6] Edição e exclusão (A2)\n";
$e1 = $svc->create(1, 250, ['idempotency_key' => 'edit-key-000001']);
$u = $svc->update(1, $e1['id'], ['amount_ml' => 300]);
check('corrige volume dentro de 24 h', $u['amount_ml'] === 300 && $u['day_total_ml'] === 300);
check('replay da chave após edição não duplica nem recusa', code(fn() => $svc->create(1, 250, ['idempotency_key' => 'edit-key-000001'])) === 'OK' && $count('SELECT COUNT(*) FROM hydration_logs') === 1);
$audit = $db->query("SELECT details FROM audit_logs WHERE action='HYDRATION_UPDATED' AND resource_id='" . $e1['id'] . "'")->fetchColumn();
check('auditoria da edição com antes/depois', $audit && str_contains($audit, '"before":{"amount_ml":250') && str_contains($audit, '"after":{"amount_ml":300'), $audit);
check('edição sem campos → 422', code(fn() => $svc->update(1, $e1['id'], [])) === 'VALIDATION_ERROR/422');
check('edição com volume inválido → 422', code(fn() => $svc->update(1, $e1['id'], ['amount_ml' => -1])) === 'VALIDATION_ERROR/422');
$asPatient(2, 1);
check('IDOR mesma clínica: Bruno não edita registro da Ana (404)', code(fn() => $svc->update(2, $e1['id'], ['amount_ml' => 1])) === 'HYDRATION_NOT_FOUND/404');
check('IDOR mesma clínica: Bruno não exclui registro da Ana (404)', code(fn() => $svc->delete(2, $e1['id'])) === 'HYDRATION_NOT_FOUND/404');
check('IDOR: token do Bruno não age como Ana (TenantGuard)', code(fn() => $svc->update(1, $e1['id'], ['amount_ml' => 1])) === 'TENANT');
check('IDOR: Bruno não lista o histórico da Ana', code(fn() => $svc->history(1)) === 'TENANT');
$asPatient(3, 2);
check('IDOR entre clínicas: Carla não edita registro da Ana (404)', code(fn() => $svc->update(3, $e1['id'], ['amount_ml' => 1])) === 'HYDRATION_NOT_FOUND/404');
check('IDOR entre clínicas: Carla não lista histórico da Ana', code(fn() => $svc->history(1)) === 'TENANT');
$asPatient(1, 1);
check('registro da Ana intacto após tentativas', (int)$db->query('SELECT amount_ml FROM hydration_logs WHERE id=' . $e1['id'])->fetchColumn() === 300);
$d = $svc->delete(1, $e1['id']);
check('exclusão lógica: linha mantida com deleted_at e fora dos totais', $d['deleted'] && $d['day_total_ml'] === 0 && $count('SELECT COUNT(*) FROM hydration_logs WHERE deleted_at IS NOT NULL') === 1);
check('excluir de novo é idempotente', $svc->delete(1, $e1['id'])['already_deleted'] === true);
check('editar registro excluído → 404', code(fn() => $svc->update(1, $e1['id'], ['amount_ml' => 100])) === 'HYDRATION_NOT_FOUND/404');
check('auditoria da exclusão', $count("SELECT COUNT(*) FROM audit_logs WHERE action='HYDRATION_DELETED'") === 1);
check('auditoria da criação', $count("SELECT COUNT(*) FROM audit_logs WHERE action='HYDRATION_LOGGED'") >= 1);
$e2 = $svc->create(1, 400);
$now = $now->modify('+24 hours +1 minute');
check('após 24 h: edição → 409', code(fn() => $svc->update(1, $e2['id'], ['amount_ml' => 100])) === 'HYDRATION_EDIT_WINDOW_CLOSED/409');
check('após 24 h: exclusão → 409', code(fn() => $svc->delete(1, $e2['id'])) === 'HYDRATION_EDIT_WINDOW_CLOSED/409');
$db->exec("INSERT INTO hydration_logs (clinic_id,patient_id,amount_ml,logged_at,created_at,source) VALUES (1,1,200,'" . $now->format('Y-m-d H:i:s') . "','" . $now->format('Y-m-d H:i:s') . "','import')");
$imp = (int)$db->lastInsertId();
check('registro importado (não feito no app) não é editável', code(fn() => $svc->delete(1, $imp)) === 'HYDRATION_NOT_EDITABLE/409');
$db->exec("DELETE FROM hydration_logs");

echo "[7] Histórico paginado por dia (A2)\n";
$now = new DateTimeImmutable('2026-10-21 20:00:00', new DateTimeZone('America/Sao_Paulo'));
foreach (['2026-10-21T08:00', '2026-10-21T19:00', '2026-10-20T10:00', '2026-10-18T09:00', '2026-10-18T07:00'] as $i => $t) {
    $svc->create(1, 100 * ($i + 1), ['logged_at' => $t]);
}
$x = $svc->create(1, 999, ['logged_at' => '2026-10-19T10:00']);
$svc->delete(1, $x['id']);
$pg1 = $svc->history(1, null, 2);
check('página 1: 2 dias, mais recente primeiro', array_column($pg1['days'], 'date') === ['2026-10-21', '2026-10-20'], array_column($pg1['days'], 'date'));
check('página 1: itens do dia em ordem decrescente de horário', array_column($pg1['days'][0]['entries'], 'amount_ml') === [200, 100]);
check('página 1: cursor next_before = 2026-10-20', $pg1['next_before'] === '2026-10-20');
$pg2 = $svc->history(1, $pg1['next_before'], 2);
check('página 2: só 18/10 (19/10 excluído não aparece) e sem próxima', array_column($pg2['days'], 'date') === ['2026-10-18'] && $pg2['next_before'] === null && $pg2['days'][0]['total_ml'] === 900, $pg2['days']);
check('parâmetros inválidos → 422', code(fn() => $svc->history(1, '21/10/2026')) === 'VALIDATION_ERROR/422' && code(fn() => $svc->history(1, null, 0)) === 'VALIDATION_ERROR/422' && code(fn() => $svc->history(1, null, 99)) === 'VALIDATION_ERROR/422');
check('metadados: fuso, teto, janela', $pg1['timezone'] === 'America/Sao_Paulo' && $pg1['daily_max_ml'] === 10000 && $pg1['edit_window_hours'] === 24 && $pg1['backdate_days'] === 7);

echo "[8] Meta só de anamnese finalizada (A4)\n";
$portal = new PatientPortalService($db);
$db->exec("INSERT INTO anamneses (clinic_id,patient_id,professional_id,version,water_intake_ml,is_draft) VALUES (1,1,1,1,3000,1)");
check('só rascunho → sem meta (null)', $portal->getPrescribedWaterGoal(1) === null);
$db->exec("INSERT INTO anamneses (clinic_id,patient_id,professional_id,version,water_intake_ml,is_draft) VALUES (1,1,1,2,2000,0)");
$db->exec("INSERT INTO anamneses (clinic_id,patient_id,professional_id,version,water_intake_ml,is_draft) VALUES (1,1,1,3,3500,1)");
check('finalizada 2000 + rascunho mais novo 3500 → 2000', $portal->getPrescribedWaterGoal(1) === 2000);
check('histórico usa a meta finalizada', $svc->history(1)['goal_ml'] === 2000);

echo "[9] Visão do profissional (A3)\n";
TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist', 'name' => 'TESTE Nutri']);
$sum = $svc->professionalSummary(1, 7);
check('7 dias, inclusive sem registro, mais recente primeiro', count($sum['days']) === 7 && $sum['days'][0]['date'] === '2026-10-21' && $sum['days'][1]['total_ml'] === 300 && $sum['days'][2]['total_ml'] === 0, array_slice($sum['days'], 0, 3));
check('dias com registro e média', $sum['days_with_records'] === 3 && $sum['average_ml_on_recorded_days'] === 500, $sum);
check('excluído não entra no resumo', $sum['days'][2]['total_ml'] === 0);
TenantContext::set(2, ['id' => 2, 'role' => 'nutritionist', 'name' => 'TESTE Nutri Dois']);
check('profissional de outra clínica não vê (TenantGuard)', code(fn() => $svc->professionalSummary(1)) === 'TENANT');

echo "[10] LGPD e migração\n";
check('exportação LGPD omite idempotency_key', \ClyoNutri\Domain\Privacy\LgpdService::EXPORTED_TABLES['hydration_logs'] === ['idempotency_key']);
require_once __DIR__ . '/../database/migrations/2026_10_24_000051_harden_hydration_logs.php';
check('migração 50 reexecutável sem erro', code(fn() => (new HardenHydrationLogs())->up($db)) === 'OK');

echo "\nNI2-92 regras: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
