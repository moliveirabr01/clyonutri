<?php
// Fixture do teste real da NI2-89 (API versionada do paciente). Dados fictícios.
// Só roda em SQLite isolado (/tmp/ni2-89-*).
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;

$isMysqlTestDb = getenv('DB_CONNECTION') === 'mysql' && preg_match('/^ni2_(24|73|89|91|96|137|138|140)_test_[a-z0-9]+$/', getenv('DB_DATABASE') ?: '');
if (!$isMysqlTestDb && !preg_match('#^/tmp/ni2-(24|73|89|91|96|137|138|140)-#', getenv('DB_DATABASE') ?: '')) {
    throw new RuntimeException('Isolated test database required');
}
$db = Connection::getInstance();
$mode = $argv[1] ?? 'prepare';

if ($mode === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    $db->exec("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'n89-c1','Clínica Fictícia Um','active'),(2,'n89-c2','Clínica Fictícia Dois','active')");
    $hash = password_hash('PacienteTeste@2026', PASSWORD_DEFAULT);
    $p = $db->prepare('INSERT INTO patients(id,clinic_id,uuid,name,email,password_hash,status) VALUES(?,?,?,?,?,?,?)');
    $p->execute([1, 1, 'n89-p1', 'Ana Fictícia', 'ana@example.test', $hash, 'active']);
    $p->execute([2, 1, 'n89-p2', 'Bruno Fictício', 'bruno@example.test', $hash, 'active']);
    $p->execute([3, 2, 'n89-p3', 'Carla Fictícia', 'carla@example.test', $hash, 'active']);
    $db->exec("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(1,'n89-u1','Nutri Um','nutri1@example.test','x','nutritionist','active'),(2,'n89-u2','Nutri Dois','nutri2@example.test','x','nutritionist','active')");
    $db->exec("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,1,'owner','active'),(2,2,'owner','active')");
    $db->exec("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'CRN-T1','CRN-3'),(2,2,'CRN-T2','CRN-3')");
    $db->exec("INSERT INTO foods(id,name,energy_kcal) VALUES(1,'Arroz fictício',124)");
    $future = (new DateTimeImmutable('+3 days'))->format('Y-m-d 10:00:00');
    $a = $db->prepare("INSERT INTO appointments(id,clinic_id,patient_id,professional_id,scheduled_at,duration_minutes,status,type) VALUES(?,?,?,?,?,60,'scheduled','follow_up')");
    $a->execute([11, 1, 1, 1, $future]);   // Ana
    $a->execute([12, 1, 2, 1, $future]);   // Bruno (mesma clínica)
    $a->execute([13, 2, 3, 2, $future]);   // Carla (outra clínica)
    // Plano publicado da Ana e da Carla (a da Carla com receita prescrita).
    TenantContext::set(1, ['id' => 1, 'role' => 'nutritionist']);
    $svc = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);
    $plan = $svc->createPlan(1, 1, 'Plano fictício da Ana', null, [['name' => 'Almoço', 'time_of_day' => '12:00', 'items' => [['food_id' => 1, 'amount' => 100, 'measure_unit' => 'g']]]]);
    $svc->publishPlan((int)$plan['id']);
    TenantContext::set(2, ['id' => 2, 'role' => 'nutritionist']);
    $svc = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);
    $planC = $svc->createPlan(3, 2, 'Plano fictício da Carla', null, [['name' => 'Jantar', 'time_of_day' => '19:00', 'items' => [['food_id' => 1, 'amount' => 80, 'measure_unit' => 'g']]]]);
    $svc->publishPlan((int)$planC['id']);
    $db->exec("INSERT INTO recipes(id,clinic_id,title,instructions,is_custom) VALUES(21,2,'Receita privada da clínica dois','Misturar.',1),(22,NULL,'Receita da biblioteca','Assar.',0)");
    $db->prepare("INSERT INTO recipe_prescriptions(clinic_id,meal_plan_id,patient_id,recipe_id,prescribed_servings) VALUES(2,?,3,21,1)")->execute([(int)$planC['id']]);
    // Conversa do Bruno com mensagem e anexo.
    $db->exec("INSERT INTO chat_conversations(id,clinic_id,patient_id,professional_id,status) VALUES(31,1,2,1,'active'),(32,2,3,2,'active')");
    $db->exec("INSERT INTO chat_messages(id,conversation_id,clinic_id,sender_type,sender_id,message) VALUES(41,31,1,'professional',1,'Mensagem privada para o Bruno'),(42,32,2,'professional',2,'Mensagem privada para a Carla')");
    $file = (getenv('NI2_FIXTURE_DIR') ?: dirname((string)getenv('DB_DATABASE'))) . '/anexo-bruno.txt';
    file_put_contents($file, 'conteudo privado do Bruno');
    $db->prepare("INSERT INTO chat_attachments(id,clinic_id,conversation_id,message_id,original_name,stored_name,mime_type,size_bytes,sha256_hash,storage_path,uploaded_by_type,uploaded_by_id) VALUES(51,1,31,41,'exame-bruno.txt','anexo-bruno.txt','text/plain',?,?,?,'professional',1)")
        ->execute([filesize($file), hash_file('sha256', $file), $file]);
    echo json_encode(['ok' => true]);
} elseif ($mode === 'sql') {
    $q = (string)($argv[2] ?? '');
    if (!preg_match('/^\s*SELECT\s/i', $q)) throw new RuntimeException('Somente SELECT');
    echo json_encode($db->query($q)->fetchAll(PDO::FETCH_ASSOC));
} elseif ($mode === 'deactivate') {
    $db->exec("UPDATE patients SET status='inactive' WHERE id=1");
} elseif ($mode === 'anonymize') {
    $db->exec("UPDATE patients SET status='anonymized' WHERE id=1");
} elseif ($mode === 'reactivate') {
    $db->exec("UPDATE patients SET status='active' WHERE id=1");
} elseif ($mode === 'expire') {
    $db->exec("UPDATE personal_access_tokens SET expires_at='2000-01-01 00:00:00' WHERE tokenable_type='patient' AND tokenable_id=1");
} else {
    throw new RuntimeException('Unknown fixture mode');
}
