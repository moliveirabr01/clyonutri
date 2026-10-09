<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
$path=getenv('DB_DATABASE');
if(!$path || !str_starts_with($path,'/tmp/ni2-299-'))throw new RuntimeException('Dedicated temporary database required');
date_default_timezone_set('America/Sao_Paulo');
$db=Connection::getInstance();$mode=$argv[1]??'prepare';
if($mode==='prepare') {
 (new Migrator($db,__DIR__.'/../../database/migrations'))->run();
 $auth=new AuthService($db);
 foreach([1=>'Autora V1',2=>'Outro tenant',3=>'Autora V2',4=>'Recepção'] as $i=>$name)$auth->registerNutritionist('NI299 '.$name,"ni299-$i@example.test",'AnamneseTeste@2026',"Clínica NI299 $i","NI299-$i",'TEST');
 $db->exec("DELETE FROM clinic_members WHERE user_id IN (3,4)");
 $db->exec("INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,3,'nutritionist','active'),(1,4,'receptionist','active')");
 $db->exec("UPDATE users SET role='receptionist' WHERE id=4");
 TenantContext::set(1,['id'=>1,'role'=>'nutritionist']);
 $repo=new PatientRepository($db);
 $patient=$repo->create(['name'=>'Paciente NI299','email'=>'ni299-patient@example.test']);
 $empty=$repo->create(['name'=>'Sem anamnese NI299']);
 $service=new AnamnesisService($db);
 $v1=$service->saveAnamnesis((int)$patient['id'],1,['objectives'=>'Objetivo histórico NI299 V1','allergies'=>'Leite NI299 V1','water_intake_ml'=>0],false);
 $v2=$service->saveAnamnesis((int)$patient['id'],3,['objectives'=>'Objetivo recente NI299 V2','allergies'=>'Ovo NI299 V2','notes'=>'Nota exclusiva V2'],true);
 // Explicitly backdated synthetic fixtures to distinguish displayed historical timestamps.
 $db->exec("UPDATE anamneses SET created_at='2026-09-01 09:10:11' WHERE version=1 AND patient_id=1");
 $db->exec("UPDATE anamneses SET created_at='2026-10-01 10:20:21' WHERE version=2 AND patient_id=1");
 $plan=(new MealPlanService($db))->createPlan((int)$patient['id'],1,'Plano origem NI299');
 TenantContext::set(2,['id'=>2,'role'=>'nutritionist']);
 $other=(new PatientRepository($db))->create(['name'=>'Paciente secreto outro tenant NI299']);
 $service->saveAnamnesis((int)$other['id'],2,['objectives'=>'SEGREDO NI299 OUTRO TENANT'],false);
 echo json_encode(['patient_id'=>$patient['id'],'empty_id'=>$empty['id'],'other_id'=>$other['id'],'plan_id'=>$plan['id'],'fixture'=>'synthetic persisted versions, dates and authors']);
} elseif($mode==='inspect') {
 echo json_encode(['versions'=>$db->query('SELECT * FROM anamneses ORDER BY id')->fetchAll(),'audit'=>$db->query("SELECT action,resource_id,details FROM audit_logs WHERE resource='anamneses' ORDER BY id")->fetchAll()]);
} elseif($mode==='break') {$db->exec('ALTER TABLE anamneses RENAME TO ni299_unavailable');}
elseif($mode==='restore') {$db->exec('ALTER TABLE ni299_unavailable RENAME TO anamneses');}
elseif($mode==='inactive') {$db->exec("UPDATE clinic_members SET status='inactive' WHERE clinic_id=1 AND user_id=1");}
elseif($mode==='active') {$db->exec("UPDATE clinic_members SET status='active' WHERE clinic_id=1 AND user_id=1");}
else throw new RuntimeException('Unknown fixture action');
