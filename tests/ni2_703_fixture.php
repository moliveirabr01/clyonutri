<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ux_workflow_fixture.php';ob_end_clean();
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Dietary\DietaryStarterCatalog;
use ClyoNutri\Domain\ClinicalProtocol\{ProtocolCatalog,ProtocolWorkspace,PatientConditionService};
use ClyoNutri\Domain\MealPlan\MealPlanService;
TenantContext::set($c1,['id'=>$users[0],'role'=>'nutritionist']);
$catalog=new ProtocolCatalog($db);$workspace=new ProtocolWorkspace($db);$conditionsService=new PatientConditionService($db);
$dietCodes=[];$dietVersions=[];
foreach(array_keys(DietaryStarterCatalog::all()) as $kind){
 $code='diet_test_'.$run.'_'.$kind;$dietCodes[$kind]=$code;
 $pid=$catalog->register($code,'TESTE Dietética '.$kind);
 $db->prepare('UPDATE clinical_protocols SET clinic_id=? WHERE id=?')->execute([$c1,$pid]);
 $version=$catalog->createDraft($pid,DietaryStarterCatalog::definition($kind),'UNIT/SYNTHETIC approval for engineering tests ONLY. Not a clinical approval.',$users[0]);
 $catalog->submit($version,$users[0]);$catalog->approve($version,$users[1],'UNIT Revisor','CRN-3 12345',date('Y-m-d',strtotime('+1 year')));
 $workspace->setClinicEnabled($c1,$code,true,$users[0]);$dietVersions[$kind]=$version;
}
$dietPatient=ins('patients',['clinic_id'=>$c1,'uuid'=>'diet-'.$run,'name'=>'TESTE Sugestões Dietéticas','birth_date'=>'1990-01-01']);
(new ClyoNutri\Domain\Anamnesis\AnamnesisService($db))->saveAnamnesis($dietPatient,$professionals[0],['notes'=>'TESTE','allergy_assessment'=>'denied'],false);
$dietCondition=$conditionsService->record($c1,$dietPatient,$dietCodes['diabetes'],'documented','professional',$users[0]);
$dietPlan=(new MealPlanService($db))->createPlan($dietPatient,$professionals[0],'TESTE Plano Dietético')['id'];
$f['diet_patient']=$dietPatient;$f['diet_plan']=$dietPlan;$f['diet_codes']=$dietCodes;$f['diet_versions']=$dietVersions;$f['diet_condition']=$dietCondition;
file_put_contents(getenv('ADAPTIVE_FIXTURE_FILE'),json_encode($f));
echo json_encode(['synthetic'=>true,'approvals'=>'UNIT/SYNTHETIC ONLY','fixture_created'=>true])."\n";
