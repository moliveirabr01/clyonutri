<?php
/**
 * NI2-220 (v54.25): anamnese no MySQL 5.7 real depois da migração 86 (php tests/mysql/ni2_220_anamnese_mysql_smoke.php <socket> <banco_com_test_no_nome>).
 * Banco descartável com a 86 aplicada e semeado por tests/mysql/ni2_233_e2e_seed.php. Só dados TESTE; recusa banco sem "test".
 * Confere: pergunta fechada em branco grava NULL (não avaliado), valor fora da faixa é recusado sem gravar (modo estrito do MySQL),
 * e o rascunho mantém autor e data de início, registrando à parte quem editou por último.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/homologacao/ni2_233_fixture.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Clinical\ClinicalInputException;

[$self, $sock, $dbn] = $argv + [null, null, null];
if (!$sock || !preg_match('/test/', (string)$dbn)) { fwrite(STDERR, "uso: <socket> <banco com 'test'>\n"); exit(2); }
date_default_timezone_set('America/Sao_Paulo');
foreach (['DB_CONNECTION' => 'mysql', 'DB_SOCKET' => $sock, 'DB_HOST' => '127.0.0.1', 'DB_PORT' => getenv('NI2_233_MYSQL_PORT') ?: '33057', 'DB_DATABASE' => $dbn, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'APP_ENV' => 'local'] as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
$db = Connection::getInstance();
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $l) use (&$pass, &$fail) { echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; };
$fx = ni2_233_fixture($db);
$a = $fx['a']; $a2 = $fx['a2']; $cA = $fx['clinicA'];
$db->prepare("INSERT INTO patients (clinic_id, uuid, name, birth_date, gender, status) VALUES (?, ?, 'Paciente TESTE 220 MySQL', '1990-05-10', 'F', 'active')")->execute([$cA, bin2hex(random_bytes(8))]);
$pid = (int)$db->lastInsertId();
TenantContext::set($cA, ['id' => $a['user_id'], 'role' => 'nutritionist']);
$svc = new AnamnesisService();
$count = static fn() => (int)$db->query('SELECT COUNT(*) FROM anamneses WHERE patient_id = ' . $pid)->fetchColumn();

$col = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'anamneses' AND COLUMN_NAME IN ('has_surgeries','medication_usage','supplement_usage','intolerance_assessment','alcohol_consumption','smoking_status','physical_activity') AND IS_NULLABLE = 'YES' AND COLUMN_DEFAULT IS NULL")->fetchColumn();
$ok((int)$col === 7, 'migração 86: as 7 perguntas fechadas aceitam NULL com padrão NULL');

try { $svc->saveAnamnesis($pid, $a['professional_id'], ['chief_complaint' => 'Queixa TESTE', 'water_intake_ml' => '99999', 'sleep_hours' => '30'], false); $ok(false, 'água 99999 e sono 30 recusados'); }
catch (ClinicalInputException $e) { $f = $e->fieldErrors(); $ok(isset($f['water_intake_ml'], $f['sleep_hours']), 'água 99999 e sono 30 recusados com erro por campo'); }
$ok($count() === 0, 'nada gravado depois da recusa');

$d = $svc->saveAnamnesis($pid, $a['professional_id'], ['chief_complaint' => 'Queixa TESTE rascunho', 'water_intake_ml' => '2000'], true);
$row = $db->query('SELECT * FROM anamneses WHERE id = ' . (int)$d['id'])->fetch(PDO::FETCH_ASSOC);
$ok($row['smoking_status'] === null && $row['physical_activity'] === null && $row['has_surgeries'] === null, 'perguntas não respondidas gravadas como NULL (não avaliado), sem negativa inventada');
$created = $row['created_at'];
sleep(1);
TenantContext::set($cA, ['id' => $a2['user_id'], 'role' => 'nutritionist']);
$d2 = $svc->saveAnamnesis($pid, $a2['professional_id'], ['chief_complaint' => 'Queixa TESTE editada', 'water_intake_ml' => '2100', 'smoking_status' => 'non_smoker'], true);
$row2 = $db->query('SELECT * FROM anamneses WHERE patient_id = ' . $pid . ' AND is_draft = 1')->fetch(PDO::FETCH_ASSOC);
$ok((int)$row2['professional_id'] === (int)$a['professional_id'] && $row2['created_at'] === $created, 'rascunho mantém autor e data de início');
$ok((int)$row2['updated_by_professional_id'] === (int)$a2['professional_id'] && $row2['updated_at'] !== null, 'última edição registrada à parte (quem e quando)');
$ok($row2['smoking_status'] === 'non_smoker' && $row2['physical_activity'] === null, 'resposta marcada gravada; a não marcada continua NULL');
$ok((int)$row2['water_intake_ml'] === 2100, 'água dentro da faixa gravada como inteiro');
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail ? 1 : 0);
