<?php
// Synthetic data only, persisted in a dedicated real SQLite database.
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
$path = getenv('DB_DATABASE');
$temporaryRoots = [realpath(sys_get_temp_dir()), realpath('/tmp')];
$directory = $path ? dirname($path) : '';
$dedicatedDirectory = str_starts_with(basename($directory), 'ni2-294-') && in_array(realpath(dirname($directory)), $temporaryRoots, true);
if (!$path || !str_starts_with(basename($path), 'ni2-294-') || (!in_array(realpath($directory), $temporaryRoots, true) && !$dedicatedDirectory)) throw new RuntimeException('Dedicated temporary database required.');
$db = Connection::getInstance();
$action = $argv[1] ?? 'prepare';
if ($action === 'prepare') {
    (new Migrator($db, __DIR__ . '/../../database/migrations'))->run();
    $db->exec("INSERT INTO clinics (id,uuid,name,status) VALUES (1,'ni294-c1','Clínica de teste A','active'),(2,'ni294-c2','Clínica de teste B','active')");
    $hash = password_hash('PacienteTeste@2026', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO patients (id,clinic_id,uuid,name,email,phone,password_hash,status) VALUES (?,?,?,?,?,?,?,?)");
    foreach ([[1,1,'ni294-p1','Paciente Teste A','patient-a@example.test','', $hash,'active'],[2,2,'ni294-p2','Paciente Teste B','patient-b@example.test',null,$hash,'active'],[3,1,'ni294-p3','Sem plano','empty@example.test',null,$hash,'active'],[4,1,'ni294-p4','Inativo','inactive@example.test',null,$hash,'inactive']] as $row) $stmt->execute($row);
    $db->exec("INSERT INTO users (id,uuid,name,email,password_hash,role) VALUES (1,'ni294-u1','Profissional teste','professional@example.test','unused','nutritionist')");
    $db->exec("INSERT INTO clinic_members(clinic_id,user_id,role) VALUES(1,1,'nutritionist')");
    $db->exec("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'TEST','TEST')");
    $db->exec("INSERT INTO foods(id,name,energy_kcal) VALUES(1,'Arroz de teste',124)");
    \ClyoNutri\Core\Auth\TenantContext::set(1,['id'=>1,'role'=>'nutritionist']);
    $service = new \ClyoNutri\Domain\MealPlan\MealPlanService($db);
    $plan = $service->createPlan(1,1,'Plano vigente NI2-294',null,[['name'=>'Almoço','time_of_day'=>'12:00','items'=>[['food_id'=>1,'amount'=>100,'measure_unit'=>'g']]]]);
    $service->publishPlan((int)$plan['id']);
    $service->createPlan(1,1,'Rascunho não acessível');
    echo json_encode(['prepared'=>true,'plan_id'=>$plan['id']]);
} elseif ($action === 'inspect') {
    echo json_encode([
      'tokens'=>$db->query('SELECT tokenable_type,tokenable_id,token,expires_at FROM personal_access_tokens')->fetchAll(),
      'plans'=>$db->query('SELECT id,title,status FROM meal_plans ORDER BY id')->fetchAll(),
      'snapshots'=>$db->query('SELECT COUNT(*) FROM meal_plan_versions')->fetchColumn(),
      'water'=>$db->query('SELECT patient_id,amount_ml FROM hydration_logs ORDER BY id')->fetchAll(),
    ]);
} elseif ($action === 'expire') {
    $db->exec("UPDATE personal_access_tokens SET expires_at = '2000-01-01 00:00:00' WHERE tokenable_id = 1");
} elseif ($action === 'null-expiry') {
    $db->exec("UPDATE personal_access_tokens SET expires_at = NULL WHERE tokenable_id = 1");
} elseif ($action === 'deactivate') {
    $db->exec("UPDATE patients SET status='inactive' WHERE id=1");
} elseif ($action === 'reactivate') {
    $db->exec("UPDATE patients SET status='active' WHERE id=1");
} elseif ($action === 'clinic-off') {
    $db->exec("UPDATE clinics SET status='inactive' WHERE id=1");
} elseif ($action === 'clinic-on') {
    $db->exec("UPDATE clinics SET status='active' WHERE id=1");
} elseif ($action === 'wrong-type') {
    $db->exec("UPDATE personal_access_tokens SET tokenable_type='user' WHERE tokenable_id=1");
} elseif ($action === 'ambiguous') {
    $db->exec("UPDATE patients SET email='patient-a@example.test' WHERE id=2");
} else { throw new RuntimeException('Unknown fixture action'); }
