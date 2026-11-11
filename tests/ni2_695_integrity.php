<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ux_workflow_fixture.php';ob_end_clean();
use ClyoNutri\Domain\Appointment\{ConsultationDraftService,AppointmentService};
use ClyoNutri\Domain\Telehealth\TeleconsultationDraftService;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Core\Auth\TenantContext;
$checks=[];
function iCheck(string $name,bool $ok):void{global $checks;$checks[]=['name'=>$name,'pass'=>$ok];if(!$ok)throw new RuntimeException($name);}
function iDenied(string $name,callable $f):void{try{$f();}catch(\ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationStateException|DomainException|InvalidArgumentException|\ClyoNutri\Core\Security\TenantSecurityException $e){iCheck($name,true);return;}iCheck($name,false);}
TenantContext::set($c1,['id'=>$users[0],'role'=>'nutritionist']);
$s=new ConsultationDraftService($db);
iCheck('consultation starts without draft',$s->get($f['appointment'],$users[0],$c1)['version']===0);
$r=$s->save($f['appointment'],$users[0],$c1,0,['evolution_summary'=>'TESTE recuperação','weight_kg'=>'70']);
iCheck('consultation confirms persisted revision',$r['status']==='saved'&&$r['version']===1);
iCheck('new service instance resumes persisted draft',(new ConsultationDraftService($db))->get($f['appointment'],$users[0],$c1)['evolution_summary']==='TESTE recuperação');
$r=$s->save($f['appointment'],$users[0],$c1,0,['evolution_summary'=>'TESTE aba antiga']);
iCheck('stale tab insert conflicts',$r['status']==='conflict'&&$r['server']['evolution_summary']==='TESTE recuperação');
$r=$s->save($f['appointment'],$users[0],$c1,1,['evolution_summary'=>'TESTE segunda versão']);
iCheck('current tab increments revision',$r['version']===2);
$r=$s->save($f['appointment'],$users[0],$c1,1,['evolution_summary'=>'TESTE tentativa antiga']);
iCheck('stale tab update preserves server',$r['status']==='conflict'&&$r['server']['evolution_summary']==='TESTE segunda versão');
iCheck('another professional has independent consultation draft',$s->get($f['appointment'],$users[1],$c1)['version']===0);
iDenied('other clinic cannot read consultation',fn()=>$s->get($f['appointment'],$users[2],$c2));
putenv('MIGRATION_WRITE_LOCK=1');
try{$s->save($f['appointment'],$users[0],$c1,2,['evolution_summary'=>'TESTE failed']);iCheck('maintenance failure thrown',false);}
catch(PDOException $e){iCheck('maintenance failure thrown',true);}
finally{putenv('MIGRATION_WRITE_LOCK');}
iCheck('server failure preserves confirmed content',$s->get($f['appointment'],$users[0],$c1)['evolution_summary']==='TESTE segunda versão');
iDenied('oversize input refused',fn()=>$s->save($f['appointment'],$users[0],$c1,2,['evolution_summary'=>str_repeat('a',60001)]));
iCheck('4xx validation preserves revision',$s->get($f['appointment'],$users[0],$c1)['version']===2);
$service=new AppointmentService($db);
iDenied('invalid clinical measurements do not conclude',fn()=>$service->completeConsultation($f['appointment'],'TESTE clínica',['weight_kg'=>999],$users[0],$professionals[0]));
iCheck('invalid conclusion leaves appointment open',$db->query('SELECT status FROM appointments WHERE id='.(int)$f['appointment'])->fetchColumn()==='in_progress');
$done=$service->completeConsultation($f['appointment'],'TESTE evolução concluída',['weight_kg'=>70,'waist_cm'=>80],$users[0],$professionals[0]);
iCheck('explicit completion creates clinical record',$done['status']==='completed'&&!empty($done['record_hash']));
iDenied('concluded record is not overwritten by draft',fn()=>$s->save($f['appointment'],$users[0],$c1,2,['evolution_summary'=>'TESTE sobrescrever']));
iDenied('double completion refused',fn()=>$service->completeConsultation($f['appointment'],'TESTE repetição',[],$users[0],$professionals[0]));
$clinical=new ClinicalRecordService($db);
$old=$db->query('SELECT content_json FROM clinical_records WHERE id='.(int)$done['clinical_record_id'])->fetchColumn();
$revision=$clinical->reviseRecord($done['clinical_record_id'],$p1,$professionals[0],'TESTE justificativa clínica para retificação',['notes'=>'TESTE texto retificado']);
iCheck('retification appends a distinct record',$revision['id']!==$done['clinical_record_id']);
iCheck('original clinical content retained',$db->query('SELECT content_json FROM clinical_records WHERE id='.(int)$done['clinical_record_id'])->fetchColumn()===$old);
iCheck('clinical hash chain verifies after retification',$clinical->verifyIntegrity($p1)['is_valid']);
$appt=ins('appointments',['clinic_id'=>$c1,'patient_id'=>$p1,'professional_id'=>$professionals[0],'scheduled_at'=>date('Y-m-d H:i:s',strtotime('+1 day')),'status'=>'scheduled']);
$tid=ins('teleconsultation_sessions',['clinic_id'=>$c1,'appointment_id'=>$appt,'patient_id'=>$p1,'professional_id'=>$professionals[0],'patient_token_hash'=>hash('sha256',random_bytes(32)),'status'=>'scheduled']);
$t=new TeleconsultationDraftService($db);
iCheck('teleconsultation starts without draft',$t->get($tid,$professionals[0],$c1)['version']===0);
$r=$t->save($tid,$professionals[0],$c1,0,['title'=>'TESTE','notes'=>'TESTE teleconsulta','goals'=>'TESTE meta']);
iCheck('teleconsultation persisted version',$r['status']==='saved'&&$r['version']===1);
iCheck('teleconsultation resumes across service lifetime',(new TeleconsultationDraftService($db))->get($tid,$professionals[0],$c1)['notes']==='TESTE teleconsulta');
$r=$t->save($tid,$professionals[0],$c1,0,['notes'=>'TESTE conflito']);
iCheck('teleconsultation stale tab conflict',$r['status']==='conflict'&&$r['server']['notes']==='TESTE teleconsulta');
iDenied('other professional cannot read teleconsultation',fn()=>$t->get($tid,$professionals[1],$c1));
iDenied('other clinic cannot read teleconsultation',fn()=>$t->get($tid,$professionals[2],$c2));
$db->prepare("UPDATE teleconsultation_sessions SET status='cancelled' WHERE id=?")->execute([$tid]);
iDenied('cancelled teleconsultation refuses save',fn()=>$t->save($tid,$professionals[0],$c1,1,['notes'=>'TESTE blocked']));
iCheck('rejected teleconsultation write preserves content',$db->query('SELECT notes FROM teleconsultation_note_drafts WHERE session_id='.$tid)->fetchColumn()==='TESTE teleconsulta');
echo json_encode(['suite'=>'NI2-697 additional integrity','driver'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'synthetic'=>true,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
