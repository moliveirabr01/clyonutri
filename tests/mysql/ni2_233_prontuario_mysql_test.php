<?php
/**
 * NI2-233: prova do prontuário no MySQL real (php tests/mysql/ni2_233_prontuario_mysql_test.php <socket> <banco_com_test_no_nome>).
 * Banco descartável já migrado (migração 84) e semeado por tests/mysql/ni2_233_e2e_seed.php. Só dados TESTE; recusa banco sem "test".
 * Usa a conexão da aplicação (Connection::getInstance, prepares emulados, SET time_zone do APP_TIMEZONE).
 * Concorrência REAL: N processos filhos (proc_open), cada um com a sua conexão, liberados no mesmo instante.
 * Cobre: jornada (evolução, retificação, cadeia de hash), adulteração detectada, isolamento entre clínicas,
 * corrida de N gravações simultâneas, corrida de N retificações da mesma versão e fuso horário (America/Sao_Paulo).
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/homologacao/ni2_233_fixture.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;

$child = ($argv[1] ?? '') === '--child';
$args = $child ? array_slice($argv, 2) : array_slice($argv, 1);
[$sock, $dbn] = $args + [null, null];
if (!$sock || !preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <socket> <banco com 'test'>\n"); exit(2); }
date_default_timezone_set('America/Sao_Paulo');
foreach (['DB_CONNECTION' => 'mysql', 'DB_SOCKET' => $sock, 'DB_HOST' => 'localhost', 'DB_DATABASE' => $dbn, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'APP_ENV' => 'local'] as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
$port = getenv('NI2_233_MYSQL_PORT') ?: '33057';
putenv('DB_HOST=127.0.0.1'); $_ENV['DB_HOST'] = '127.0.0.1'; putenv("DB_PORT=$port"); $_ENV['DB_PORT'] = $port;
$db = Connection::getInstance();

if ($child) {
    // --child <sock> <db> <op> <clinic> <user> <prof> <patient> <startAt> <idx> [<targetId>]
    [, , $op, $clinic, $user, $prof, $patient, $startAt, $idx] = $args; $target = (int)($args[9] ?? 0);
    TenantContext::set((int)$clinic, ['id' => (int)$user, 'role' => 'nutritionist']);
    $svc = new ClinicalRecordService($db);
    while (microtime(true) < (float)$startAt) { usleep(500); }
    try {
        if ($op === 'add') { $r = $svc->addClinicalObservation((int)$patient, (int)$prof, "Evolução TESTE concorrente $idx", "Texto TESTE concorrente $idx", 'evolucao'); }
        else { $r = $svc->reviseRecord($target, (int)$patient, (int)$prof, "Retificação TESTE concorrente número $idx", ['text' => "Texto TESTE retificado $idx"]); }
        echo json_encode(['ok' => true, 'id' => $r['id'] ?? null, 'version' => $r['version'] ?? null]);
    } catch (\Throwable $e) { echo json_encode(['ok' => false, 'class' => get_class($e), 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE); }
    exit(0);
}

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $l) use (&$pass, &$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; };
$one = static function (string $sql, array $a = []) use ($db) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchColumn(); };
$fx = ni2_233_fixture($db);
$cA = $fx['clinicA']; $cB = $fx['clinicB']; $a = $fx['a']; $a2 = $fx['a2']; $b = $fx['b'];
$tag = date('His') . '-' . getmypid();
$newPatient = static function (int $clinic, string $name) use ($db): int {
    $db->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (?, ?, ?, '1990-05-10', 'F', 'active')")->execute([$clinic, bin2hex(random_bytes(8)), $name]);
    return (int)$db->lastInsertId();
};
$as = static function (array $u, int $clinic) { TenantContext::set($clinic, ['id' => $u['user_id'], 'role' => 'nutritionist']); };
$svc = new ClinicalRecordService($db);
$spawn = static function (int $n, string $op, int $clinic, array $u, int $patient, int $target = 0) use ($sock, $dbn): array {
    $startAt = sprintf('%.3f', microtime(true) + 1.5); $procs = [];
    for ($i = 1; $i <= $n; $i++) {
        $cmd = [PHP_BINARY, __FILE__, '--child', $sock, $dbn, $op, (string)$clinic, (string)$u['user_id'], (string)$u['professional_id'], (string)$patient, $startAt, (string)$i, (string)$target];
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); $GLOBALS['pp'][$i] = $pipes;
    }
    $out = [];
    foreach ($procs as $k => $pr) { $p = $GLOBALS['pp'][$k + 1]; $o = stream_get_contents($p[1]); $e = stream_get_contents($p[2]); proc_close($pr); $out[] = json_decode($o, true) ?? ['ok' => false, 'msg' => 'saída inválida: ' . substr($o . $e, 0, 300)]; }
    return $out;
};

echo "== ambiente\n";
$ver = (string)$one('SELECT VERSION()');
$ok(str_starts_with($ver, '5.7'), "MySQL $ver (alvo HostGator 5.7)");
$ok((int)$one("SELECT COUNT(*) FROM migrations WHERE migration LIKE '2026_11_03_000084%'") === 1, 'esquema na migração 84');
$ok(str_contains((string)$one("SELECT GROUP_CONCAT(index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_records'"), 'PRIMARY'), 'tabela clinical_records presente');

echo "== C1 jornada no serviço real (evolução, retificação, cadeia)\n";
$p1 = $newPatient($cA, "Paciente TESTE MySQL jornada $tag");
$as($a, $cA);
$r1 = $svc->addClinicalObservation($p1, $a['professional_id'], 'Evolução TESTE 1', 'Paciente TESTE relata melhora. Ação: manter plano.', 'evolucao');
$as($a2, $cA);
$r2 = $svc->addClinicalObservation($p1, $a2['professional_id'], 'Retorno TESTE 2', 'Retorno TESTE com acentuação: ção, ã, é, ü — e emoji 🍎.', 'retorno');
$r3 = $svc->reviseRecord((int)$r2['id'], $p1, $a2['professional_id'], 'Correção TESTE do texto do retorno', ['text' => 'Retorno TESTE corrigido 🍎']);
$rows = $db->query("SELECT id, version, professional_id, previous_hash, record_hash, is_retracted, supersedes_id, content_json FROM clinical_records WHERE patient_id = $p1 ORDER BY version")->fetchAll();
$ok(count($rows) === 3 && array_column($rows, 'version') == [1, 2, 3], 'três versões 1,2,3 gravadas');
$ok((int)$rows[0]['professional_id'] === $a['professional_id'] && (int)$rows[1]['professional_id'] === $a2['professional_id'] && (int)$rows[2]['professional_id'] === $a2['professional_id'], 'autoria canônica: professional_id de quem gravou (A, A2, A2)');
$ok($rows[0]['previous_hash'] === ClinicalRecordService::GENESIS_HASH && $rows[1]['previous_hash'] === $rows[0]['record_hash'] && $rows[2]['previous_hash'] === $rows[1]['record_hash'], 'cadeia de hash: gênese → v1 → v2 → v3');
$ok((int)$rows[1]['is_retracted'] === 1 && (int)$rows[2]['supersedes_id'] === (int)$r2['id'] && (int)$rows[0]['is_retracted'] === 0, 'retificação append-only: v2 superada, v3 aponta para v2, v1 intacta');
$ok(str_contains((string)$rows[1]['content_json'], 'emoji') || str_contains(json_decode((string)$rows[1]['content_json'], true)['text'] ?? '', '🍎'), 'utf8mb4: emoji e acentos preservados no texto original');
$iv = $svc->verifyIntegrity($p1);
$ok($iv['is_valid'] === true, 'verifyIntegrity válido após a jornada');
$tl = $svc->getPatientTimeline($p1);
$byId = array_column($tl, null, 'id');
$ok(isset($byId[$r3['id']]['previous_hash']) && $byId[$r3['id']]['previous_hash'] === $rows[1]['record_hash'] && !empty($byId[$r3['id']]['professional_crn']), 'linha do tempo traz previous_hash real e CRN do autor (correção (d))');

echo "== adulteração direta no banco é detectada\n";
$orig = (string)$rows[0]['content_json'];
$db->prepare('UPDATE clinical_records SET content_json = ? WHERE id = ?')->execute([str_replace('melhora', 'piora', $orig), $rows[0]['id']]);
$iv = $svc->verifyIntegrity($p1);
$ok($iv['is_valid'] === false && (int)($iv['tampered_record_id'] ?? 0) === (int)$rows[0]['id'], 'conteúdo adulterado em v1: integridade inválida apontando v1');
$db->prepare('UPDATE clinical_records SET content_json = ? WHERE id = ?')->execute([$orig, $rows[0]['id']]);
$ok($svc->verifyIntegrity($p1)['is_valid'] === true, 'conteúdo restaurado: integridade volta a válida');

echo "== C3 isolamento entre clínicas (B contra paciente da A)\n";
$before = (int)$one('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ?', [$p1]);
$as($b, $cB);
foreach ([
    'gravar evolução' => fn() => $svc->addClinicalObservation($p1, $b['professional_id'], 'X TESTE', 'X TESTE', 'evolucao'),
    'retificar' => fn() => $svc->reviseRecord((int)$r3['id'], $p1, $b['professional_id'], 'Tentativa TESTE cruzada', ['text' => 'x']),
    'ler linha do tempo' => fn() => $svc->getPatientTimeline($p1),
    'verificar integridade' => fn() => $svc->verifyIntegrity($p1),
] as $lbl => $fn) {
    try { $res = $fn(); $ok(false, "B $lbl do paciente da A: deveria recusar (" . json_encode(is_array($res) ? count($res) : $res) . ')'); }
    catch (\Throwable $e) { $ok(true, "B $lbl do paciente da A: recusado (" . (new ReflectionClass($e))->getShortName() . ')'); }
}
$as($b, $cA); // sessão adulterada: B dizendo ser da clínica A, com o CRN da B
try { $svc->addClinicalObservation($p1, $b['professional_id'], 'X TESTE', 'X TESTE', 'evolucao'); $ok(false, 'CRN da B gravando na clínica A deveria ser recusado'); }
catch (\Throwable $e) { $ok(true, 'CRN da clínica B não grava no paciente da A mesmo com contexto adulterado'); }
$ok((int)$one('SELECT COUNT(*) FROM clinical_records WHERE patient_id = ?', [$p1]) === $before, 'banco inalterado após as tentativas cruzadas');

echo "== C4 concorrência real: 8 gravações simultâneas no mesmo paciente\n";
$p2 = $newPatient($cA, "Paciente TESTE MySQL corrida $tag");
$res = $spawn(8, 'add', $cA, $a, $p2);
$oks = array_filter($res, fn($r) => $r['ok']);
$ok(count($oks) === 8, '8/8 processos gravaram (' . implode(' | ', array_map(fn($r) => $r['ok'] ? 'ok' : $r['msg'], $res)) . ')');
$vs = array_map('intval', $db->query("SELECT version FROM clinical_records WHERE patient_id = $p2 ORDER BY version")->fetchAll(PDO::FETCH_COLUMN));
$ok($vs === range(1, 8), 'versões únicas e contíguas 1..8: ' . implode(',', $vs));
$ph = $db->query("SELECT previous_hash FROM clinical_records WHERE patient_id = $p2")->fetchAll(PDO::FETCH_COLUMN);
$ok(count(array_unique($ph)) === 8, 'nenhum previous_hash repetido (sem bifurcação da cadeia)');
$as($a, $cA);
$ok($svc->verifyIntegrity($p2)['is_valid'] === true, 'cadeia íntegra após a corrida');

echo "== C4 concorrência real: 6 retificações simultâneas da MESMA versão\n";
$base = $svc->addClinicalObservation($p2, $a['professional_id'], 'Base TESTE para corrida', 'Texto TESTE base', 'evolucao');
$res = $spawn(6, 'revise', $cA, $a2, $p2, (int)$base['id']);
$won = array_values(array_filter($res, fn($r) => $r['ok']));
$lost = array_filter($res, fn($r) => !$r['ok']);
$ok(count($won) === 1, 'exatamente 1 retificação venceu (' . count($won) . ')');
$ok(count($lost) === 5 && count(array_filter($lost, fn($r) => str_contains($r['msg'], 'já foi retificado'))) === 5, 'as outras 5 receberam "já foi retificado" (' . implode(' | ', array_unique(array_map(fn($r) => mb_substr($r['msg'], 0, 60), $lost))) . ')');
$ok((int)$one('SELECT COUNT(*) FROM clinical_records WHERE supersedes_id = ?', [$base['id']]) === 1, 'uma única versão aponta para a base (0 bifurcações)');
$ok($svc->verifyIntegrity($p2)['is_valid'] === true, 'cadeia íntegra após a corrida de retificações');

echo "== C4 fuso horário (APP_TIMEZONE=America/Sao_Paulo, MySQL 5.7)\n";
$tz = (string)$one('SELECT @@session.time_zone');
$ok($tz === (new DateTimeImmutable('now'))->format('P'), "sessão MySQL no fuso da aplicação ($tz)");
$r = $svc->addClinicalObservation($p2, $a['professional_id'], 'Fuso TESTE', 'Texto TESTE fuso', 'evolucao');
$created = (string)$one('SELECT created_at FROM clinical_records WHERE id = ?', [$r['id']]);
$diff = abs(strtotime($created) - time());
$ok($diff < 120, "created_at gravado no horário de Brasília ($created, diferença {$diff}s do relógio PHP)");
$nowDb = (string)$one('SELECT NOW()');
$ok(abs(strtotime($nowDb) - time()) < 120, "NOW() do MySQL alinhado ao PHP ($nowDb)");
$ok(substr($created, 0, 10) === date('Y-m-d'), 'data do registro = data local de hoje (sem virar o dia por UTC)');

echo "\n$pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
