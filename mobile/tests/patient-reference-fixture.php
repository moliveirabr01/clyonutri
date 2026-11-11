<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=getenv('PATIENT_BACKEND_ROOT');$dsn=getenv('ADAPTIVE_TEST_DSN')?:'';
if(!$root||!is_file($root.'/config/build-manifest.json')||!str_starts_with($dsn,'sqlite:/tmp/clyonutri-patient-ds-test-'))throw new RuntimeException('Isolated v54.32 SQLite fixture required');
$manifest=json_decode(file_get_contents($root.'/config/build-manifest.json'),true,64,JSON_THROW_ON_ERROR);
if($manifest['package_version']!=='v54.32')throw new RuntimeException('Wrong backend version');
ob_start();require $root.'/tests/ni2_703_fixture.php';ob_end_clean();
use ClyoNutri\Domain\MealPlan\MealPlanService;
use ClyoNutri\Domain\Dietary\DietarySuggestionService;
use ClyoNutri\Domain\Anthropometry\AnthropometryService;
use ClyoNutri\Domain\Evolution\PatientEvolutionService;
use ClyoNutri\Domain\Engagement\EngagementService;
$patient=$dietPatient;$plan=$dietPlan;
$db->prepare('UPDATE patients SET name=?,email=?,password_hash=? WHERE id=? AND clinic_id=?')
 ->execute(['Marina TESTE','patient-ds-'.$run.'@example.invalid',password_hash('Synthetic-mobile-2026!',PASSWORD_DEFAULT),$patient,$c1]);
$db->prepare('UPDATE anamneses SET water_intake_ml=2000 WHERE patient_id=? AND clinic_id=?')->execute([$patient,$c1]);
$mp=new MealPlanService($db);$mealIds=[];
foreach([['Café da manhã','08:00'],['Almoço','12:00'],['Jantar','18:00']] as [$name,$hour]){
 $meal=$mp->addMeal($plan,$name,$hour,'Orientação sintética da refeição: confira o preparo combinado na consulta.');
 $item=$mp->addItem($meal,$foods[0],300,'g','Orientação sintética do alimento.');
 $mp->addSubstitution($item,$foods[1],250,'g','Alternativa sintética autorizada, sem troca automática.');
 $mealIds[]=$meal;
}
$svc=new DietarySuggestionService($db);$preview=$svc->preview($plan);$card=$preview['cards'][0];
$svc->confirm($plan,bin2hex(random_bytes(16)),$preview['workflow']['revision'],$preview['context_sha256'],[$card['id']],[$card['id']=>'TEXTO SINTÉTICO v54.32: orientações revisadas para testar a leitura no aplicativo.'],'TESTE de integração: revisão sintética, sem paciente real.',true);
$mp->publishPlan($plan,false,true);
$recipeSvc=new ClyoNutri\Domain\Recipe\RecipeService($db);
$recipe=$recipeSvc->createRecipe(['title'=>'Receita SINTÉTICA de teste','servings'=>2,'instructions'=>'Preparo SINTÉTICO: misture os ingredientes e divida em duas porções.','status'=>'published','allergens_verified'=>true],[['food_id'=>$foods[0],'amount'=>200,'measure_unit'=>'g']]);
$recipeSvc->prescribeRecipe($c1,$plan,$mealIds[0],$patient,$recipe['id'],1.0,null,'Café da manhã');
$appointment=(new ClyoNutri\Domain\Appointment\AppointmentService($db))->schedule($patient,$professionals[0],$f['date'].' 16:00:00','first_visit',60,'Consulta SINTÉTICA',false,$users[0]);
$db->prepare('UPDATE patients SET email=?,password_hash=? WHERE id=? AND clinic_id=?')->execute(['patient-other-'.$run.'@example.invalid',password_hash('Synthetic-mobile-2026!',PASSWORD_DEFAULT),$p2,$c2]);
$f['patient_other_email']='patient-other-'.$run.'@example.invalid';$f['mobile_appointment']=$appointment['id'];$f['mobile_recipe']=$recipe['id'];

$anthro=new AnthropometryService($db);
foreach([[-21,72.1],[-14,71.8],[-7,72.0],[0,71.6]] as [$days,$weight]){
 $anthro->recordAssessment($patient,$professionals[0],['weight_kg'=>$weight,'height_cm'=>170,'assessed_at'=>date('Y-m-d H:i:s',strtotime($days.' days')),'notes'=>'Avaliação SINTÉTICA para teste do gráfico','is_shared'=>1],$users[0]);
}
(new PatientEvolutionService($db))->setEnabledMetrics($patient,['weight_kg'],$users[0]);
(new EngagementService($db))->logHydration($patient,500);
(new ClyoNutri\Domain\Chat\PatientLinkService($db))->ensureFromCare($c1,$patient,$professionals[0],'synthetic_test');
$f['patient_email']='patient-ds-'.$run.'@example.invalid';$f['mobile_patient']=$patient;$f['mobile_plan']=$plan;$f['mobile_meals']=$mealIds;
file_put_contents(getenv('ADAPTIVE_FIXTURE_FILE'),json_encode($f));
echo json_encode(['synthetic'=>true,'backend'=>'v54.32','patient'=>$patient,'schema_through'=>87,'published_plan'=>true,'clinical_approval_real'=>false])."\n";
