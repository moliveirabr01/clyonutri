<?php
// v54.21: prova no MySQL/MariaDB (php tests/mysql/v5421_materials_mysql_smoke.php <socket> <banco_com_test_no_nome>). Banco descartável
// já com o SQL manual da migração 83; cria tabelas mínimas de apoio. Só dados TESTE.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use ClyoNutri\Domain\Materials\MaterialService as M;
[$self, $sock, $dbn] = $argv + [null, null, null];
if (!$sock || !preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <socket> <banco com 'test'>\n"); exit(2); }
$pdo = new PDO("mysql:unix_socket=$sock;dbname=$dbn;charset=utf8mb4", 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach (["CREATE TABLE IF NOT EXISTS patients (id INT PRIMARY KEY, clinic_id INT, name VARCHAR(160), status VARCHAR(20), email VARCHAR(160) NULL) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS clinic_members (clinic_id INT, user_id INT, role VARCHAR(30), status VARCHAR(20)) ENGINE=InnoDB"] as $q) { $pdo->exec($q); }
foreach (['educational_material_shares', 'educational_materials', 'patients', 'clinic_members'] as $t) { $pdo->exec("DELETE FROM $t"); }
$pdo->exec("INSERT INTO patients VALUES (1, 10, 'Paciente TESTE Ação', 'active', NULL), (2, 10, 'Paciente TESTE Dois', 'active', NULL), (3, 20, 'Paciente TESTE B', 'active', NULL)");
$pdo->exec("INSERT INTO clinic_members VALUES (10, 1, 'owner', 'active'), (20, 2, 'owner', 'active')");
$ok = static function (bool $c, string $l) { echo ($c ? 'ok    ' : 'FALHA ') . $l . "\n"; if (!$c) { $GLOBALS['falhas'] = ($GLOBALS['falhas'] ?? 0) + 1; } };
$dir = sys_get_temp_dir() . '/clyonutri-mat-my-' . getmypid(); $tmp = tempnam(sys_get_temp_dir(), 'mat');
file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$svc = new M($pdo, $dir);
$m = $svc->upload(10, 1, ['name' => 'guia ação.pdf', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], 'Guia TESTE 50% ação', 'Descrição TESTE', 'orientacao');
$ok($svc->list(10, 'ação')[0]['id'] == $m && count($svc->list(10, '50%')) === 1 && count($svc->list(10, '_')) === 0, 'busca com ESCAPE e acentos (utf8mb4)');
$ok($svc->share(10, 1, $m, [1, 2], 'Recado TESTE ação') === 2 && $svc->share(10, 1, $m, [1]) === 0, 'envio idempotente');
try { $pdo->prepare('INSERT INTO educational_material_shares (clinic_id, material_id, patient_id, shared_by_user_id, shared_at) VALUES (10, ?, 1, 1, NOW())')->execute([$m]); $ok(false, 'índice único material+paciente'); }
catch (PDOException $e) { $ok(true, 'índice único material+paciente segura duplicata no banco'); }
try { $svc->share(10, 1, $m, [3]); $ok(false, 'paciente de outra clínica'); } catch (\Throwable $e) { $ok(true, 'paciente de outra clínica recusado'); }
$ok($svc->patientList(10, 1)[0]['note'] === 'Recado TESTE ação' && $svc->patientList(20, 1) === [], 'área do paciente filtrada pela clínica');
$f = $svc->patientFile(10, 1, $m);
$ok(is_file($f['path']) && $pdo->query('SELECT COUNT(*) FROM educational_material_shares WHERE first_opened_at IS NOT NULL')->fetchColumn() == 1, 'download marca a primeira abertura');
$sid = (int)$pdo->query('SELECT id FROM educational_material_shares WHERE patient_id = 1')->fetchColumn();
$svc->revoke(10, 1, $sid); $ok(count($svc->patientList(10, 1)) === 0 && $svc->share(10, 1, $m, [1]) === 1, 'revogar e reenviar');
$ok((int)$svc->list(10)[0]['shared_count'] === 2, 'contagem de envios na biblioteca');
foreach (['educational_material_shares', 'educational_materials', 'patients', 'clinic_members'] as $t) { $pdo->exec("DELETE FROM $t"); }
foreach (glob("$dir/*/*") ?: [] as $x) { @unlink($x); } foreach (glob("$dir/*") ?: [] as $x) { @rmdir($x); } @rmdir($dir); @unlink($tmp);
echo empty($GLOBALS['falhas']) ? "SMOKE v54.21 MATERIAIS MYSQL OK\n" : "SMOKE v54.21 MATERIAIS MYSQL FALHOU\n"; exit(empty($GLOBALS['falhas']) ? 0 : 1);
