<?php
/**
 * v54.46 — Code Review r2: CR2-NI2-001 (structured_data_json cifrado), CR-NI2-006 (PATIENT_RECORD_VIEWED),
 * CR-NI2-010 (logger estruturado), CR-NI2-011 (copy() só em CLI fora de produção), CR-NI2-004 (findScoped),
 * CR-NI2-009 (etapa MySQL BLOQUEADA sem servidor), CR-NI2-007 (wording), CR2-NI2-002/003 (docs/evidência).
 * Dados só de TESTE. Sem Jira / sem push.
 */
declare(strict_types=1);

putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5446.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Audit\PatientRecordAccessAudit;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\UploadMover;
use ClyoNutri\Core\Observability\StructuredLogger;
use ClyoNutri\Core\Security\ClinicalEncryptionService;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;

$root = dirname(__DIR__);
putenv('APP_ENV=testing');
putenv('CLINICAL_ENCRYPTION_KEY=test-clinical-key-v5446-not-for-prod');
$_ENV['CLINICAL_ENCRYPTION_KEY'] = $_SERVER['CLINICAL_ENCRYPTION_KEY'] = 'test-clinical-key-v5446-not-for-prod';

$db = Connection::getInstance();
$cA = Fx::clinic($db, 'Clínica TESTE CR2 A');
$cB = Fx::clinic($db, 'Clínica TESTE CR2 B');
$u = Fx::user($db, $cA, 'Nutri TESTE CR2', 'owner');
$pA = Fx::patient($db, $cA, 'Paciente TESTE CR2 A');
$pB = Fx::patient($db, $cB, 'Paciente TESTE CR2 B');
Fx::act($u);
$profId = (int)$u['professional_id'];

fwrite(STDOUT, "=== CR2-NI2-001 structured_data_json cifrado ===\n");
T::ok(in_array('structured_data_json', ClinicalEncryptionService::ANAMNESIS_ENCRYPTED_FIELDS, true), 'structured_data_json na lista de campos cifrados');
$svc = new AnamnesisService($db);
$svc->saveAnamnesis($pA, $profId, ['allergy_assessment' => 'recorded', 'allergies' => 'TESTE castanha CR2', 'objectives' => 'TESTE objetivo CR2'], false);
$raw = $db->query("SELECT id, structured_data_json FROM anamneses WHERE patient_id = {$pA} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rawJson = (string)($raw['structured_data_json'] ?? '');
T::ok($rawJson !== '' && ClinicalEncryptionService::isEncrypted($rawJson), 'DB grava structured_data_json como enc:v1');
T::ok(!str_contains($rawJson, 'castanha'), 'sem texto clínico em claro no snapshot');
$snap = json_decode((string)ClinicalEncryptionService::decrypt($rawJson), true);
T::ok(is_array($snap) && ($snap['allergies'] ?? '') === 'TESTE castanha CR2', 'snapshot decifra para o JSON original');
$latest = $svc->getLatestAnamnesis($pA);
T::ok(!ClinicalEncryptionService::isEncrypted((string)($latest['structured_data_json'] ?? '')), 'leitura pelo serviço devolve snapshot claro');
// legado em claro
$legacyJson = json_encode(['allergies' => 'TESTE legado json claro'], JSON_UNESCAPED_UNICODE);
$db->prepare('UPDATE anamneses SET structured_data_json = ? WHERE id = ?')->execute([$legacyJson, (int)$raw['id']]);
$row = ClinicalEncryptionService::decryptAnamnesisRow($db->query('SELECT * FROM anamneses WHERE id = ' . (int)$raw['id'])->fetch(PDO::FETCH_ASSOC));
T::ok(($row['structured_data_json'] ?? '') === $legacyJson, 'JSON legado em claro segue legível (passthrough)');
T::ok(in_array('structured_data_json', ClinicalEncryptionService::anamnesisFieldsPresent($db), true), 'probe detecta a coluna presente');
$stats = ClinicalEncryptionService::anamnesisCoverageStats($db);
T::ok(is_array($stats), 'coverage stats calcula com structured_data_json');
// rascunho → snapshot draft_origin continua cifrado
$svc->saveAnamnesis($pA, $profId, ['allergy_assessment' => 'recorded', 'allergies' => 'TESTE rascunho CR2'], true);
$d = $db->query("SELECT structured_data_json FROM anamneses WHERE patient_id = {$pA} ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok(ClinicalEncryptionService::isEncrypted((string)$d), 'rascunho também grava snapshot cifrado');

fwrite(STDOUT, "=== CR-NI2-006 PATIENT_RECORD_VIEWED ===\n");
$_SESSION = $_SESSION ?? [];
unset($_SESSION['_record_view_audit']);
$count = static fn() => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'PATIENT_RECORD_VIEWED' AND resource_id = '{$pA}'")->fetchColumn();
$before = $count();
T::ok(PatientRecordAccessAudit::record($pA, 'profile360', $db) === true, 'primeira visualização registra');
T::ok(PatientRecordAccessAudit::record($pA, 'profile360', $db) === false, 'repetição na mesma sessão é deduplicada');
T::ok(PatientRecordAccessAudit::record($pA, 'medical_record', $db) === true, 'outra superfície registra');
T::ok($count() === $before + 2, 'exatamente 2 linhas novas');
$det = (string)$db->query("SELECT details FROM audit_logs WHERE action = 'PATIENT_RECORD_VIEWED' ORDER BY id DESC LIMIT 1")->fetchColumn();
$dj = json_decode($det, true);
T::ok(is_array($dj) && array_keys($dj) === ['surface'], 'details só com surface (sem payload clínico)');
T::ok(!str_contains($det, 'TESTE') && !str_contains($det, 'Paciente'), 'sem nome/texto clínico no details');
$dc = file_get_contents($root . '/src/Application/Controllers/DashboardController.php');
foreach (['profile360', 'medical_record', 'medical_record_print', 'medical_record_export'] as $s) {
    T::ok(str_contains($dc, "PatientRecordAccessAudit::record(\$id, '{$s}')") || str_contains($dc, "PatientRecordAccessAudit::record(\$patientId, '{$s}')"), "DashboardController audita {$s}");
}

fwrite(STDOUT, "=== CR-NI2-010 logger estruturado ===\n");
StructuredLogger::resetForTesting();
$lines = [];
StructuredLogger::$sink = static function (string $l) use (&$lines) { $lines[] = $l; };
$_SERVER['HTTP_X_REQUEST_ID'] = 'abc123def456';
StructuredLogger::warning('TESTE_EVENT', ['patient_id' => 7, 'allergies' => 'segredo', 'name' => 'Fulano', 'exception' => new RuntimeException('detalhe sensível')]);
StructuredLogger::$sink = null;
$j = json_decode($lines[0] ?? '', true);
T::ok(is_array($j) && ($j['event'] ?? '') === 'TESTE_EVENT', 'linha JSON com event');
T::ok(($j['request_id'] ?? '') === 'abc123def456', 'request_id propagado do X-Request-Id');
T::ok(!str_contains($lines[0] ?? '', 'segredo') && !str_contains($lines[0] ?? '', 'Fulano'), 'chaves clínicas/pessoais removidas');
T::ok(!str_contains($lines[0] ?? '', 'detalhe sensível') && str_contains($lines[0] ?? '', 'RuntimeException'), 'exceção só com a classe');
$ph = file_get_contents($root . '/src/Application/View/PatientHeader.php');
T::ok(substr_count($ph, 'StructuredLogger::warning(') >= 3 && !str_contains($ph, 'error_log('), 'PatientHeader usa logger estruturado');
T::ok(is_file($root . '/docs/delivery/OBSERVABILIDADE_HEALTH_ALERTAS.md'), 'doc de alertas /health existe');

fwrite(STDOUT, "=== CR-NI2-011 copy() restrito ===\n");
$tmp = tempnam(sys_get_temp_dir(), 'cr2'); file_put_contents($tmp, 'TESTE');
$dest = sys_get_temp_dir() . '/cr2_dest_' . getmypid();
T::ok(UploadMover::copyFallbackAllowed(), 'CLI fora de produção permite copy() (testes)');
T::ok(UploadMover::move($tmp, $dest) && is_file($dest), 'move em CLI de teste copia');
@unlink($dest);
putenv('APP_ENV=production');
T::ok(!UploadMover::copyFallbackAllowed(), 'produção nunca usa copy()');
T::ok(!UploadMover::move($tmp, $dest) && !is_file($dest), 'produção recusa arquivo não enviado por POST');
putenv('APP_ENV=testing');
@unlink($tmp);
foreach (['src/Domain/Materials/MaterialService.php', 'src/Domain/Academy/AcademyHandoutService.php', 'src/Domain/LaboratoryExam/ExamResultDocumentService.php', 'src/Domain/Helpdesk/HelpdeskAttachmentService.php', 'src/Domain/Chat/ChatAttachmentService.php'] as $f) {
    $c = file_get_contents($root . '/' . $f);
    T::ok(str_contains($c, 'UploadMover::move(') && !preg_match('/[^:]\bcopy\(/', $c), basename($f) . ' sem copy() direto');
}

fwrite(STDOUT, "=== CR-NI2-004 findScoped / cross-tenant ===\n");
T::ok(TenantGuard::findScoped($db, 'patients', $pA, $cA, 'id, clinic_id') !== null, 'mesma clínica encontra');
T::ok(TenantGuard::findScoped($db, 'patients', $pB, $cA, 'id, clinic_id') === null, 'outra clínica: nem sai do banco');
T::throws(fn() => TenantGuard::findScoped($db, 'users', 1, $cA), 'tabela fora da lista recusada');
T::throws(fn() => TenantGuard::findScoped($db, 'patients', 1, $cA, 'id; DROP TABLE x'), 'colunas inválidas recusadas');
T::ok(TenantGuard::assertPatientBelongsToClinic($pA, $cA, $db)['id'] == $pA, 'assertPatient mesma clínica OK');
T::throws(fn() => TenantGuard::assertPatientBelongsToClinic($pB, $cA, $db), 'assertPatient cross-tenant bloqueia');
$vio = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED' AND resource_id = '{$pB}'")->fetchColumn();
T::ok($vio >= 1, 'violação cross-tenant auditada');
T::throws(fn() => TenantGuard::assertMealPlanBelongsToClinic(999999, $cA, $db), 'meal plan inexistente bloqueia');
T::throws(fn() => TenantGuard::assertExamRequestBelongsToClinic(999999, $cA, $db), 'exam request inexistente bloqueia');

fwrite(STDOUT, "=== CR-NI2-009 / 007 / 012 / docs ===\n");
$gate = file_get_contents($root . '/tests/run_release_gate.sh');
T::ok(str_contains($gate, 'GATE_MYSQL') && str_contains($gate, 'BLOQUEADO'), 'gate tem etapa MySQL opcional com BLOQUEADO');
T::ok(is_file($root . '/tests/mysql/run_mysql_stage.sh'), 'runner MySQL existe');
if (!is_executable('/usr/bin/mysql') && trim((string)shell_exec('command -v mysql 2>/dev/null')) === '') {
    $out = (string)shell_exec('bash ' . escapeshellarg($root . '/tests/mysql/run_mysql_stage.sh') . ' 2>&1');
    T::ok(str_starts_with($out, 'BLOQUEADO') && !str_contains($out, 'OK '), 'sem MySQL: BLOQUEADO, nunca PASS fingido');
}
foreach (['routes/web.php', 'routes/api.php', 'routes/console.php', 'bootstrap/app.php'] as $f) {
    $c = file_get_contents($root . '/' . $f);
    T::ok(str_contains($c, 'public/index.php') && !str_contains($c, 'Laravel 13 Architecture') && !str_contains($c, 'Laravel 13 Bootstrap'), "$f não induz a Laravel/Sanctum");
}
$gi = (string)@file_get_contents($root . '/.gitignore');
T::ok($gi !== '' || !is_dir($root . '/.git'), '.gitignore presente no repo');
foreach (['docs/delivery/HOSTGATOR_RESTORE_CHECKLIST.md', 'bin/smoke_hostgator.sh', 'docs/adr/ADR-0005-acesso-clinico-clinic-wide.md', 'docs/adr/ADR-0008-convergencia-schema-soft-delete.md', 'bin/compare_migrations_schema.php'] as $f) {
    T::ok(is_file($root . '/' . $f), "$f existe");
}
$chk = (string)@file_get_contents($root . '/docs/delivery/HOSTGATOR_RESTORE_CHECKLIST.md');
foreach (['CLINICAL_ENCRYPTION_KEY', 'BACKUP_ENCRYPTION_KEY', 'BACKUP_OFFSITE_DIR', 'BACKUP_OFFSITE_RSYNC'] as $v) {
    T::ok(str_contains($chk, $v), "checklist documenta $v");
}

T::done();
