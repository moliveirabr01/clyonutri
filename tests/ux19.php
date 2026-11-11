<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ux_workflow_fixture.php';ob_end_clean();
use ClyoNutri\Domain\Appointment\CareProgress;
use ClyoNutri\Domain\UX\Scorecard;
use ClyoNutri\Core\Auth\TenantContext;
$checks=[];
function verify19(string $label,bool $value):void{global $checks;$checks[]=['test'=>$label,'pass'=>$value];if(!$value)throw new RuntimeException($label);}
function deny19(string $label,callable $op):void{try{$op();}catch(DomainException|InvalidArgumentException|\ClyoNutri\Domain\Clinical\WorkflowConflict $e){verify19($label,true);return;}verify19($label,false);}
$db->prepare('UPDATE anamneses SET is_draft=0 WHERE clinic_id=? AND patient_id=?')->execute([$c1,$p1]); // Synthetic completed-anamnesis scenario.
$svc=new CareProgress($db);$appointment=$f['appointment'];$state=$svc->snapshot($c1,$p1,$appointment);
verify19('nenhum percentual sem configuração de aplicabilidade',!$state['quantitative']);
verify19('registro do mesmo dia não conclui etapa automaticamente',$state['done']===0&&$state['steps'][0]['state']!=='done');
verify19('plano em rascunho tem próxima ação exata',$state['next']['key']==='plano'&&str_starts_with($state['next']['url'],'/planos/'));
$initial=$state['revision'];$key=bin2hex(random_bytes(16));$svc->decide($c1,$p1,$appointment,'anamnese','required','','',$initial,$key);
$svc->decide($c1,$p1,$appointment,'anamnese','required','','',$initial,$key);
verify19('repetição da mesma decisão não duplica evento',(int)$db->query('SELECT COUNT(*) FROM care_progress_events WHERE clinic_id='.(int)$c1.' AND appointment_id='.(int)$appointment)->fetchColumn()===1);
deny19('chave repetida com decisão diferente é recusada',fn()=>$svc->decide($c1,$p1,$appointment,'anamnese','optional','','',$initial,$key));
deny19('outra aba não sobrescreve aplicabilidade',fn()=>$svc->decide($c1,$p1,$appointment,'plano','required','','',$initial,bin2hex(random_bytes(16))));
foreach(['avaliacao','plano','revisao'] as $step){$s=$svc->snapshot($c1,$p1,$appointment);$svc->decide($c1,$p1,$appointment,$step,'required','','',$s['revision'],bin2hex(random_bytes(16)));}
$s=$svc->snapshot($c1,$p1,$appointment);verify19('denominador definido por quatro etapas necessárias',$s['quantitative']&&$s['total']===4&&$s['done']===0);
deny19('dispensa exige motivo válido',fn()=>$svc->decide($c1,$p1,$appointment,'avaliacao','waived','texto arbitrário','',$s['revision'],bin2hex(random_bytes(16))));
$svc->decide($c1,$p1,$appointment,'avaliacao','waived','not_indicated','',$s['revision'],bin2hex(random_bytes(16)));
$s=$svc->snapshot($c1,$p1,$appointment);verify19('dispensa persistida recalcula denominador',$s['total']===3&&$s['steps'][1]['state']==='not_applicable');
$svc->decide($c1,$p1,$appointment,'revisao','optional','','',$s['revision'],bin2hex(random_bytes(16)));$s=$svc->snapshot($c1,$p1,$appointment);verify19('etapa opcional não infla o denominador',$s['total']===2);
$st=$s['steps'][2];deny19('rascunho persistido nunca confirma plano concluído',fn()=>$svc->decide($c1,$p1,$appointment,'plano','confirm','',$st['evidence'],$s['revision'],bin2hex(random_bytes(16))));
$st=$s['steps'][0];if($st['can_confirm']){$svc->decide($c1,$p1,$appointment,'anamnese','confirm','',$st['evidence'],$s['revision'],bin2hex(random_bytes(16)));verify19('registro concluído exige vínculo explícito',$svc->snapshot($c1,$p1,$appointment)['steps'][0]['state']==='done');}
$s=$svc->snapshot($c1,$p1,$appointment);verify19('refresh reconstrói decisões persistidas',(new CareProgress($db))->snapshot($c1,$p1,$appointment)===$s);
TenantContext::set($c2,['id'=>$users[2],'role'=>'nutritionist']);deny19('outra clínica não lê atendimento',fn()=>$svc->snapshot($c2,$p1,$appointment));deny19('clinic id fornecido não ultrapassa tenant',fn()=>$svc->snapshot($c1,$p1,$appointment));
TenantContext::set($c1,['id'=>$users[0],'role'=>'nutritionist']);deny19('paciente divergente não lê metadados',fn()=>$svc->snapshot($c1,$p2,$appointment));
$report=Scorecard::evaluate([]);verify19('tarefa não executada permanece não testada',count(array_filter($report['scores'],fn($v)=>$v===null))===7&&$report['release_gate']==='NO-GO');
$input=['build'=>'synthetic','protocol_id'=>'ux19','tasks_id'=>'fixed','profiles_id'=>'three','sample'=>['beginner'=>1,'experienced'=>1,'patient'=>1],'consent_recorded'=>true,'human_sessions_complete'=>true,'accessibility_reviewed'=>true,'clinical_go'=>true,'p0_count'=>0,'p1_clinical_count'=>0];
foreach(Scorecard::LAWS as $law)$input['laws'][$law]=array_fill(0,5,['rater_a'=>4,'rater_b'=>4,'executed'=>true,'evidence'=>'synthetic assertion only']);
// v54.33: contrato NI2-688 (v54.28) exige protocolo, fichas e sessões; o caminho GO completo é coberto por tests/ni2_688.php.
verify19('entrada antiga sem evidências NI2-688 continua NO-GO',Scorecard::evaluate($input)['release_gate']==='NO-GO');
$input['laws']['Hick'][0]['rater_a']=1;verify19('uma lei abaixo de quatro bloqueia meta',!Scorecard::evaluate($input)['target_met']);$input['laws']['Hick'][0]['rater_a']=4;
$input['p0_count']=1;verify19('P0 clínico bloqueia independentemente de notas',Scorecard::evaluate($input)['release_gate']==='NO-GO');$input['p0_count']=0;
$input['human_sessions_complete']=false;verify19('automação não certifica meta humana',!Scorecard::evaluate($input)['target_met']);$input['human_sessions_complete']=true;
$input['laws']['Fitts'][0]['executed']=false;verify19('critério não executado não recebe nota',Scorecard::evaluate($input)['scores']['Fitts']===null);$input['laws']['Fitts'][0]['executed']=true;
$input['baseline']=['protocol_id'=>'other','tasks_id'=>'fixed','profiles_id'=>'three'];verify19('protocolos incompatíveis não permitem comparação',Scorecard::evaluate($input)['comparison_allowed']===false);
$policy=new \ClyoNutri\Domain\Appointment\CareWorkflowPolicy($db);$before=$policy->read($c1,'first_visit');
$defaults=['anamnese'=>'required','avaliacao'=>'optional','plano'=>'required','revisao'=>'optional'];$pk=bin2hex(random_bytes(16));$policy->save($c1,'first_visit',$defaults,$before['revision'],$pk);$policy->save($c1,'first_visit',$defaults,$before['revision'],$pk);
verify19('política da clínica por tipo persiste idempotentemente',$policy->read($c1,'first_visit')['steps']===$defaults);
verify19('outro tipo não herda política inexistente',$policy->read($c1,'return')['steps']===[]);
verify19('dispensa individual prevalece sobre padrão',$svc->snapshot($c1,$p1,$appointment)['steps'][1]['state']==='not_applicable');
deny19('política antiga conflita',fn()=>$policy->save($c1,'first_visit',$defaults,$before['revision'],bin2hex(random_bytes(16))));
TenantContext::set($c1,['id'=>$users[1],'role'=>'nutritionist']);deny19('profissional sem gestão não altera padrão',fn()=>$policy->save($c1,'return',$defaults,$policy->read($c1,'return')['revision'],bin2hex(random_bytes(16))));
TenantContext::set($c1,['id'=>$users[0],'role'=>'nutritionist']);
$header=\ClyoNutri\Application\View\PatientHeader::render($db,$p1,'plano');verify19('contexto canônico renderiza no plano',str_contains($header,'data-testid="clinical-context"'));
$foreign=\ClyoNutri\Application\View\PatientHeader::render($db,$p2,'plano',['id'=>$p2,'name'=>'foreign']);verify19('paciente fornecido não burla isolamento do cabeçalho',$foreign==='');
require_once __DIR__.'/../database/migrations/2026_11_03_000081_create_care_progress_events.php';(new CreateCareProgressEvents())->up($db);verify19('migração é idempotente',true);
echo json_encode(['build'=>'v54.19','database'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
