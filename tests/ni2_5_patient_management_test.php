<?php
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\{Connection,Migrator};
use ClyoNutri\Domain\Patient\{PatientRepository,DuplicatePatientException};
use ClyoNutri\Domain\Privacy\{ConsentManagementService,LgpdService,DataSubjectRequestService};
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');Connection::setInstance($db);
(new Migrator($db,__DIR__.'/../database/migrations'))->run();
$db->exec("INSERT INTO clinics(id,uuid,name,status) VALUES(1,'c1','Test 1','active'),(2,'c2','Test 2','active');
INSERT INTO users(id,uuid,name,email,password_hash,role,status) VALUES(1,'u1','Test','test@example.test','unused','nutritionist','active');
INSERT INTO clinic_members(clinic_id,user_id,role,status) VALUES(1,1,'nutritionist','active');
INSERT INTO professionals(id,user_id,crn,crn_region) VALUES(1,1,'TEST','TEST');
INSERT INTO consent_terms(id,version,title,purpose_code,term_text,is_active) VALUES(1,'1','Test','clinical_care','Test',1);");
TenantContext::set(1,['id'=>1,'role'=>'nutritionist']);$r=new PatientRepository($db);
function check($v,$message){if(!$v)throw new RuntimeException($message);}
$d=['name'=>'José','email'=>'jose@example.test','phone'=>'(11) 99999-0000','birth_date'=>'1990-10-09'];$p=$r->create($d);$id=$p['id'];
try{$r->create($d);throw new RuntimeException('Duplicate accepted');}catch(DuplicatePatientException $e){check($e->patientId===$id,'Duplicate id');}
try{$r->create(['name'=>'Other','phone'=>'11999990000']);throw new RuntimeException('Normalized phone duplicate accepted');}catch(DuplicatePatientException $e){}
$r->create(['name'=>'José','email'=>'other@example.test']); // homonyms allowed
TenantContext::set(2,['id'=>1,'role'=>'nutritionist']);$foreign=$r->create($d);check(!$r->update($id,[]),'Foreign update');check(!$r->setStatus($id,'inactive'),'Foreign status');TenantContext::set(1,['id'=>1,'role'=>'nutritionist']);
$db->exec("UPDATE patients SET updated_at='2000-01-01' WHERE id=$id");
$data=['name'=>'José atualizado','email'=>$d['email'],'phone'=>$d['phone'],'birth_date'=>$d['birth_date'],'gender'=>null,'notes'=>null];$r->update($id,$data);
check($r->findById($id)['updated_at']!=='2000-01-01','Timestamp unchanged');
check((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='PATIENT_UPDATED' AND user_id=1")->fetchColumn()===1,'Update audit');
$db->exec("INSERT INTO personal_access_tokens(tokenable_type,tokenable_id,name,token,clinic_id) VALUES('patient',$id,'test','synthetic-token',1);
INSERT INTO patient_app_invitations(clinic_id,patient_id,created_by,token_hash,expires_at) VALUES(1,$id,1,'synthetic-invite','2099-01-01');
INSERT INTO appointments(clinic_id,patient_id,professional_id,scheduled_at) VALUES(1,$id,1,'2026-10-09 09:00');
INSERT INTO checkins(clinic_id,patient_id,meal_adherence_pct,checkin_date) VALUES(1,$id,80,'2026-10-08');
INSERT INTO receivables(clinic_id,patient_id,description,amount_cents,due_date) VALUES(1,$id,'Test fee',10000,'2026-10-10');
INSERT INTO payments(clinic_id,receivable_id,amount_cents,payment_method,paid_at) VALUES(1,1,5000,'pix','2026-10-09');
INSERT INTO appointments(clinic_id,patient_id,professional_id,scheduled_at) VALUES(2,{$foreign['id']},1,'2026-10-09 10:00');");
$r->inactivate($id);check((int)$db->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn()===0,'Token not revoked');check($db->query('SELECT revoked_at FROM patient_app_invitations')->fetchColumn()!==null,'Invite not revoked');
check((int)$db->query('SELECT COUNT(*) FROM appointments')->fetchColumn()===2,'History erased');$r->setStatus($id,'active');
$profile=$r->getProfile360($id);check(count($profile['appointments'])===1 && count($profile['checkins'])===1 && $profile['receivables'][0]['paid_cents']===5000,'Profile aggregation');check(count($profile['timeline'])===3,'Timeline');check($r->getProfile360($foreign['id'])===null,'Foreign profile');
$c=new ConsentManagementService($db);$c->recordConsent($id,'clinical_care',1,'professional_attestation');check($c->revokeConsent($id,'clinical_care','Requested by titular'),'Consent not revoked');check(!$c->hasActiveConsent($id,'clinical_care'),'Active consent remains');check(count($c->getConsentAuditTrail($id))===1,'History erased');
$c->recordConsent($id,'clinical_care',1,'professional_attestation');$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_logs WHEN NEW.action='CONSENT_REVOKED' BEGIN SELECT RAISE(ABORT,'test'); END");try{$c->revokeConsent($id,'clinical_care','test');throw new RuntimeException('Audit failure ignored');}catch(PDOException $e){}check($c->hasActiveConsent($id,'clinical_care'),'Withdrawal not atomic');$db->exec('DROP TRIGGER fail_audit');
$db->exec("CREATE TRIGGER fail_patient_audit BEFORE INSERT ON audit_logs WHEN NEW.action='PATIENT_CREATED' BEGIN SELECT RAISE(ABORT,'test'); END");$before=(int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn();try{$r->create(['name'=>'Must roll back']);throw new RuntimeException('Audit failure ignored');}catch(PDOException $e){}check((int)$db->query('SELECT COUNT(*) FROM patients')->fetchColumn()===$before,'Unaudited creation persisted');$db->exec('DROP TRIGGER fail_patient_audit');
$db->exec("UPDATE patients SET password_hash='secret',access_pin='123',otp_code='otp' WHERE id=$id");$export=(new LgpdService($db))->exportPatientData($id);foreach (['password_hash','otp_code','access_pin'] as $secret) check(!array_key_exists($secret,$export['patient']),'Credential exported: '.$secret);check(count($export['appointments'])===1,'Export tenant scope');
$req=(new DataSubjectRequestService($db))->openRequest($id,'deletion');check($req['status']==='pending' && $r->findById($id)!==null,'Deletion bypassed review');
$db->beginTransaction();$stmt=$db->prepare("INSERT INTO patients(clinic_id,uuid,name,status) VALUES(1,?,?,?)");for($i=0;$i<5000;$i++)$stmt->execute(['volume-'.$i,'Volume '.sprintf('%05d',$i),$i%2?'active':'inactive']);$db->commit();
$start=microtime(true);$page=$r->paginate('Volume','inactive',2);check(count($page['patients'])===25 && $page['total']===2500 && $page['page']===2,'Pagination/status/volume');check($r->paginate('jose')['total']===2,'Accent search');check($r->paginate('11 99999-0000')['total']===1,'Phone search');check($r->paginate('%')['total']===0,'Wildcard escaped');check(count($r->birthdays('2026-10-09'))===1,'Birthday month scope');
echo 'PASS NI2-5: audit rollback, duplicates/tenant/homonyms, status/revocation/history, consent atomicity, privacy export/request, profile/timeline, pagination with 5000 patients ('.round((microtime(true)-$start)*1000).'ms).'.PHP_EOL;
