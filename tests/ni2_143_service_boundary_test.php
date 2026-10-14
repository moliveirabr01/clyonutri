<?php
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\Migrator;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\PatientPortal\PatientPortalService;
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Connection::setInstance($db);
(new Migrator($db,__DIR__.'/../database/migrations'))->run();
$db->exec("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'c1','Synthetic clinic 1','active'),(2,'c2','Synthetic clinic 2','active');
INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(1,'u1','Synthetic user 1','u1@example.test','unused','nutritionist','active'),(2,'u2','Synthetic user 2','u2@example.test','unused','nutritionist','active');
INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,1,'nutritionist','active'),(2,2,'nutritionist','active');
INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'TEST','TEST'),(2,2,'TEST','TEST');
INSERT INTO patients(id,clinic_id,uuid,name,status) VALUES(1,1,'p1','Synthetic patient 1','active'),(2,2,'p2','Synthetic patient 2','active'),(3,1,'p3','Synthetic patient 3','active');");
$count=0;
function blocked(callable $call,string $name):void{global $count;try{$call();throw new RuntimeException('Unblocked '.$name);}catch(TenantSecurityException $e){if($e->getCode()!==403)throw $e;$count++;echo "PASS $name\n";}}
TenantContext::set(1,['id'=>1,'role'=>'nutritionist']);$portal=new PatientPortalService($db);
$valid=(new \ClyoNutri\Domain\PatientPortal\PatientOnboardingService($db))->create(1,1,1);if(empty($valid['invitation_code']))throw new RuntimeException('Legitimate invitation rejected');
$before=$db->query('SELECT COUNT(*) FROM patient_app_invitations')->fetchColumn();
blocked(fn()=>$portal->createInvitation(1,2),'Invitation refuses foreign professional');
blocked(fn()=>$portal->sendMessageToProfessional(1,2,'must not persist'),'Message refuses foreign professional before conversation creation');
blocked(fn()=>$portal->uploadDocument(1,['mime_type'=>'application/pdf','size_bytes'=>10,'title'=>'synthetic','file_path'=>'test.pdf'],'professional',2),'Upload refuses foreign author');
if($before!==$db->query('SELECT COUNT(*) FROM patient_app_invitations')->fetchColumn() || (int)$db->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn()!==0 || (int)$db->query('SELECT COUNT(*) FROM patient_documents')->fetchColumn()!==0)throw new RuntimeException('Unauthorized writes persisted');
$db->exec("UPDATE clinic_members SET status='revoked' WHERE user_id=1");blocked(fn()=>TenantGuard::assertProfessionalBelongsToClinic(1,1,$db),'Revoked professional membership denied');$db->exec("UPDATE clinic_members SET status='active' WHERE user_id=1");
TenantContext::set(1,['id'=>1,'role'=>'patient']);
blocked(fn()=>$portal->uploadDocument(1,['mime_type'=>'application/pdf','size_bytes'=>10,'title'=>'synthetic','file_path'=>'test.pdf'],'professional',1),'Patient cannot forge professional upload authorship');
blocked(fn()=>$portal->getEvolutionTimeline(3),'Patient cannot read another patient in same clinic');
blocked(fn()=>$portal->recordSelfReportedMetric(3,'weight',70,'kg'),'Patient cannot write another patient in same clinic');
if($portal->getEvolutionTimeline(1)['patient_id']!==1)throw new RuntimeException('Own timeline broken');
$logs=$db->query("SELECT user_id,ip_address,user_agent,details FROM audit_logs WHERE action LIKE 'SECURITY_%'")->fetchAll();
foreach($logs as $row){if($row['ip_address']!==null || $row['user_agent']!=='security-event' || str_contains($row['details'],'example.test'))throw new RuntimeException('PII in security log');}
if((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'SECURITY_%' AND user_id IS NULL")->fetchColumn()<2)throw new RuntimeException('Patient misattributed to professional in audit');
echo "PASS $count security boundaries, legitimate invitation/timeline, no unauthorized writes and sanitized audit\n";
