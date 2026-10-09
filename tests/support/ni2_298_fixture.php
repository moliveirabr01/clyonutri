<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
$path=getenv('DB_DATABASE');
if (!$path || !str_starts_with($path,'/tmp/ni2-298-')) throw new RuntimeException('Dedicated test SQLite path required');
$db=Connection::getInstance();
$mode=$argv[1]??'prepare';
if($mode==='prepare') {
 (new Migrator($db,__DIR__.'/../../database/migrations'))->run();
 $hash=password_hash('AgendaTeste@2026',PASSWORD_DEFAULT);
 for($i=1;$i<=3;$i++) {
  $db->prepare("INSERT INTO clinics(id,uuid,name,status) VALUES(?,?,?,'active')")->execute([$i,"ni298-c$i","Clínica de teste $i"]);
  $db->prepare("INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(?,?,?,?,?,'nutritionist','active')")->execute([$i,"ni298-u$i","Profissional teste $i","agenda$i@example.test",$hash]);
  $db->prepare("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(?,?,'nutritionist','active')")->execute([$i,$i]);
  $db->prepare("INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(?,?,'TEST','TEST')")->execute([$i,$i]);
 }
 $today=date('Y-m-d');
 foreach(['scheduled','confirmed','in_progress','completed','cancelled','no_show','previous','next','other-tenant'] as $n=>$status) {
  $id=$n+1; $clinic=$status==='other-tenant'?2:1;
  $db->prepare("INSERT INTO patients(id,clinic_id,uuid,name,status) VALUES(?,?,?,?,'active')")->execute([$id,$clinic,"ni298-p$id","NI298 $status"]);
  $at=$today.' '.sprintf('%02d:00:00',$n+1);
  if($status==='previous')$at=date('Y-m-d',strtotime($today.' -1 day')).' 23:59:59';
  if($status==='next')$at=date('Y-m-d',strtotime($today.' +1 day')).' 00:00:00';
  $stored=in_array($status,['previous','next','other-tenant'])?'scheduled':$status;
  $db->prepare("INSERT INTO appointments(id,clinic_id,patient_id,professional_id,scheduled_at,status,type,duration_minutes) VALUES(?,?,?,?,?,?,'first_visit',30)")->execute([$id,$clinic,$id,$clinic,$at,$stored]);
  $db->prepare("INSERT INTO appointment_status_history(clinic_id,appointment_id,from_status,to_status,notes) VALUES(?,?,NULL,?,'Synthetic fixture')")->execute([$clinic,$id,$stored]);
 }
 echo json_encode(['date'=>$today,'local_now'=>date(DATE_ATOM),'utc_now'=>gmdate(DATE_ATOM),'database_current_date'=>$db->query('SELECT CURRENT_DATE')->fetchColumn(),'legacy_utc_count'=>$db->query('SELECT COUNT(*) FROM appointments WHERE clinic_id=1 AND date(scheduled_at)=CURRENT_DATE')->fetchColumn(),'fixture'=>'synthetic persisted appointments, not simulated responses']);
} elseif($mode==='bootstrap') {
 $auth=new \ClyoNutri\Core\Auth\AuthService($db);
 for($i=1;$i<=3;$i++)$auth->registerNutritionist("Profissional regressão $i","regression$i@example.test",'AgendaTeste@2026',"Clínica regressão $i","TEST-$i",'TEST');
 echo 'Three real fixture accounts registered through AuthService';
} elseif($mode==='inspect') {
 echo json_encode(['appointments'=>$db->query('SELECT id,clinic_id,patient_id,scheduled_at,status FROM appointments ORDER BY id')->fetchAll(),'history'=>$db->query('SELECT * FROM appointment_status_history ORDER BY id')->fetchAll()]);
} elseif($mode==='break') {$db->exec('ALTER TABLE appointments RENAME TO ni298_unavailable');}
elseif($mode==='restore') {$db->exec('ALTER TABLE ni298_unavailable RENAME TO appointments');}
else throw new RuntimeException('Unknown fixture operation');
