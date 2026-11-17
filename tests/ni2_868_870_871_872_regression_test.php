<?php
declare(strict_types=1);
putenv('V54_DB='.sys_get_temp_dir().'/ni2-868-872-'.getmypid().'.sqlite');
require __DIR__.'/_v54_bootstrap.php';
ob_start();
use ClyoNutri\Application\View\AgendaReturnLocation;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Domain\Patient\{PatientImportService,PatientRepository};
use ClyoNutri\Domain\Onboarding\OnboardingService;
use ClyoNutri\Domain\Appointment\{AppointmentService,AppointmentConflictException};
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Observability\{ProductionEvent,ProductionEventService};
use ClyoNutri\Domain\Clinical\WorkflowState;
use ClyoNutri\Core\I18n\LabelMap;

$c=Fx::clinic($pdo,'Clínica TESTE');$u=Fx::user($pdo,$c,'Nutri TESTE','owner');Fx::act($u);
$p=(int)(new PatientRepository($pdo))->create(['name'=>'Paciente TESTE','allergies'=>'amendoim','allergy_assessment'=>'reported'])['id'];
$day=date('Y-m-d',strtotime('+14 days'));
foreach(['day','week','month'] as $view){
    $url=AgendaReturnLocation::make(['date'=>$day,'view'=>$view],null,['status_updated'=>'1']);parse_str(parse_url($url,PHP_URL_QUERY),$q);
    T::ok($q===['view'=>$view,'date'=>$day,'status_updated'=>'1'],"868: retorno $view preserva data e sucesso");
    $url=AgendaReturnLocation::make(['date'=>$day,'view'=>$view],null,['error'=>'transicao_invalida']);parse_str(parse_url($url,PHP_URL_QUERY),$q);
    T::ok($q['view']===$view&&$q['date']===$day&&$q['error']==='transicao_invalida',"868: erro também preserva contexto $view");
}
$url=AgendaReturnLocation::make(['date'=>'2026-02-31','view'=>'https://evil.example'], $day.' 10:00:00');
T::ok($url==='/agenda?view=day&date='.$day,'868: data inexistente e view externa não são aceitas');
T::ok(!AgendaReturnLocation::validDate(['x']) && !AgendaReturnLocation::validDate('2026-13-01') && !AgendaReturnLocation::validDate("2026-01-01\0"),'868: rejeita array e mês inválido');
$apptSvc=new AppointmentService($pdo);$app=$apptSvc->schedule($p,(int)$u['professional_id'],$day.' 10:00:00','follow_up',45,null,true,(int)$u['user_id']);
$_SESSION=['user_name'=>'Nutri TESTE','clinic_name'=>'Clínica TESTE'];$_GET=['date'=>$day,'view'=>'day'];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
ob_start();(new \ClyoNutri\Application\Controllers\DashboardController())->agenda();$html=ob_get_clean();
preg_match_all('~<form[^>]*action="/agenda/\d+/status"[^>]*>(.*?)</form>~s',$html,$forms);
T::ok(count($forms[1])>=3,'868: ações reais da agenda foram renderizadas');
$all=true;foreach($forms[1] as $form)$all=$all&&str_contains($form,'name="view" value="day"')&&str_contains($form,'name="date" value="'.$day.'"');
T::ok($all,'868: todos os formulários de status carregam view e data');

$imports=new PatientImportService($pdo);$csv="Nome;E-mail\nPaciente Importado TESTE;import868@example.test\n";
$batch=$imports->preview($csv,'teste.csv',(int)$u['user_id']);
T::ok($imports->preview($csv,'teste.csv',(int)$u['user_id'])['reused'],'870: prévia ativa continua idempotente');
$imports->confirm((int)$batch['batch_id']);T::ok($imports->preview($csv,'teste.csv',(int)$u['user_id'])['reused'],'870: lote confirmado também permanece idempotente');$rb=$imports->rollback((int)$batch['batch_id']);
T::ok($rb['removed']===1&&$rb['kept']===0,'870: rollback real remove 100% do lote');
$new=$imports->preview($csv,'teste.csv',(int)$u['user_id']);T::ok(!$new['reused']&&$new['status']==='preview'&&$new['batch_id']!==$batch['batch_id'],'870: mesmo arquivo gera nova prévia após rollback');
$pdo->prepare("UPDATE patient_import_batches SET status='confirming' WHERE id=?")->execute([$new['batch_id']]);
$inProgress=$imports->preview($csv,'teste.csv',(int)$u['user_id']);T::ok($inProgress['reused']&&$inProgress['status']==='confirming','870: lote em processamento não é apagado');

$needle='TEXTO_CLINICO_SIGILOSO_TESTE_871';$logger=new AuditLogger($pdo);
foreach(['clinical_records'=>'CLINICAL_RECORD_SAVED','anamnesis'=>'ANAMNESIS_SAVED','anthropometric_assessments'=>'ANTHROPOMETRY_SAVED','meal_plans'=>'MEAL_PLAN_PUBLISHED','prescriptions'=>'PRESCRIPTION_ISSUED','exam_requests'=>'EXAM_REQUEST_ISSUED','protocol_responses'=>'PROTOCOL_SAVED'] as $resource=>$type){
    $logger->log($type,$resource,'1',['allergen_override'=>true,'allergen_override_reason'=>$needle,'allergenOverrideReason'=>$needle,'unknown_clinical_field'=>$needle,'nested'=>[['free_text'=>$needle]],'version_number'=>2,'status'=>'published']);
    $st=$pdo->prepare('SELECT metadata FROM production_events WHERE event_type=? ORDER BY id DESC LIMIT 1');$st->execute([$type]);$raw=(string)$st->fetchColumn();$meta=json_decode($raw,true);
    T::ok($raw!==''&&!str_contains($raw,$needle)&&($meta['version_number']??null)===2&&($meta['allergen_override']??null)===true,"871: metadados persistidos de $type sem texto livre");
}
$raw=(string)$pdo->query("SELECT details FROM audit_logs WHERE action='MEAL_PLAN_PUBLISHED' ORDER BY id DESC LIMIT 1")->fetchColumn();T::ok(str_contains($raw,$needle),'871: justificativa original permanece na auditoria clínica');
$pdo->exec("INSERT INTO foods (id,name,category,energy_kcal,protein_g,carbohydrate_g,lipid_g,allergens) VALUES (91001,'Arroz TESTE','Cereais',120,2,25,1,NULL),(91002,'Amendoim TESTE','Leguminosas',500,20,10,40,'amendoim')");
$allergyRecord=\ClyoNutri\Domain\Patient\PatientAllergyRecord::read($pdo,$c,$p);
T::ok($allergyRecord['allergies']==='amendoim' && \ClyoNutri\Core\Security\ClinicalEncryptionService::isEncrypted((string)$pdo->query('SELECT allergies FROM patients WHERE id='.$p)->fetchColumn()),'872: alergia cifrada é decifrada na leitura clínica sem alterar armazenamento');
$plans=new MealPlanService($pdo);$plan=$plans->createPlan($p,(int)$u['professional_id'],'Plano TESTE',null,[['name'=>'Almoço','items'=>[['food_id'=>91001,'amount'=>100,'measure_unit'=>'g']]]]);
$planId=(int)$plan['plan_id'];$preview=$plans->getPlanPreview($planId);
T::ok(!$preview['allergen_safety']['has_conflict']&&!empty($preview['allergen_safety']['unverified_foods']),'872: falta de informação não é classificada como conflito');
$renderWarning=static function(array $s):string{$allergenSafety=$s;ob_start();require dirname(__DIR__).'/views/meal_plans/_allergen_publication_warning.php';return ob_get_clean();};
$html=$renderWarning($preview['allergen_safety']);T::ok(str_contains($html,'Arroz TESTE')&&str_contains($html,'sem informação')&&!str_contains($html,'Conflito de alérgeno detectado'),'872: aviso nomeia alimento sem informação sem alegar conflito');
$plans->addItem((int)$preview['plan']['meals'][0]['id'],91002,10,'g');$preview=$plans->getPlanPreview($planId);$html=$renderWarning($preview['allergen_safety']);
T::ok(str_contains($html,'Conflito de alérgeno detectado')&&str_contains($html,'Amendoim TESTE')&&str_contains($html,'Arroz TESTE')&&str_contains($html,'allergen-unverified-list'),'872: aviso misto lista conflitos e alimentos sem informação');
T::throws(fn()=>$plans->publishPlan($planId),'872: publicação ainda exige revisão clínica');
$plans->publishPlan($planId,true,true,$needle,true,'Adequação conferida em cenário de teste.',true);
$st=$pdo->prepare('SELECT allergen_override_reason FROM meal_plan_versions WHERE meal_plan_id=?');$st->execute([$planId]);T::ok($st->fetchColumn()===$needle,'871: versão publicada preserva justificativa clínica');
$st=$pdo->query("SELECT metadata FROM production_events WHERE event_type='MEAL_PLAN_PUBLISHED' ORDER BY id DESC LIMIT 1");$raw=(string)$st->fetchColumn();T::ok(!str_contains($raw,$needle)&&json_decode($raw,true)['allergen_override']===true,'871: publicação real emite somente indicador de override');
foreach(['draft'=>'Rascunho','published'=>'Publicado','superseded'=>'Substituído','archived'=>'Arquivado'] as $status=>$label){T::ok(WorkflowState::label($status)===$label&&LabelMap::mealPlanStatus($status)===$label,"872: status $status traduzido nas duas apresentações");}

$onb=new OnboardingService($pdo);$onb->saveStep((int)$u['user_id'],$c,3,['appointment_interval_minutes'=>15,'default_duration_minutes'=>45]);
$onb->saveStep((int)$u['user_id'],$c,3,['appointment_interval_minutes'=>15,'default_duration_minutes'=>45]);
$policy=$pdo->query("SELECT * FROM booking_calendar_policies WHERE clinic_id=$c")->fetch();T::ok((int)$policy['buffer_after_minutes']===15,'872: salvar intervalo duas vezes é idempotente');
$pdo->exec("UPDATE booking_calendar_policies SET buffer_before_minutes=5,waitlist_enabled=1,offer_ttl_minutes=25 WHERE clinic_id=$c");
$onb->saveStep((int)$u['user_id'],$c,3,['appointment_interval_minutes'=>15]);
$keptPolicy=$pdo->query("SELECT * FROM booking_calendar_policies WHERE clinic_id=$c")->fetch();T::ok((int)$keptPolicy['buffer_before_minutes']===5 && (int)$keptPolicy['waitlist_enabled']===1 && (int)$keptPolicy['offer_ttl_minutes']===25,'872: intervalo não sobrescreve demais campos da política');
$pdo->exec("UPDATE booking_calendar_policies SET buffer_before_minutes=0 WHERE clinic_id=$c");
$onb->saveStep((int)$u['user_id'],$c,3,['default_duration_minutes'=>45]);T::ok((int)$pdo->query("SELECT buffer_after_minutes FROM booking_calendar_policies WHERE clinic_id=$c")->fetchColumn()===15,'872: ausência do campo não sobrescreve política existente');
T::throws(fn()=>$onb->saveStep((int)$u['user_id'],$c,3,['appointment_interval_minutes'=>121]),'872: intervalo acima de 120 recusado','0 a 120');
T::throws(fn()=>$onb->saveStep((int)$u['user_id'],$c,3,['appointment_interval_minutes'=>-1]),'872: intervalo negativo recusado','0 a 120');
// Day avoids the default lunch break; real conflict check must include the configured buffer.
$d=date('Y-m-d',strtotime('next monday +21 days'));
$apptSvc->schedule($p,(int)$u['professional_id'],$d.' 09:00:00','follow_up',45,null,false,(int)$u['user_id']);
T::throws(fn()=>$apptSvc->schedule($p,(int)$u['professional_id'],$d.' 09:45:00','follow_up',45,null,false,(int)$u['user_id']),'872: consulta colada é bloqueada pelo intervalo efetivo');
$next=$apptSvc->schedule($p,(int)$u['professional_id'],$d.' 10:00:00','follow_up',45,null,false,(int)$u['user_id']);T::ok(!empty($next['id']),'872: consulta após intervalo é aceita');
$other=Fx::user($pdo,$c,'Nutri sem gestão','nutritionist');Fx::act($other);T::throws(fn()=>$onb->saveStep((int)$other['user_id'],$c,3,['appointment_interval_minutes'=>0]),'872: profissional sem gestão não altera intervalo global');
T::done();
