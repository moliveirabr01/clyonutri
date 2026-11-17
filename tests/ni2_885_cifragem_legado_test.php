<?php
/**
 * NI2-885 (v54.76): bin/encrypt_clinical_records.php cifra os dados clínicos antigos de verdade, num SQLite de TESTE:
 * dry-run só conta; verificação acusa pendências; execução em lotes interrompida e retomada; segunda execução não altera nada;
 * falha num registro não para o lote; trava contra execução dupla; sem chave não grava; resumo sem conteúdo clínico;
 * leitura pelo sistema (prontuário com cadeia de hash íntegra, cadastro, exportação LGPD, rascunho) continua igual.
 */
declare(strict_types=1);
putenv('V54_DB=' . sys_get_temp_dir() . '/ni2-885-' . getmypid() . '.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Security\ClinicalEncryptionService as Enc;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Privacy\LgpdService;

$root = dirname(__DIR__);
$db = $pdo;
$dbFile = getenv('DB_DATABASE');
$logDir = sys_get_temp_dir() . '/ni2-885-logs-' . getmypid();
@mkdir($logDir, 0700, true);
$envFile = $logDir . '/teste.env';
file_put_contents($envFile, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbFile}\nCLINICAL_ENCRYPTION_KEY=test-clinical-key-v5445-not-for-prod\n");

// ------------------------------------------------------------------ dados "antigos" (texto puro, como antes da v54.68)
$c = Fx::clinic($db, 'Clínica TESTE 885');
$u = Fx::user($db, $c, 'Nutri TESTE 885', 'owner');
Fx::act($u);
$legacy = [];
$patients = [];
for ($i = 1; $i <= 5; $i++) {
    $p = Fx::patient($db, $c, "Paciente TESTE 885-$i");
    $patients[] = $p;
    $db->prepare('UPDATE patients SET notes = ?, allergies = ? WHERE id = ?')->execute(["OBS-LEGADO-$i gastrite TESTE", "ALERGIA-LEGADO-$i amendoim", $p]);
    $legacy["patients:$p:notes"] = "OBS-LEGADO-$i gastrite TESTE";
    $legacy["patients:$p:allergies"] = "ALERGIA-LEGADO-$i amendoim";
    $db->prepare('INSERT INTO anamneses (clinic_id, patient_id, professional_id, chief_complaint, routine, structured_data_json) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$c, $p, $u['professional_id'], "QUEIXA-LEGADO-$i refluxo", "ROTINA-LEGADO-$i", json_encode(['queixa' => "QUEIXA-LEGADO-$i refluxo"])]);
    $aid = (int)$db->lastInsertId();
    $legacy["anamneses:$aid:chief_complaint"] = "QUEIXA-LEGADO-$i refluxo";
    $legacy["anamneses:$aid:routine"] = "ROTINA-LEGADO-$i";
}
$p1 = $patients[0];
// prontuário: grava pelo serviço (hash sobre o texto) e devolve o content_json ao texto puro, como nos registros antigos
$svc = new ClinicalRecordService($db);
$rec1 = $svc->appendRecord($p1, $u['professional_id'], 'Consulta TESTE 885', ['conduta' => 'CONDUTA-LEGADO dieta 1800 kcal']);
$rec2 = $svc->reviseRecord($rec1['id'], $p1, $u['professional_id'], 'Retificação TESTE 885', ['conduta' => 'CONDUTA-LEGADO-V2 dieta 1900 kcal']);
foreach ([$rec1['id'], $rec2['id']] as $rid) {
    $plain = Enc::decrypt((string)$db->query("SELECT content_json FROM clinical_records WHERE id = $rid")->fetchColumn());
    $db->prepare('UPDATE clinical_records SET content_json = ? WHERE id = ?')->execute([$plain, $rid]);
    $legacy["clinical_records:$rid:content_json"] = $plain;
}
T::ok($svc->verifyIntegrity($p1)['is_valid'] ?? $svc->verifyIntegrity($p1)['valid'] ?? false, 'pré-condição: cadeia de hash íntegra com o prontuário em texto puro');
$appt = Fx::appointment($db, $c, $p1, $u['professional_id']);
$db->prepare('INSERT INTO consultation_note_drafts (clinic_id, appointment_id, user_id, evolution_summary, version, updated_at_utc) VALUES (?, ?, ?, ?, 1, ?)')
    ->execute([$c, $appt, $u['user_id'], 'EVOLUCAO-LEGADO perdeu 2 kg', gmdate('Y-m-d H:i:s')]);
$did = (int)$db->lastInsertId();
$legacy["consultation_note_drafts:$did:evolution_summary"] = 'EVOLUCAO-LEGADO perdeu 2 kg';
// já cifrado (criado pela v54.68+): tem de ser pulado, sem dupla cifragem
$p6 = Fx::patient($db, $c, 'Paciente TESTE 885-novo');
$encNew = Enc::encrypt('OBS-NOVA já cifrada');
$db->prepare('UPDATE patients SET notes = ? WHERE id = ?')->execute([$encNew, $p6]);
$expectedPlain = count($legacy);

$snapshot = function () use ($db): string {
    $h = '';
    foreach (['patients' => 'id, notes, allergies', 'anamneses' => 'id, chief_complaint, routine, structured_data_json', 'clinical_records' => 'id, content_json, record_hash', 'consultation_note_drafts' => 'id, evolution_summary'] as $t => $cols) {
        $h .= json_encode($db->query("SELECT $cols FROM $t ORDER BY id")->fetchAll(PDO::FETCH_NUM));
    }
    return hash('sha256', $h);
};
$run = function (array $args) use ($root, $envFile, $logDir): array {
    $cmd = array_merge([PHP_BINARY, $root . '/bin/encrypt_clinical_records.php', '--env-file=' . $envFile, '--log-dir=' . $logDir], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $rc = proc_close($p);
    return [$rc, $out];
};
$lastSummary = function (string $prefix) use ($logDir): array {
    $files = glob($logDir . '/' . $prefix . '*.json') ?: [];
    sort($files);
    return json_decode((string)file_get_contents((string)end($files)), true) ?: [];
};
$sumField = fn(array $s, string $k) => array_sum(array_map(fn($t) => (int)($t[$k] ?? 0), $s['tables'] ?? []));
$structuredPlain = 0; // structured_data_json conta como campo em claro também
$expectedPlain += 5;

// ------------------------------------------------------------------ 1. dry-run
$before = $snapshot();
[$rc, $out] = $run(['--dry-run']);
$s = $lastSummary('encrypt_clinical_records-dryrun-');
T::ok($rc === 0 && str_contains($out, 'nada foi gravado'), "dry-run sai 0 e avisa que não gravou (rc=$rc)");
T::ok($sumField($s, 'campos_cifrados') === $expectedPlain, "dry-run conta os {$expectedPlain} campos em texto puro (contou " . $sumField($s, 'campos_cifrados') . ')');
T::ok($snapshot() === $before, 'dry-run não alterou nenhum byte no banco');
T::ok(!is_file($logDir . '/encrypt_clinical_records.state.json'), 'dry-run não cria ponto de retomada');

// ------------------------------------------------------------------ 2. verificação antes
[$rc, $out] = $run(['--verify']);
T::ok($rc === 4 && str_contains($out, 'PENDENTE'), "verificação antes acusa pendências (rc=$rc)");

// ------------------------------------------------------------------ 3. sem chave: não grava
file_put_contents($logDir . '/semchave.env', "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbFile}\nCLINICAL_ENCRYPTION_KEY=\n");
$p = proc_open(['env', '-u', 'CLINICAL_ENCRYPTION_KEY', PHP_BINARY, $root . '/bin/encrypt_clinical_records.php', '--env-file=' . $logDir . '/semchave.env', '--log-dir=' . $logDir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, $root);
$o = stream_get_contents($pp[1]) . stream_get_contents($pp[2]); $rcK = proc_close($p);
T::ok($rcK === 1 && str_contains($o, 'CLINICAL_ENCRYPTION_KEY vazia') && $snapshot() === $before, "sem CLINICAL_ENCRYPTION_KEY o script para (rc=$rcK) e não grava nada");

// ------------------------------------------------------------------ 4. trava: outra execução em andamento
$lk = fopen($logDir . '/encrypt_clinical_records.lock', 'c'); flock($lk, LOCK_EX);
[$rc, $out] = $run([]);
flock($lk, LOCK_UN); fclose($lk);
T::ok($rc === 3 && $snapshot() === $before, "com outra execução em andamento sai 3 sem gravar (rc=$rc)");

// ------------------------------------------------------------------ 5. falha num registro não para o lote
$badPatient = $patients[2];
$db->exec("CREATE TRIGGER t885_falha BEFORE UPDATE OF notes ON patients WHEN OLD.id = {$badPatient} BEGIN SELECT RAISE(ABORT, 'falha TESTE'); END");

// ------------------------------------------------------------------ 6. execução interrompida (1 lote de 2 linhas) e retomada
[$rc, $out] = $run(['--batch-size=2', '--max-batches=1']);
$state = json_decode((string)@file_get_contents($logDir . '/encrypt_clinical_records.state.json'), true) ?: [];
T::ok($rc === 6 && str_contains($out, 'PARCIAL'), "execução limitada a 1 lote para no meio e avisa PARCIAL (rc=$rc)");
T::ok(empty($state['completed']) && (int)($state['tables']['anamneses'] ?? 0) === 2, 'ponto de retomada salvo depois do lote (anamneses até o id 2)');
$encAfterPartial = (int)$db->query("SELECT COUNT(*) FROM anamneses WHERE chief_complaint LIKE 'enc:v1:%'")->fetchColumn();
T::ok($encAfterPartial === 2, "só o primeiro lote foi cifrado ($encAfterPartial de 5 anamneses)");
[$rc, $out] = $run(['--batch-size=2']);
$s = $lastSummary('encrypt_clinical_records-2');
T::ok(str_contains($out, 'Retomando do ponto salvo'), 'segunda execução retoma do ponto salvo');
T::ok($rc === 5 && count($s['errors'] ?? []) === 1 && ($s['errors'][0]['table'] ?? '') === 'patients' && (int)($s['errors'][0]['id'] ?? 0) === $badPatient && ($s['errors'][0]['column'] ?? '') === 'notes',
    "o registro que falhou vai para o resumo (patients #{$badPatient}.notes) e a execução sai 5 (rc=$rc)");
$encAnam = (int)$db->query("SELECT COUNT(*) FROM anamneses WHERE chief_complaint LIKE 'enc:v1:%' AND routine LIKE 'enc:v1:%' AND structured_data_json LIKE 'enc:v1:%'")->fetchColumn();
$encPat = (int)$db->query("SELECT COUNT(*) FROM patients WHERE id IN (" . implode(',', $patients) . ") AND id <> {$badPatient} AND notes LIKE 'enc:v1:%'")->fetchColumn();
T::ok($encAnam === 5 && $encPat === 4, "os demais registros do mesmo lote foram cifrados (anamneses $encAnam/5, pacientes antigos sem falha $encPat/4)");
T::ok(Enc::isEncrypted((string)$db->query("SELECT allergies FROM patients WHERE id = {$badPatient}")->fetchColumn()), 'a outra coluna do registro com falha foi cifrada normalmente');
T::ok((string)$db->query("SELECT notes FROM patients WHERE id = $p6")->fetchColumn() === $encNew, 'valor já cifrado (v54.68+) ficou idêntico: sem dupla cifragem');

// ------------------------------------------------------------------ 7. corrige a causa e roda de novo: completa
$db->exec('DROP TRIGGER t885_falha');
[$rc, $out] = $run([]);
T::ok($rc === 0 && str_contains($out, 'Concluído'), "depois de corrigir a causa, nova execução conclui (rc=$rc)");
[$rc, $out] = $run(['--verify']);
$v = $lastSummary('encrypt_clinical_records-verify-');
T::ok($rc === 0 && (int)($v['pendencias'] ?? -1) === 0, "verificação: 0 em claro e 0 que não decifram nas colunas cobertas (rc=$rc)");

// ------------------------------------------------------------------ 8. idempotência: rodar de novo não altera nada
$afterFirst = $snapshot();
[$rc, $out] = $run([]);
$s = $lastSummary('encrypt_clinical_records-2');
T::ok($rc === 0 && $sumField($s, 'campos_cifrados') === 0 && $snapshot() === $afterFirst, 'segunda execução completa: 0 campos cifrados e nenhum byte alterado');

// ------------------------------------------------------------------ 9. conteúdo preservado e leitura pelo sistema
$okAll = true;
foreach ($legacy as $k => $plain) {
    [$t, $id, $col] = explode(':', $k);
    $stored = (string)$db->query("SELECT $col FROM $t WHERE id = $id")->fetchColumn();
    if (!Enc::isEncrypted($stored) || Enc::decrypt($stored) !== $plain) { $okAll = false; echo "  divergente: $k\n"; }
}
T::ok($okAll, 'todo valor antigo ficou cifrado e decifra exatamente no texto original (' . count($legacy) . ' campos)');
$integ = $svc->verifyIntegrity($p1);
T::ok(($integ['is_valid'] ?? $integ['valid'] ?? false) === true, 'prontuário: cadeia de hash continua íntegra depois da cifragem');
$timeline = $svc->getPatientTimeline($p1);
T::ok(str_contains(json_encode($timeline, JSON_UNESCAPED_UNICODE), 'CONDUTA-LEGADO-V2 dieta 1900 kcal'), 'prontuário antigo abre pela linha do tempo com o texto original');
$safe = json_encode($svc->exportRecordTimelineSafe($p1, $u['professional_id']), JSON_UNESCAPED_UNICODE);
T::ok(str_contains($safe, 'CONDUTA-LEGADO-V2') && !str_contains($safe, 'enc:v1:'), 'exportação do prontuário (linha do tempo) sai com o texto original');
$export = (new LgpdService($db))->exportPatientData($p1);
$ej = json_encode($export, JSON_UNESCAPED_UNICODE);
T::ok(str_contains($ej, 'OBS-LEGADO-1 gastrite TESTE') && str_contains($ej, 'QUEIXA-LEGADO-1 refluxo') && str_contains($ej, 'CONDUTA-LEGADO-V2') && !str_contains($ej, 'enc:v1:'), 'exportação LGPD entrega o texto original (cadastro, anamnese e prontuário), sem envelope enc:v1');
$draft = (new \ClyoNutri\Domain\Appointment\ConsultationDraftService($db))->get($appt, $u['user_id'], $c);
T::ok(str_contains(json_encode($draft, JSON_UNESCAPED_UNICODE), 'EVOLUCAO-LEGADO perdeu 2 kg'), 'rascunho de evolução antigo continua legível');

// ------------------------------------------------------------------ 10. resumos sem conteúdo clínico
$logs = '';
foreach (glob($logDir . '/*.json') ?: [] as $f) { $logs .= file_get_contents($f); }
$leak = array_filter(['LEGADO', 'gastrite', 'amendoim', 'refluxo', 'kcal'], fn($w) => str_contains($logs, $w));
T::ok($logs !== '' && $leak === [], 'resumos em storage/logs não contêm nenhum conteúdo clínico');

array_map('unlink', glob($logDir . '/*') ?: []); @rmdir($logDir);
T::done();
