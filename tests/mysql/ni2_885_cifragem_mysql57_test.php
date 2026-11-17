<?php
/**
 * NI2-885 (v54.76) no MySQL 5.7 real: php tests/mysql/ni2_885_cifragem_mysql57_test.php <porta> <banco_com_test_no_nome>
 * Banco descartável (é recriado). Só dados TESTE. Confere, no esquema de produção (patients.allergies VARCHAR(500)):
 *  - alergia longa cifrada não cabe: no modo estrito o script registra o erro (SQLSTATE 22001) e não corta nada;
 *  - sem modo estrito (como pode estar a hospedagem) a leitura de volta detecta o corte e devolve o texto original;
 *  - depois de database/manual/ni2_885_patients_allergies_text_mysql57.sql tudo cifra, a verificação dá 0 e a 2ª execução não altera nada;
 *  - o cadastro pelo sistema (PatientRepository) com alergia de 500 caracteres falha antes da correção e funciona depois.
 */
declare(strict_types=1);
[$self, $port, $dbn] = $argv + [null, '33057', null];
if (!preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <porta> <banco com 'test'>\n"); exit(2); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $l) use (&$pass, &$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; };
$admin = new PDO("mysql:host=127.0.0.1;port=$port", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("DROP DATABASE IF EXISTS `$dbn`");
$admin->exec("CREATE DATABASE `$dbn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$ok(stripos((string)$admin->query('SELECT @@GLOBAL.sql_mode')->fetchColumn(), 'STRICT_TRANS_TABLES') !== false, 'servidor em modo estrito (padrão do 5.7): ' . $admin->query('SELECT VERSION()')->fetchColumn());
$tmp = sys_get_temp_dir() . '/ni2-885-mysql-' . getmypid();
@mkdir($tmp, 0700, true);
$envFile = "$tmp/teste.env";
$key = 'chave-teste-mysql-885-nao-usar-em-producao';
file_put_contents($envFile, "APP_ENV=local\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=$port\nDB_DATABASE=$dbn\nDB_USERNAME=root\nDB_PASSWORD=\nCLINICAL_ENCRYPTION_KEY=$key\nAPP_TIMEZONE=America/Sao_Paulo\n");
$envVars = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_DATABASE' => $dbn, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'CLINICAL_ENCRYPTION_KEY' => $key, 'APP_ENV' => 'local', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'ALLOW_TESTS_ON_DEV_DB' => '1'];
foreach ($envVars as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
$sh = static function (array $cmd) use ($root, $envVars): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $envVars + ['PATH' => getenv('PATH'), 'PHP_INI_SCAN_DIR' => (string)getenv('PHP_INI_SCAN_DIR')]);
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $o];
};
[$rc, $o] = $sh([PHP_BINARY, 'bin/migrate.php']);
$ok($rc === 0, 'migrações completas no MySQL 5.7 (bin/migrate.php)');
[$rc] = $sh([PHP_BINARY, 'bin/seed.php']); [$rc2] = $sh([PHP_BINARY, 'bin/seed.php', '--demo']);
$ok($rc === 0 && $rc2 === 0, 'seed base + demo');
$db = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$type = fn() => (string)$db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'allergies'")->fetchColumn();
$ok($type() === 'text', 'instalação nova já sai com patients.allergies TEXT (migração 98)');
// esquema como está em produção (atualizacao-v54-completa.sql criou VARCHAR(500))
$db->exec('ALTER TABLE patients MODIFY allergies VARCHAR(500) NULL');
$db->exec("DELETE FROM migrations WHERE migration = '2026_11_17_000098_ni2_885_patients_allergies_text'");
$ok($type() === 'varchar', 'esquema de produção simulado: patients.allergies VARCHAR(500)');

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\ClinicalEncryptionService as Enc;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
Connection::setInstance($db);
$clinic = (int)$db->query('SELECT id FROM clinics ORDER BY id LIMIT 1')->fetchColumn();
$user = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$prof = (int)$db->query("SELECT id FROM professionals WHERE user_id = $user")->fetchColumn();
TenantContext::set($clinic, ['id' => $user, 'role' => 'nutritionist']);
$long = 'ALERGIA-LONGA TESTE ' . str_repeat('camarão, amendoim, lactose; ', 17); // ~480 caracteres: cabe em claro, não cifrado
$long = mb_substr($long, 0, 480);
$repo = new PatientRepository($db);
$err = '';
try { $repo->create(['name' => 'Paciente TESTE 885 longa', 'allergies' => $long, 'consent_choice' => 'not_collected']); } catch (\Throwable $e) { $err = $e->getMessage(); }
$ok(str_contains($err, '22001') || stripos($err, 'too long') !== false, 'defeito reproduzido: cadastro pelo sistema com alergia de 480 caracteres falha em VARCHAR(500) (valor cifrado não cabe)');

// dados antigos em texto puro
$ins = $db->prepare("INSERT INTO patients (clinic_id, uuid, name, status, notes, allergies) VALUES (?, ?, ?, 'active', ?, ?)");
$ins->execute([$clinic, bin2hex(random_bytes(8)), 'Paciente TESTE 885 curta', 'OBS-LEGADO curta', 'ALERGIA-LEGADO curta']);
$ins->execute([$clinic, bin2hex(random_bytes(8)), 'Paciente TESTE 885 longa legado', 'OBS-LEGADO longa', $long]);
$pLong = (int)$db->lastInsertId();
$db->prepare('INSERT INTO anamneses (clinic_id, patient_id, professional_id, chief_complaint, structured_data_json) VALUES (?, ?, ?, ?, ?)')->execute([$clinic, $pLong, $prof, 'QUEIXA-LEGADO mysql', '{"q":"QUEIXA-LEGADO mysql"}']);
$svc = new ClinicalRecordService($db);
$rec = $svc->appendRecord($pLong, $prof, 'Consulta TESTE 885 MySQL', ['conduta' => 'CONDUTA-LEGADO mysql']);
$db->prepare('UPDATE clinical_records SET content_json = ? WHERE id = ?')->execute([Enc::decrypt((string)$db->query('SELECT content_json FROM clinical_records WHERE id = ' . (int)$rec['id'])->fetchColumn()), (int)$rec['id']]);
$run = fn(array $a) => $sh(array_merge([PHP_BINARY, 'bin/encrypt_clinical_records.php', "--env-file=$envFile", "--log-dir=$tmp"], $a));
$last = function (string $prefix) use ($tmp): array { $f = glob("$tmp/$prefix*.json") ?: []; sort($f); return json_decode((string)file_get_contents((string)end($f)), true) ?: []; };

[$rc, $o] = $run(['--dry-run']);
$ok($rc === 0 && str_contains($o, 'nada foi gravado'), 'dry-run no MySQL');
// modo estrito: o valor longo dá erro registrado; o resto cifra
[$rc, $o] = $run([]);
$s = $last('encrypt_clinical_records-2');
$e = $s['errors'][0] ?? [];
$ok($rc === 5 && count($s['errors'] ?? []) === 1 && ($e['id'] ?? 0) === $pLong && ($e['column'] ?? '') === 'allergies' && str_contains((string)($e['error'] ?? ''), '22001'), 'modo estrito: alergia longa vira erro 22001 no resumo (id/coluna, sem conteúdo) e a execução continua');
$ok((string)$db->query("SELECT allergies FROM patients WHERE id = $pLong")->fetchColumn() === $long, 'o valor que não coube continua íntegro em texto puro (nada cortado)');
$ok(Enc::isEncrypted((string)$db->query("SELECT notes FROM patients WHERE id = $pLong")->fetchColumn()), 'as outras colunas do mesmo registro foram cifradas');
// sem modo estrito: o MySQL cortaria; a leitura de volta impede o envelope truncado
$admin->exec("SET GLOBAL sql_mode = 'NO_ENGINE_SUBSTITUTION'");
try {
    [$rc, $o] = $run([]);
    $s = $last('encrypt_clinical_records-2');
    $ok($rc === 5 && (($s['errors'][0]['error'] ?? '') === 'gravacao_truncada'), 'sem modo estrito: corte detectado na leitura de volta (gravacao_truncada)');
    $ok((string)$db->query("SELECT allergies FROM patients WHERE id = $pLong")->fetchColumn() === $long, 'sem modo estrito: o texto original foi devolvido (nenhum envelope ilegível gravado)');
} finally {
    $admin->exec("SET GLOBAL sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
}
[$rc, $o] = $run(['--verify']);
$ok($rc === 4, 'verificação ainda acusa a pendência');
// correção do esquema pelo SQL manual (como no phpMyAdmin), duas vezes (idempotente)
$sqlFile = (string)file_get_contents($root . '/database/manual/ni2_885_patients_allergies_text_mysql57.sql');
$stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sqlFile))));
for ($i = 0; $i < 2; $i++) { foreach ($stmts as $st) { $q = $db->query($st); if ($q) { $q->fetchAll(); $q->closeCursor(); } } }
$ok($type() === 'text' && (int)$db->query("SELECT COUNT(*) FROM migrations WHERE migration = '2026_11_17_000098_ni2_885_patients_allergies_text'")->fetchColumn() === 1, 'SQL manual aplicado 2x: allergies TEXT e migração registrada uma vez');
[$rc, $o] = $run([]);
$ok($rc === 0, 'depois da correção a execução conclui sem erros');
[$rc, $o] = $run(['--verify']);
$ok($rc === 0, 'verificação: 0 em claro e 0 que não decifram');
$snap = fn() => hash('sha256', json_encode([$db->query('SELECT id, notes, allergies FROM patients ORDER BY id')->fetchAll(PDO::FETCH_NUM), $db->query('SELECT id, chief_complaint, structured_data_json FROM anamneses ORDER BY id')->fetchAll(PDO::FETCH_NUM), $db->query('SELECT id, content_json FROM clinical_records ORDER BY id')->fetchAll(PDO::FETCH_NUM)]));
$h = $snap();
[$rc, $o] = $run([]);
$s = $last('encrypt_clinical_records-2');
$ok($rc === 0 && $snap() === $h && array_sum(array_map(fn($t) => (int)$t['campos_cifrados'], $s['tables'])) === 0, 'segunda execução: nenhum registro alterado');
$ok(Enc::decrypt((string)$db->query("SELECT allergies FROM patients WHERE id = $pLong")->fetchColumn()) === $long, 'alergia longa cifrada decifra exatamente no original');
$ok(($svc->verifyIntegrity($pLong)['is_valid'] ?? false) === true && str_contains(json_encode($svc->getPatientTimeline($pLong), JSON_UNESCAPED_UNICODE), 'CONDUTA-LEGADO mysql'), 'prontuário: hash íntegro e texto original na linha do tempo');
$created = $repo->create(['name' => 'Paciente TESTE 885 longa depois', 'allergies' => $long, 'consent_choice' => 'not_collected']);
$row = $repo->findById((int)($created['id'] ?? 0));
$ok(($row['allergies'] ?? null) === $long, 'cadastro pelo sistema com alergia de 480 caracteres funciona depois da correção e lê de volta igual');
$admin->exec("DROP DATABASE IF EXISTS `$dbn`");
array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp);
echo "\nNI2-885 MySQL 5.7: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
