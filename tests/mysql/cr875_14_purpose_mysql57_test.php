<?php
/**
 * CR875-14 (v54.76) no MySQL 5.7 real: php tests/mysql/cr875_14_purpose_mysql57_test.php <porta> <banco_com_test_no_nome>
 *  - banco migrado; "produção" simulada sem a migração 101; SQL manual 2x (como no phpMyAdmin);
 *  - serviço real grava purpose, recusa troca de tipo e aceita link antigo (purpose NULL) pela regra antiga;
 *  - limite de links inválidos grava em login_attempts no MySQL.
 */
declare(strict_types=1);
[$self, $port, $dbn] = $argv + [null, '33057', null];
if (!preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <porta> <banco com 'test'>\n"); exit(2); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $l) use (&$pass, &$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; };
$admin = new PDO("mysql:host=127.0.0.1;port=$port", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ok(str_starts_with((string)$admin->query('SELECT VERSION()')->fetchColumn(), '5.7'), 'MySQL ' . $admin->query('SELECT VERSION()')->fetchColumn());
$env = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_DATABASE' => $dbn, 'APP_ENV' => 'local', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'CLINICAL_ENCRYPTION_KEY' => 'chave-teste-87514', 'ALLOW_TESTS_ON_DEV_DB' => '1'];
$admin->exec("DROP DATABASE IF EXISTS `$dbn`");
$admin->exec("CREATE DATABASE `$dbn` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$p = proc_open([PHP_BINARY, 'bin/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + ['PATH' => getenv('PATH'), 'PHP_INI_SCAN_DIR' => (string)getenv('PHP_INI_SCAN_DIR'), 'LD_LIBRARY_PATH' => (string)getenv('LD_LIBRARY_PATH')]);
$o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); $rc = proc_close($p);
$ok($rc === 0, 'migrações no MySQL 5.7' . ($rc ? ': ' . substr($o, -300) : ''));
$db = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$col = fn() => (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='password_resets' AND COLUMN_NAME='purpose'")->fetchColumn();
$ok($col() === 1, 'migração 101 criou password_resets.purpose');
$db->exec("ALTER TABLE password_resets DROP COLUMN purpose");
$db->exec("DELETE FROM migrations WHERE migration = '2026_11_17_000101_cr875_14_password_reset_purpose'");
// link antigo (antes da 101) de conta ativa
$db->exec("INSERT INTO clinics (uuid, name, email, status) VALUES ('c87514', 'Clínica TESTE 87514', 'c87514@example.test', 'active')");
$cid = (int)$db->lastInsertId();
$hash = password_hash('Teste@12345', PASSWORD_DEFAULT);
foreach ([['ativa.87514@example.test', 'active'], ['convidada.87514@example.test', 'invited']] as [$em, $st]) {
    $db->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES (?, 'TESTE', ?, ?, 'nutritionist', 1, ?)")->execute([uniqid('u'), $em, $hash, $st]);
    $db->prepare("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (?, ?, 'nutritionist', 'active')")->execute([$cid, (int)$db->lastInsertId()]);
}
$legacy = bin2hex(random_bytes(32));
$db->prepare("INSERT INTO password_resets (email, token, created_at, expires_at) VALUES ('ativa.87514@example.test', ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")->execute([hash('sha256', $legacy)]);
$manual = (string)file_get_contents($root . '/database/manual/cr875_14_password_reset_purpose_mysql57.sql');
// Como o phpMyAdmin: divide no ";" fora de aspas e executa em ordem, mostrando os SELECTs.
$run = function (string $sql) use ($db): array {
    $stmts = []; $cur = ''; $q = null; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $ch = $sql[$i];
        if ($q === null && $ch === '-' && ($sql[$i + 1] ?? '') === '-') { while ($i < $n && $sql[$i] !== "\n") { $i++; } continue; }
        if ($q !== null) { $cur .= $ch; if ($ch === $q) { if (($sql[$i + 1] ?? '') === $q) { $cur .= $sql[++$i]; } else { $q = null; } } continue; }
        if ($ch === "'" || $ch === '"') { $q = $ch; $cur .= $ch; continue; }
        if ($ch === ';') { if (trim($cur) !== '') { $stmts[] = trim($cur); } $cur = ''; continue; }
        $cur .= $ch;
    }
    if (trim($cur) !== '') { $stmts[] = trim($cur); }
    $out = ''; $rc = 0;
    try { foreach ($stmts as $st) { $r = $db->query($st); if ($r && $r->columnCount() > 0) $out .= json_encode($r->fetchAll(), JSON_UNESCAPED_UNICODE) . "\n"; } }
    catch (\Throwable $e) { $rc = 1; $out .= $e->getMessage(); }
    return [$rc, $out];
};
[$r1, $o1] = $run($manual); [$r2, $o2] = $run($manual);
$mig = (int)$db->query("SELECT COUNT(*) FROM migrations WHERE migration = '2026_11_17_000101_cr875_14_password_reset_purpose'")->fetchColumn();
$ok($r1 === 0 && $r2 === 0 && $col() === 1 && $mig === 1 && str_contains($o2, 'purpose já existe'), "SQL manual 101 roda 2x no MySQL 5.7 (coluna 1, migração registrada $mig vez)" . ($r1 || $r2 ? ': ' . $o1 . $o2 : ''));
// conexão nova: o cache de colunas (SchemaInfo) é por conexão
$db2 = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$dbn;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$svc = new \ClyoNutri\Core\Auth\PasswordResetService($db2);
$ok($svc->validateToken($legacy, 'reset') !== null && $svc->validateToken($legacy, 'invite') === null, 'link antigo (purpose NULL) de conta ativa: vale só como nova senha');
$inv = $svc->issueAdminToken('convidada.87514@example.test', 3600, 'invite');
$rst = $svc->issueAdminToken('ativa.87514@example.test', 3600, 'reset');
$pp = $db2->query("SELECT email, purpose FROM password_resets ORDER BY email")->fetchAll(PDO::FETCH_KEY_PAIR);
$ok(($pp['convidada.87514@example.test'] ?? null) === 'invite' && ($pp['ativa.87514@example.test'] ?? null) === 'reset', 'serviço grava purpose invite/reset no MySQL');
$ok($svc->validateToken($inv['token'], 'invite') !== null && $svc->validateToken($inv['token'], 'reset') === null
    && $svc->validateToken($rst['token'], 'reset') !== null && $svc->validateToken($rst['token'], 'invite') === null, 'convite só abre /convite e nova senha só abre /recuperar-senha');
// convite emitido para conta que ficou ativa: purpose=invite não vira link de nova senha
$db2->exec("UPDATE users SET status = 'active' WHERE email = 'convidada.87514@example.test'");
$ok($svc->validateToken($inv['token'], 'reset') === null, 'convite de quem já ficou ativa não serve como nova senha (tipo gravado manda)');
$lim = new \ClyoNutri\Core\Security\TokenLinkRateLimiter($db2);
for ($i = 0; $i < 10; $i++) { $lim->fail('invite', '203.0.113.7'); }
$ok($lim->blocked('invite', '203.0.113.7') && !$lim->blocked('reset', '203.0.113.7') && !$lim->blocked('invite', '203.0.113.8'), 'limite no MySQL: 10 falhas travam aquele IP só naquele tipo de link');
$admin->exec("DROP DATABASE IF EXISTS `$dbn`");
echo "\nCR875-14 MySQL 5.7: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
