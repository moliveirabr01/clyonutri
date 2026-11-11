<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ni2_703_fixture.php';ob_end_clean();
use ClyoNutri\Domain\Dietary\{DietarySuggestionService,DietaryDefinition,DietaryStarterCatalog};
use ClyoNutri\Domain\ClinicalProtocol\{ProtocolDefinition,ClinicProtocolEditor,PatientConditionService,ProtocolWorkspace};
use ClyoNutri\Domain\Clinical\WorkflowConflict;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Settings\PlatformSettings;
$checks=[];
function dcheck(string $name,bool $ok):void{global $checks;$checks[]=['name'=>$name,'pass'=>$ok];if(!$ok)throw new RuntimeException($name);}
function dreject(string $name,callable $f):void{try{$f();}catch(DomainException|InvalidArgumentException|\ClyoNutri\Core\Security\TenantSecurityException $e){dcheck($name,true);return;}dcheck($name,false);}
$s=new DietarySuggestionService($db);
foreach(array_keys(DietaryStarterCatalog::all()) as $kind){$definition=ClinicProtocolEditor::definition(DietaryStarterCatalog::definition($kind));dcheck('starter validates '.$kind,!empty($definition['dietary_suggestions']));}
$definition=DietaryStarterCatalog::definition('diabetes');$definition['dietary_suggestions'][0]['source_url']='javascript:alert(1)';
dreject('unsafe source rejected',fn()=>ProtocolDefinition::validate($definition));
$definition=DietaryStarterCatalog::definition('diabetes');$definition['dietary_suggestions'][0]['guidance']='<script>x</script>';
dreject('HTML guidance rejected',fn()=>ProtocolDefinition::validate($definition));
$p=$s->preview($dietPlan);dcheck('confirmed diabetes produces a proposal',count($p['cards'])===1&&$p['cards'][0]['condition']==='diabetes');
dcheck('GET never changes plan',$p['plan']['notes']===null||$p['plan']['notes']==='');
dcheck('confirmed source and version retained',$p['cards'][0]['version_id']===$dietVersions['diabetes']&&str_starts_with($p['cards'][0]['rule']['source_url'],'https://'));
function acceptDiet(array $p,bool $confirmed=true,?string $key=null):array{global $s,$dietPlan;$card=$p['cards'][0];return $s->confirm($dietPlan,$key??bin2hex(random_bytes(16)),$p['workflow']['revision'],$p['context_sha256'],[$card['id']],[$card['id']=>'TESTE orientação final revisada pelo nutricionista'],'TESTE justificativa clínica individualizada',$confirmed);}
dreject('confirmation mandatory on server',fn()=>acceptDiet($p,false));
dreject('empty selection rejected',fn()=>$s->confirm($dietPlan,bin2hex(random_bytes(16)),$p['workflow']['revision'],$p['context_sha256'],[],[],'TESTE justificativa',true));
$key=bin2hex(random_bytes(16));$result=acceptDiet($p,true,$key);
dcheck('explicit confirmation persists one decision',$result['accepted_count']===1&&$result['confirmation_id']>0);
$notes=$db->query('SELECT notes FROM meal_plans WHERE id='.$dietPlan)->fetchColumn();
dcheck('only reviewed final text reaches notes',str_contains($notes,'TESTE orientação final')&&!str_contains($notes,$p['cards'][0]['rule']['guidance']));
$retry=acceptDiet($p,true,$key);dcheck('same receipt replays without duplication',!empty($retry['replayed'])&&$retry['confirmation_id']===$result['confirmation_id']);
dcheck('retry does not duplicate notes',$db->query('SELECT notes FROM meal_plans WHERE id='.$dietPlan)->fetchColumn()===$notes);
dreject('stale tab cannot reapply',fn()=>acceptDiet($p));
$current=$s->preview($dietPlan);$hypertension=$conditionsService->record($c1,$dietPatient,$dietCodes['hipertensao'],'reported','self_reported',$users[0]);
dreject('condition change invalidates proposal',fn()=>acceptDiet($current));
$p=$s->preview($dietPlan);dcheck('compatible comorbidities produce separate proposals',count($p['cards'])===2&&count(array_filter($p['cards'],fn($c)=>!$c['blocked']))===2);
$renal=$conditionsService->record($c1,$dietPatient,$dietCodes['renal'],'suspected','professional',$users[0]);
$p=$s->preview($dietPlan);dcheck('suspected renal never generates guidance',count($p['cards'])===2);
dcheck('suspected renal blocks other generic guidance',count(array_filter($p['cards'],fn($c)=>!$c['blocked']))===0);
dreject('blocked combination cannot be forced',fn()=>acceptDiet($p));
$conditionsService->confirm($c1,$renal,'documented','report',$users[0],'TESTE confirmado');
$p=$s->preview($dietPlan);dcheck('renal is individual assessment only',count($p['cards'])===3&&count(array_filter($p['cards'],fn($c)=>!$c['blocked']))===0);
$conditionsService->close($c1,$renal,'resolved',$users[0],'TESTE encerrada');
$p=$s->preview($dietPlan);dcheck('resolved condition stops affecting new proposals',count($p['cards'])===2&&count(array_filter($p['cards'],fn($c)=>!$c['blocked']))===2);
$workspace->setClinicEnabled($c1,$dietCodes['diabetes'],false,$users[0]);$p=$s->preview($dietPlan);dcheck('disabled module is excluded',count($p['cards'])===1);
$workspace->setClinicEnabled($c1,$dietCodes['diabetes'],true,$users[0]);
$db->prepare("UPDATE clinical_protocol_versions SET review_due_at='2000-01-01' WHERE id=?")->execute([$dietVersions['diabetes']]);
dcheck('overdue clinical review excluded',count($s->preview($dietPlan)['cards'])===1);
$db->prepare('UPDATE clinical_protocol_versions SET review_due_at=? WHERE id=?')->execute([date('Y-m-d',strtotime('+1 year')),$dietVersions['diabetes']]);
$before=$s->preview($dietPlan);
$db->prepare("UPDATE anamneses SET allergies='Amendoim',allergy_assessment='recorded' WHERE patient_id=?")->execute([$dietPatient]);
dreject('allergy change invalidates proposal',fn()=>acceptDiet($before));
$before=$s->preview($dietPlan);$db->prepare('UPDATE meal_plans SET notes=? WHERE id=?')->execute(['TESTE outra aba',$dietPlan]);
dreject('plan notes change invalidates proposal',fn()=>acceptDiet($before));
$db->prepare("UPDATE meal_plans SET status='published' WHERE id=?")->execute([$dietPlan]);
$p=$s->preview($dietPlan);dcheck('published plan proposals disabled',count(array_filter($p['cards'],fn($c)=>!$c['blocked']))===0);
dreject('published plan rejects apply',fn()=>acceptDiet($p));
$db->prepare("UPDATE meal_plans SET status='draft' WHERE id=?")->execute([$dietPlan]);
TenantContext::set($c2,['id'=>$users[2],'role'=>'nutritionist']);dreject('cross clinic read denied',fn()=>$s->preview($dietPlan));
TenantContext::set($c1,['id'=>$users[0],'role'=>'nutritionist']);
$db->prepare("UPDATE professionals SET crn='' WHERE id=?")->execute([$professionals[0]]);
dreject('actor without CRN denied',fn()=>$s->preview($dietPlan));
$db->prepare("UPDATE professionals SET crn='12345' WHERE id=?")->execute([$professionals[0]]);
$db->prepare("UPDATE platform_settings SET setting_value='0' WHERE setting_key='feature_flag_clinical_protocols'")->execute();PlatformSettings::clearCache();
dcheck('global flag disables generation',count($s->preview($dietPlan)['cards'])===0);
$db->prepare("UPDATE platform_settings SET setting_value='1' WHERE setting_key='feature_flag_clinical_protocols'")->execute();PlatformSettings::clearCache();
$celiac=$conditionsService->record($c1,$dietPatient,$dietCodes['celiaca'],'reported','self_reported',$users[0]);
$p=$s->preview($dietPlan);$cc=array_values(array_filter($p['cards'],fn($c)=>$c['condition']==='celiaca'));
dcheck('celiac restriction needs documentation',count($cc)===1&&(bool)$cc[0]['blocked']);
$conditionsService->confirm($c1,$celiac,'documented','report',$users[0],'TESTE documentada');
$p=$s->preview($dietPlan);$cc=array_values(array_filter($p['cards'],fn($c)=>$c['condition']==='celiaca'));
dcheck('documented celiac can be reviewed',!$cc[0]['blocked']);
$count=(int)$db->query('SELECT COUNT(*) FROM dietary_confirmations WHERE plan_id='.$dietPlan)->fetchColumn();
dcheck('failed operations leave confirmation count unchanged',$count===1);
$row=$db->query('SELECT * FROM dietary_confirmations WHERE plan_id='.$dietPlan)->fetch();
dcheck('decision retains author and source',(int)$row['user_id']===$users[0]&&(int)$row['professional_id']===$professionals[0]&&str_contains($row['suggestions_json'],'source_url'));

// Every reference decision is portable, with operational keys omitted.
$export=(new \ClyoNutri\Domain\Privacy\LgpdService($db))->exportPatientData($dietPatient);
dcheck('clinical confirmations exported for correct patient',count($export['dietary_confirmations'])===1);
dcheck('idempotency key omitted from export',!array_key_exists('request_key',$export['dietary_confirmations'][0]));
$p=$s->preview($dietPlan);$initialNotes=$p['plan']['notes'];$trigger='diet_fail_'.$run;
$driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
if($driver==='mysql')$db->exec("CREATE TRIGGER $trigger BEFORE INSERT ON dietary_confirmations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic persistence failure'");
else $db->exec("CREATE TRIGGER $trigger BEFORE INSERT ON dietary_confirmations BEGIN SELECT RAISE(ABORT,'Synthetic persistence failure'); END");
try{try{acceptDiet($p);dcheck('persistence fault rejected',false);}catch(PDOException $e){dcheck('persistence fault rejected',true);}}
finally{$db->exec("DROP TRIGGER $trigger");}
dcheck('failed persistence rolls notes back',$db->query('SELECT notes FROM meal_plans WHERE id='.$dietPlan)->fetchColumn()===$initialNotes);
dcheck('failed persistence creates no decision',(int)$db->query('SELECT COUNT(*) FROM dietary_confirmations WHERE plan_id='.$dietPlan)->fetchColumn()===1);
// Confirm again after known context changes, then explicitly publish a synthetic populated plan.
$p=$s->preview($dietPlan);acceptDiet($p);
$db->prepare("UPDATE anamneses SET allergies='',allergy_assessment='denied' WHERE patient_id=?")->execute([$dietPatient]);
$mp=new \ClyoNutri\Domain\MealPlan\MealPlanService($db);foreach(['08:00','12:00','18:00'] as $hour){$meal=$mp->addMeal($dietPlan,'TESTE refeição',$hour);$mp->addItem($meal,$foods[0],300,'g');}
$published=$mp->publishPlan($dietPlan,false,true);
dcheck('publication remains a separate explicit action',$published['status']==='published');
$version=$db->query('SELECT payload_json FROM meal_plan_versions WHERE meal_plan_id='.$dietPlan.' ORDER BY id DESC LIMIT 1')->fetchColumn();
dcheck('reviewed guidance survives immutable publication snapshot',str_contains($version,'TESTE orientação final revisada'));
dcheck('publication does not lose decision audit',(int)$db->query('SELECT COUNT(*) FROM dietary_confirmations WHERE plan_id='.$dietPlan)->fetchColumn()===2);

echo json_encode(['suite'=>'NI2-703','synthetic'=>true,'clinical_approval'=>false,'driver'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
