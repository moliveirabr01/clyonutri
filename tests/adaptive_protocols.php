<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\{Migrator,Connection};
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\ClinicalProtocol\{ProtocolCatalog,ProtocolWorkspace,ProtocolDefinition,ProtocolEngine,ProtocolConflict,ProtocolSaveConflict,PatientConditionService,ClinicProtocolEditor,AdaptivePreConsultation,TransversalSummary,TermDetector};
use ClyoNutri\Domain\PreConsultation\{PreConsultationService,PreConsultationException};

$dsn = getenv('ADAPTIVE_TEST_DSN') ?: 'sqlite::memory:';
if (str_starts_with($dsn,'mysql:') && !preg_match('/dbname=[a-z0-9_]*test[a-z0-9_]*(;|$)/i',$dsn)) throw new RuntimeException('Use apenas um banco MySQL de testes.');
$pdoClass = str_starts_with($dsn, 'mysql:') ? \ClyoNutri\Core\Database\MySqlCompatPdo::class : PDO::class;
$db = new $pdoClass($dsn, getenv('ADAPTIVE_TEST_USER') ?: null, getenv('ADAPTIVE_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
Connection::setInstance($db);
(new Migrator($db, __DIR__.'/../database/migrations'))->run();
require_once __DIR__.'/../database/migrations/2026_11_03_000079_extend_adaptive_consultation.php';
(new ExtendAdaptiveConsultation())->up($db); // idempotence
$results=[];
function check(string $name, bool $ok): void {global $results; $results[]=['test'=>$name,'pass'=>$ok]; if (!$ok) throw new RuntimeException($name);}
function denied(string $name, callable $fn): void {try{$fn();}catch(DomainException|InvalidArgumentException|PreConsultationException|\ClyoNutri\Core\Security\TenantSecurityException $e){check($name,true);return;}check($name,false);}
function ins(string $table,array $data): int {global $db;$keys=array_keys($data);$db->prepare('INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($data));return (int)$db->lastInsertId();}
$run=bin2hex(random_bytes(5));
$c1=ins('clinics',['uuid'=>$run.'c1','name'=>'TESTE Clínica A']);$c2=ins('clinics',['uuid'=>$run.'c2','name'=>'TESTE Clínica B']);
$users=[];$professionals=[];
for($i=0;$i<3;$i++){
 $u=ins('users',['uuid'=>$run.'u'.$i,'name'=>'TESTE Profissional '.$i,'email'=>$run.$i.'@example.invalid','password_hash'=>password_hash('Synthetic-local-651!',PASSWORD_DEFAULT),'role'=>'nutritionist','is_verified'=>1]);
 $users[]=$u;$professionals[]=ins('professionals',['user_id'=>$u,'crn'=>'CRN-3 12345','crn_region'=>'3']);
 ins('clinic_members',['clinic_id'=>$i===2?$c2:$c1,'user_id'=>$u,'role'=>$i===1?'nutritionist':'owner']);
}
$p1=ins('patients',['clinic_id'=>$c1,'uuid'=>$run.'p1','name'=>'TESTE Paciente A','birth_date'=>'1990-01-01']);
$p2=ins('patients',['clinic_id'=>$c2,'uuid'=>$run.'p2','name'=>'TESTE Paciente B','birth_date'=>'1991-01-01']);
TenantContext::set($c1,['id'=>$users[0],'role'=>'nutritionist']);
$db->exec("DELETE FROM platform_settings WHERE setting_key='feature_flag_clinical_protocols'");ins('platform_settings',['setting_key'=>'feature_flag_clinical_protocols','setting_value'=>'1']);PlatformSettings::clearCache();
ins('consent_terms',['version'=>'TESTE-'.$run,'title'=>'Privacidade TESTE','purpose_code'=>'pre_consultation','term_text'=>'TESTE: uso das respostas apenas neste ambiente isolado.']);
$catalog=new ProtocolCatalog($db);$ws=new ProtocolWorkspace($db);$pcs=new PatientConditionService($db);$ed=new ClinicProtocolEditor($db);
$codes=[];
foreach(['diabetes','hipertensao','crohn','celiaca','renal'] as $name){
 $code='test_'.$run.'_'.$name;$codes[$name]=$code;
 $pid=$catalog->register($code,'TESTE '.$name);
 $def=ProtocolDefinition::validate(['title'=>'TESTE '.$name,'suggestion_terms'=>[$name], 'questions'=>[
  ['key'=>'medicamentos','shared_key'=>'base.medicamentos','text'=>'Medicamentos relatados','type'=>'text','required'=>true],
  ['key'=>'sim_nao','text'=>'Deseja relatar mais informações?','type'=>'yesno','options'=>['sim'=>'Sim','nao'=>'Não'],'required'=>true],
  ['key'=>'detalhe','text'=>'Detalhe opcional','type'=>'textarea','condition'=>['question'=>'sim_nao','equals'=>'sim']],
  ['key'=>'profissional','text'=>'Revisão exclusiva profissional','type'=>'text','audience'=>'professional']]]);
 $v=$catalog->createDraft($pid,$def,'Referência sintética de engenharia; NÃO homologada clinicamente.',$users[0]);
 denied('publicação sem revisão '.$name,fn()=>$catalog->approve($v,$users[1],'TESTE Revisor','CRN-3 12345',date('Y-m-d',strtotime('+1 year'))));
 $catalog->submit($v,$users[0]);$catalog->approve($v,$users[1],'TESTE Revisor','CRN-3 12345',date('Y-m-d',strtotime('+1 year')));
 $ws->setClinicEnabled($c1,$code,true,$users[0]);
 $pcs->record($c1,$p1,$code,'reported','self_reported',$users[0]);
}
$q=$ws->questionnaire($c1,$p1);
check('mais de dois diagnósticos: cinco módulos',count($q['modules'])===5);
check('pergunta comum deduplicada',count(array_filter($q['composed']['items'],fn($it)=>$it['answer_key']==='base.medicamentos'))===1);
foreach([['diabetes','hipertensao'],['crohn','celiaca'],['renal','diabetes']] as $pair){
 $mods=array_values(array_filter($q['modules'],fn($m)=>in_array($m['code'],[$codes[$pair[0]],$codes[$pair[1]]],true)));
 $composed=ProtocolEngine::compose($mods,[]);
 check('combinação '.implode('+',$pair),count($mods)===2 && count($composed['items'])===7);
}
$answers=['base.medicamentos'=>'Relato sintético v1'];foreach($codes as $code)$answers[$code.'.sim_nao']='nao';
$ws->save($c1,$p1,$answers,'professional',true,$users[0],null,0);
$rev=$ws->revision($c1,$p1);
$ws->save($c1,$p1,['base.medicamentos'=>'RASCUNHO v2'],'professional',false,$users[0],null,$rev);
denied('conflito explícito de gravação',fn()=>$ws->save($c1,$p1,$answers,'professional',true,$users[0],null,$rev));
$an1=ins('anamneses',['clinic_id'=>$c1,'patient_id'=>$p1,'professional_id'=>$professionals[0],'version'=>1,'is_draft'=>0,'medications_details'=>'VIGENTE','allergies'=>'Amendoim','allergy_assessment'=>'recorded']);
ins('anamneses',['clinic_id'=>$c1,'patient_id'=>$p1,'professional_id'=>$professionals[0],'version'=>2,'is_draft'=>1,'medications_details'=>'RASCUNHO']);
$s=(new TransversalSummary($db))->read($c1,$p1);
check('resumo mantém anamnese v1 concluída',(int)$s['base']['id']===$an1 && $s['base']['medications_details']==='VIGENTE');
check('resumo mantém respostas concluídas',!str_contains(json_encode($s['modules']),'RASCUNHO'));
denied('resumo não cruza pacientes de clínicas',fn()=>(new TransversalSummary($db))->read($c1,$p2));
$api=(new \ClyoNutri\Domain\Anamnesis\AnamnesisService($db))->getClinicalSummaryForPrescription($p1);
check('API resumo preserva origem concluída',(int)$api['source']['anamnesis_id']===$an1 && (int)$api['adaptive_summary']['base']['id']===$an1);
$food=ins('foods',['name'=>'TESTE Alimento com amendoim','energy_kcal'=>0,'protein_g'=>0,'carbohydrate_g'=>0,'lipid_g'=>0,'fiber_g'=>0,'is_custom'=>1,'clinic_id'=>$c1,'allergens'=>'amendoim']);
$safety=(new \ClyoNutri\Domain\Clinical\AllergenSafetyValidator($db))->validateFoodsForPatient($p1,[$food]);
check('regressão da trava de alergia específica',$safety['has_conflict'] && $safety['requires_confirmation']);
$mealPlan=(new \ClyoNutri\Domain\MealPlan\MealPlanService($db))->createPlan($p1,$professionals[0],'TESTE Plano', [['name'=>'TESTE Refeição','time_of_day'=>'12:00','items'=>[['food_id'=>$food,'amount'=>10,'measure_unit'=>'g']]]]);
$shopping=(new \ClyoNutri\Domain\ShoppingList\ShoppingListService($db))->generateForPlan($mealPlan['id'],7,['require_published'=>false]);
check('lista de compras preserva alerta específico',$shopping['allergens_and_restrictions']['has_conflicts']);
$appointment=ins('appointments',['clinic_id'=>$c1,'patient_id'=>$p1,'professional_id'=>$professionals[0],'scheduled_at'=>date('Y-m-d H:i:s'),'status'=>'in_progress']);
$chain=ProtocolDefinition::validate(['title'=>'TESTE dependências','questions'=>[
 ['key'=>'filtro','type'=>'yesno','text'=>'Filtro','options'=>['sim'=>'Sim','nao'=>'Não']],
 ['key'=>'meio','type'=>'text','text'=>'Meio','condition'=>['question'=>'filtro','equals'=>'sim']],
 ['key'=>'fim','type'=>'text','text'=>'Fim','condition'=>['question'=>'meio','answered'=>true]]]]);
$chainOut=ProtocolEngine::compose([['code'=>'chain','name'=>'TESTE','version'=>1,'version_id'=>0,'definition'=>$chain]],['chain.filtro'=>'nao','chain.meio'=>'valor antigo']);
check('dependências ocultas não ativam descendentes',!$chainOut['items'][2]['visible']);
foreach(['nega diabetes','mãe com diabetes'] as $text)check('texto não confirma: '.$text,TermDetector::detect($text,['diabetes'])['match']===null);
foreach(['suspeita de diabetes','diabetes?'] as $text)check('dúvida permanece incerta: '.$text,TermDetector::detect($text,['diabetes'])['match']['certainty']==='uncertain');
$private=['title'=>'TESTE Privado','scope'=>'Questionário sintético','editor'=>'TESTE responsável','questions'=>[['key'=>'rotina','text'=>'Relato opcional','type'=>'text']]];
$pv=$ed->save($c1,$users[0],null,$private,'Referência TESTE');
denied('autor não aprova própria versão',function()use($ed,$c1,$users,$pv){$ed->submit($c1,$users[0],$pv);$ed->approve($c1,$users[0],$pv,date('Y-m-d',strtotime('+1 year')));});
$ed->approve($c1,$users[1],$pv,date('Y-m-d',strtotime('+1 year')));
$old=$catalog->version($pv)['content_sha256'];$private['questions'][0]['text']='Nova versão';$pv2=$ed->save($c1,$users[0],$pv,$private,'Referência TESTE');
check('edição publicada cria v2 e mantém hash v1',$pv2!==$pv && $catalog->version($pv)['content_sha256']===$old);
check('protocolo privado ausente da clínica B',!isset($catalog->approvedByCode($c2)[$catalog->version($pv)['code']]));
denied('clínica B não lê versão privada A',fn()=>$ed->version($c2,$users[2],$pv));
denied('clínica B não altera versão privada A',fn()=>$ed->save($c2,$users[2],$pv2,$private,'TESTE'));
denied('compartilhamento exige licença',fn()=>$ed->share($c1,$users[0],$pv,$c2));
$plan=ins('saas_plans',['code'=>'test_'.$run,'name'=>'TESTE licença','price_cents'=>0,'features_json'=>'{"clinical_protocol_sharing":true}']);
ins('clinic_subscriptions',['clinic_id'=>$c1,'plan_id'=>$plan,'price_cents'=>0,'status'=>'active','current_period_end'=>date('Y-m-d H:i:s',strtotime('+1 month'))]);
$ed->share($c1,$users[0],$pv,$c2);$ed->share($c1,$users[0],$pv,$c2);
$received=$ed->received($c2,$users[2]);check('compartilhamento idempotente',count($received)===1);
$copy=$ed->importShared($c2,$users[2],$received[0]['id']);check('cópia compartilhada nasce rascunho privado',$ed->version($c2,$users[2],$copy)['status']==='draft');
denied('não importa compartilhamento de outro destinatário',fn()=>$ed->importShared($c1,$users[0],$received[0]['id']));
$draftRow=$ed->version($c1,$users[0],$pv2);$ed->save($c1,$users[0],$pv2,$private,'Atualização de referência',ClinicProtocolEditor::revision($draftRow));
$draftRow=$ed->version($c1,$users[0],$pv2);
$changed=$private;$changed['summary']='Outra edição';$ed->save($c1,$users[0],$pv2,$changed,'TESTE',ClinicProtocolEditor::revision($draftRow));
denied('conflito do editor não sobrescreve rascunho',fn()=>$ed->save($c1,$users[0],$pv2,$private,'TESTE',ClinicProtocolEditor::revision($draftRow)));
$bad=$private;$bad['scope']='';denied('escopo obrigatório',fn()=>ClinicProtocolEditor::definition($bad));
$bad=$private;$bad['questions'][0]['text']='<script>alert(1)</script>';denied('HTML perigoso recusado',fn()=>ClinicProtocolEditor::definition($bad));
$bad=$private;$bad['execute']='php';denied('código arbitrário recusado',fn()=>ClinicProtocolEditor::definition($bad));
$bad=$private;$bad['questions'][0]['condition']=['question'=>'rotina','equals'=>'sim'];denied('dependência circular recusada',fn()=>ClinicProtocolEditor::definition($bad));
$pre=new PreConsultationService($db);$proposal=$pre->createProposal($p1,$professionals[0],[],null,true,$users[0]);
check('snapshot só inclui perguntas para paciente',!str_contains(json_encode($proposal['questions']),'Revisão exclusiva profissional'));
$before=json_encode($proposal['questions']);$pre->updateQuestions((int)$proposal['id'],['has_allergy'],[]);$proposal=$pre->get((int)$proposal['id']);
check('editar base preserva módulos versionados',count(array_filter($proposal['questions'],fn($q)=>!empty($q['module_versions'])))>0);
$sent=$pre->send((int)$proposal['id']);$row=$pre->redeem($sent['token']);
denied('convite de uso único',fn()=>$pre->redeem($sent['token']));
denied('token do convite não é credencial de retomada',fn()=>$pre->forPatient($row['id'],$row['token_hash']));
$row=$pre->forPatient($row['id'],$row['session_hash']);
check('retomada por credencial da sessão',(int)$row['patient_id']===$p1);
denied('anti-IDOR por credencial',fn()=>$pre->forPatient($row['id']+1,$row['access_hash']));
check('data de nascimento correta',$pre->verifyBirthDate($row,'1990-01-01'));
denied('rascunho sem aceite de privacidade',fn()=>$pre->saveDraft($row,[],false));
$patientAnswers=['has_allergy'=>'nao','adaptive_declared_conditions'=>'crohn'];foreach($codes as $code)$patientAnswers['cp_'.$code.'__sim_nao']='nao';$patientAnswers['cp_base__medicamentos']='TESTE relato paciente';
$pre->saveDraft($row,$patientAnswers,true,0);
denied('conflito de pré-consulta mantém respostas',fn()=>$pre->saveDraft($row,['has_allergy'=>'sim'],true,0));
$row=$pre->forPatient($row['id'],$row['access_hash']);$res=$pre->submit($row,$patientAnswers,true,'127.0.0.1','TESTE',1);
check('envio final sem erros',$res['errors']===[]);
check('pré-consulta não modifica avaliação vigente',(new TransversalSummary($db))->read($c1,$p1)['base']['id']===$an1);
check('paciente não grava respostas clínicas concluídas',(int)$db->query("SELECT COUNT(*) FROM protocol_responses WHERE provenance='patient' AND patient_id=$p1")->fetchColumn()===0);
denied('envio final imutável',fn()=>$pre->saveDraft($row,$patientAnswers,true));
$submitted=$pre->get($row['id']);check('segredos não expostos no questionário',!isset($submitted['token_hash'],$submitted['access_hash']));
$p3=ins('patients',['clinic_id'=>$c1,'uuid'=>$run.'p3','name'=>'TESTE Relato pendente','birth_date'=>'1992-01-01']);
$proposal3=$pre->createProposal($p3,$professionals[0],[],null,true,$users[0]);$pre->updateQuestions($proposal3['id'],['has_allergy'],[]);$sent3=$pre->send($proposal3['id']);$redeemed3=$pre->redeem($sent3['token']);$row3=$pre->forPatient($redeemed3['id'],$redeemed3['session_hash']);
$pre->submit($row3,['has_allergy'=>'nao','adaptive_declared_conditions'=>'crohn'],true,'127.0.0.1','TESTE',0);
$suggestions=(new AdaptivePreConsultation($db))->suggestSubmitted($c1,$p3,$pre->get($row3['id']),$users[0]);
$pending=array_values(array_filter($pcs->list($c1,$p3),fn($r)=>$r['protocol_code']===$codes['crohn']));
check('relato chega pendente com proveniência paciente',count($pending)===1 && $pending[0]['provenance']==='patient' && $pending[0]['confirmed_by_user_id']===null && str_contains($pending[0]['evidence_text'],'Pré-consulta #'.$row3['id']));
check('relato não ativa módulo antes da revisão',$ws->activeModules($c1,$p3)===[]);
$pcs->confirm($c1,(int)$pending[0]['id'],'reported','patient',$users[0],'Revisão TESTE');
check('aceite profissional mantém condição relatada',count(array_filter($pcs->list($c1,$p3),fn($r)=>(int)$r['id']===(int)$pending[0]['id'] && $r['status']==='reported'))===1 && in_array($codes['crohn'],$pcs->activatingCodes($c1,$p3),true));
// Expiration and reissue invalidate the previous browser credential.
$other=$pre->createProposal($p1,$professionals[0],[],null,true,$users[0]);$sent2=$pre->send($other['id']);$r2=$pre->redeem($sent2['token']);$pre->send($other['id']);
denied('reenvio revoga sessão anterior',fn()=>$pre->forPatient($r2['id'],$r2['session_hash']));
$db->prepare("UPDATE preconsultation_questionnaires SET token_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$other['id']]);
denied('link expirado não abre',fn()=>$pre->redeem($sent2['token']));
$db->exec("UPDATE platform_settings SET setting_value='0' WHERE setting_key='feature_flag_clinical_protocols'");PlatformSettings::clearCache();
check('flag desliga novos módulos',$ws->activeModules($c1,$p1)===[]);
check('flag preserva respostas',(int)$db->query('SELECT COUNT(*) FROM protocol_responses WHERE patient_id=' . $p1)->fetchColumn()===10);
$db->exec("UPDATE platform_settings SET setting_value='1' WHERE setting_key='feature_flag_clinical_protocols'");PlatformSettings::clearCache();
$report=['build'=>'v54.17','driver'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'passed'=>count($results),'failed'=>0,'clinical_approval'=>'pending','tests'=>$results];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
if(getenv('ADAPTIVE_FIXTURE_FILE'))file_put_contents(getenv('ADAPTIVE_FIXTURE_FILE'),json_encode(['clinic'=>$c1,'clinic_b'=>$c2,'user'=>$users[0],'reviewer'=>$users[1],'user_b'=>$users[2],'patient'=>$p1,'patient_b'=>$p2,'professional'=>$professionals[0],'email'=>$run.'0@example.invalid','email_b'=>$run.'2@example.invalid','plan'=>$mealPlan['id'],'appointment'=>$appointment,'private_version'=>$pv,'draft_version'=>$pv2]));
