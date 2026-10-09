<?php
require __DIR__.'/../../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Domain\MealPlan\MealPlanService;
if (!str_starts_with(getenv('DB_DATABASE') ?: '', '/tmp/ni2-281-')) throw new RuntimeException('Isolated test database required');
$db=Connection::getInstance(); $mode=$argv[1]??'prepare';
if($mode==='prepare') {
 (new Migrator($db,__DIR__.'/../../database/migrations'))->run();
 $hash=password_hash('PlanTeste@2026',PASSWORD_DEFAULT);
 for($i=1;$i<=3;$i++) {
  $role=$i===3?'receptionist':'nutritionist'; $clinic=$i===3?1:$i;
  if($i<3) $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(?,?,?,'active')")->execute([$i,"ni281-c$i","Clínica sintética $i"]);
  $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(?,?,?,?,?,?,'active')")->execute([$i,"ni281-u$i","Usuário sintético $i","plan$i@example.test",$hash,$role]);
  $db->prepare("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(?,?,?,'active')")->execute([$clinic,$i,$role]);
  if($i<3) {
   $db->prepare("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(?,?,'TEST','TEST')")->execute([$i,$i]);
   $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,status) VALUES(?,?,?,?,'active')")->execute([$i,$i,"ni281-p$i","Paciente sintético $i"]);
  }
 }
 $db->exec("INSERT INTO foods(id,name,energy_kcal,protein_g,carbohydrate_g,lipid_g,fiber_g) VALUES(101,'Arroz de teste',128,2.5,28.1,0.2,1.6),(102,'Alternativa de teste',77,0.6,18.4,0.1,2.2)");
 for($i=1;$i<=2;$i++) {
  TenantContext::set($i,['id'=>$i]);$service=new MealPlanService($db);
  $service->createPlan($i,$i,"Plano sintético $i",null,[['name'=>'Almoço','time_of_day'=>'12:00','items'=>[['food_id'=>101,'amount'=>100,'measure_unit'=>'g','notes'=>'Orientação inicial','substitutions'=>[['food_id'=>102,'amount'=>150,'measure_unit'=>'g','notes'=>'Alternativa preservada']]]]]]);
 }
 TenantContext::set(1,['id'=>1]); (new MealPlanService($db))->createPlan(1,1,'Plano vazio');
 $db->exec("UPDATE clinic_members SET role='owner' WHERE user_id=1; INSERT INTO consent_terms(id,version,title,purpose_code,term_text,is_active) VALUES(1,'TEST-1','Termo sintético','clinical_care','Texto somente para teste de manifestação. Não utilizar em produção.',1); INSERT INTO foods(id,clinic_id,name,energy_kcal) VALUES(103,NULL,'Maçã sintética',52),(104,2,'Exclusivo da clínica 2',90);");
 for($j=200;$j<260;$j++) $db->prepare('INSERT INTO foods(id,name,energy_kcal) VALUES(?,?,100)')->execute([$j,'Alimento sintético '.$j]);
 echo json_encode(['plan'=>1,'item'=>1,'other_plan'=>2,'other_item'=>2]);
} elseif($mode==='inspect') {
 echo json_encode(['patients'=>$db->query('SELECT * FROM patients ORDER BY id')->fetchAll(),'consents'=>$db->query('SELECT * FROM patient_consents ORDER BY id')->fetchAll(),'audit'=>$db->query('SELECT * FROM audit_logs ORDER BY id')->fetchAll(),'profiles'=>$db->query('SELECT u.id,u.name,p.specialty,p.bio FROM users u LEFT JOIN professionals p ON p.user_id=u.id ORDER BY u.id')->fetchAll(),'clinics'=>$db->query('SELECT id,name FROM clinics ORDER BY id')->fetchAll()]);
} elseif($mode==='break') {
 $db->exec("CREATE TRIGGER ni281_fail_totals BEFORE INSERT ON patient_consents BEGIN SELECT RAISE(ABORT,'synthetic persistence failure'); END");
} elseif($mode==='restore') {$db->exec('DROP TRIGGER ni281_fail_totals');}
else throw new RuntimeException('Unknown fixture mode');
