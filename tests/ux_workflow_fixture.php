<?php
declare(strict_types=1);
// Reuse the real, isolated schema/tenant fixture; never connects to production.
if (!getenv('ADAPTIVE_FIXTURE_FILE')) putenv('ADAPTIVE_FIXTURE_FILE='.tempnam(sys_get_temp_dir(),'clyonutri-ux-fixture-'));
ob_start(); require __DIR__.'/adaptive_protocols.php'; ob_end_clean();
$fixturePath=getenv('ADAPTIVE_FIXTURE_FILE');$f=json_decode(file_get_contents($fixturePath),true);
$foods=[];
foreach(['Alfa','Beta','Gama','Delta'] as $n)$foods[]=ins('foods',['name'=>'TESTE UX '.$n,'energy_kcal'=>100,'protein_g'=>5,'carbohydrate_g'=>10,'lipid_g'=>2,'fiber_g'=>1,'is_custom'=>1,'clinic_id'=>$c1,'allergens'=>'nenhum']);
$mp=new ClyoNutri\Domain\MealPlan\MealPlanService($db);
$f['baseline_plan']=$mp->createPlan($p1,$professionals[0],'TESTE UX baseline')['id'];
$f['quick_plan']=$mp->createPlan($p1,$professionals[0],'TESTE UX otimizado')['id'];
$f['foods']=$foods;
$fixtureMeal=$mp->addMeal($f['quick_plan'],'TESTE UX refeição inicial','08:00');
$mp->addItem($fixtureMeal,$foods[0],100,'g'); // Browser copy/edit journeys require a populated meal.

$prefs=['default_duration_minutes'=>60,'weekly_availability'=>['ter'=>['enabled'=>true,'start'=>'08:00','end'=>'18:00','break_start'=>'12:00','break_end'=>'13:00']]];
ins('onboarding_progress',['clinic_id'=>$c1,'user_id'=>$users[0],'practice_preferences'=>json_encode($prefs),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
$db->prepare('UPDATE professionals SET booking_slug=? WHERE id=?')->execute(['teste-ux-'.$run,$professionals[0]]);
(new ClyoNutri\Domain\Booking\BookingService($db))->updateSettings($c1,['is_enabled'=>1,'min_advance_hours'=>0,'max_future_days'=>60,'allow_presential'=>1,'allow_telehealth'=>0]);
$f['booking_slug']='teste-ux-'.$run;$f['date']=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->modify('+7 days')->modify('next Tuesday')->format('Y-m-d');
file_put_contents($fixturePath,json_encode($f));echo json_encode(['fixture_created'=>true,'scope'=>'synthetic isolated database'])."\n";
