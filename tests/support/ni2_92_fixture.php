<?php
// Fixture do teste real da NI2-92 (hidratação). Dados fictícios TESTE. Só roda em SQLite isolado (/tmp/ni2-92-*).
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;

if (!preg_match('#^/tmp/ni2-92-#', getenv('DB_DATABASE') ?: '')) {
    throw new RuntimeException('Isolated test database required');
}
date_default_timezone_set('America/Sao_Paulo');
$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    $db->exec("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'n92-c1','TESTE Clínica Um','active'),(2,'n92-c2','TESTE Clínica Dois','active')");
    $hash = password_hash('PacienteTeste@2026', PASSWORD_DEFAULT);
    $p = $db->prepare('INSERT INTO patients(id,clinic_id,uuid,name,email,password_hash,status) VALUES(?,?,?,?,?,?,?)');
    $p->execute([1, 1, 'n92-p1', 'TESTE Ana', 'ana92@example.test', $hash, 'active']);
    $p->execute([2, 1, 'n92-p2', 'TESTE Bruno', 'bruno92@example.test', $hash, 'active']);
    $p->execute([3, 2, 'n92-p3', 'TESTE Carla', 'carla92@example.test', $hash, 'active']);
    $db->exec("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(1,'n92-u1','TESTE Nutri Um','nutri92@example.test','x','nutritionist','active')");
    $db->exec("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'CRN-T1','CRN-3')");
    // Meta: rascunho mais novo (3500) não pode vencer a anamnese finalizada (2000).
    $db->exec("INSERT INTO anamneses (clinic_id,patient_id,professional_id,version,water_intake_ml,is_draft) VALUES (1,1,1,1,2000,0),(1,1,1,2,3500,1)");
    echo json_encode(['ok' => true]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) throw new RuntimeException('Somente SELECT');
    echo json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC));
} elseif ($mode === 'age') {
    // Envelhece um registro (simula criação há 25 h) para testar a janela de edição.
    $id = (int)($argv[2] ?? 0);
    $old = (new DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s');
    $db->prepare('UPDATE hydration_logs SET created_at = ?, logged_at = ? WHERE id = ?')->execute([$old, $old, $id]);
    echo json_encode(['ok' => true]);
}
