<?php
require __DIR__.'/../../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\MealPlan\MealPlanService;
if (!str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-312-')) throw new RuntimeException('Isolated test database required');
$db=Connection::getInstance(); $mode=$argv[1]??'prepare';
if($mode==='prepare') {
 (new Migrator($db,__DIR__.'/../../database/migrations'))->run();
 $hash=password_hash('PlanTeste@2026',PASSWORD_DEFAULT);
 for($i=1;$i<=3;$i++) {
  $role=$i===3?'receptionist':'nutritionist'; $clinic=$i===3?1:$i;
  if($i<3) $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(?,?,?,'active')")->execute([$i,"ni310-c$i","Clínica sintética $i"]);
  $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(?,?,?,?,?,?,'active')")->execute([$i,"ni310-u$i","Usuário sintético $i","plan$i@example.test",$hash,$role]);
  $db->prepare("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(?,?,?,'active')")->execute([$clinic,$i,$role]);
  if($i<3) {
   $db->prepare("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(?,?,'TEST','TEST')")->execute([$i,$i]);
   $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,status) VALUES(?,?,?,?,'active')")->execute([$i,$i,"ni310-p$i","Paciente sintético $i"]);
  }
 }
 $db->exec("INSERT INTO foods(id,name,energy_kcal,protein_g,carbohydrate_g,lipid_g,fiber_g) VALUES(101,'Arroz de teste',128,2.5,28.1,0.2,1.6),(102,'Alternativa de teste',77,0.6,18.4,0.1,2.2)");
 for($i=1;$i<=2;$i++) {
  TenantContext::set($i,['id'=>$i]);$service=new MealPlanService($db);
  $service->createPlan($i,$i,"Plano sintético $i",null,[['name'=>'Almoço','time_of_day'=>'12:00','items'=>[['food_id'=>101,'amount'=>100,'measure_unit'=>'g','notes'=>'Orientação inicial','substitutions'=>[['food_id'=>102,'amount'=>150,'measure_unit'=>'g','notes'=>'Alternativa preservada']]]]]]);
 }
 TenantContext::set(1,['id'=>1]); (new MealPlanService($db))->createPlan(1,1,'Plano vazio');
 echo json_encode(['plan'=>1,'item'=>1,'other_plan'=>2,'other_item'=>2]);
} elseif($mode==='inspect') {
 echo json_encode(['plans'=>$db->query('SELECT * FROM meal_plans ORDER BY id')->fetchAll(),'meals'=>$db->query('SELECT * FROM meal_plan_meals ORDER BY id')->fetchAll(),'items'=>$db->query('SELECT * FROM meal_plan_items ORDER BY id')->fetchAll(),'subs'=>$db->query('SELECT * FROM meal_plan_substitutions ORDER BY id')->fetchAll(),'anamneses'=>$db->query('SELECT * FROM anamneses ORDER BY id')->fetchAll(),'versions'=>$db->query('SELECT * FROM meal_plan_versions ORDER BY id')->fetchAll()]);
} elseif($mode==='break_read') {$db->exec('ALTER TABLE anamneses RENAME TO ni312_missing');}
elseif($mode==='restore_read') {$db->exec('ALTER TABLE ni312_missing RENAME TO anamneses');}
elseif($mode==='break') {
 $db->exec("CREATE TRIGGER ni310_fail_totals BEFORE UPDATE ON meal_plans BEGIN SELECT RAISE(ABORT,'synthetic persistence failure'); END");
} elseif($mode==='restore') {$db->exec('DROP TRIGGER ni310_fail_totals');}
else throw new RuntimeException('Unknown fixture mode');
