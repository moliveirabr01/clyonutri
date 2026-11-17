<?php
/** CLI render benchmark with synthetic data. Not HTTP middleware or a replacement for journey E2E. */
declare(strict_types=1);
putenv('V54_DB=' . sys_get_temp_dir() . '/ni2-892-perf-' . getmypid() . '.sqlite');
putenv('DB_QUERY_PROFILE=1');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\ProfiledPdo;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Application\Controllers\DashboardController;

$c=Fx::clinic($pdo,'Clínica TESTE 892');$u=Fx::user($pdo,$c,'Nutri TESTE 892','owner');
Fx::act($u);
$p=Fx::patient($pdo,$c,'Paciente TESTE 892');
$pdo->exec("INSERT OR IGNORE INTO booking_settings (clinic_id) VALUES ($c)");
$pdo->exec("INSERT INTO foods (id,name,energy_kcal,protein_g,carbohydrate_g,lipid_g) VALUES (90001,'Arroz TESTE 892',124,2,26,1)");
$svc=new \ClyoNutri\Domain\MealPlan\MealPlanService($pdo);
$plan=$svc->createPlan($p,(int)$u['professional_id'],'Plano TESTE 892',null,[['name'=>'Almoço','time_of_day'=>'12:00','items'=>[['food_id'=>90001,'amount'=>100,'measure_unit'=>'g']]]]);
$_SESSION=['user_id'=>$u['user_id'],'user_name'=>'Nutri TESTE','clinic_id'=>$c,'clinic_name'=>'Clínica TESTE'];
$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
$db=new ProfiledPdo('sqlite:'.getenv('V54_DB'));
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
Connection::setInstance($db);
SchemaInfo::reset();
$db->metrics()->reset();
$page=$argv[1]??'profile';
$_SERVER['REQUEST_URI']=match($page){'profile'=>"/pacientes/$p",'plan'=>'/planos/'.$plan['plan_id'],'agenda'=>'/agenda',default=>'/dashboard'};
$controller=new DashboardController();
ob_start();
try {
    match($page){'profile'=>$controller->profile360(['id'=>$p]),'plan'=>$controller->mealPlanEditor(['id'=>$plan['plan_id']]),'agenda'=>$controller->agenda(),'dashboard'=>$controller->index(),default=>throw new InvalidArgumentException('Unknown page')};
    $html=ob_get_clean();
    $snapshot=$db->metrics()->snapshot();
    echo json_encode(['page'=>$page,'scope'=>'controller_render_only','status'=>http_response_code()?:200,'html_bytes'=>strlen($html)]+$snapshot,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
} finally {
    while(ob_get_level()>0)ob_end_clean();
    foreach([getenv('V54_DB'),getenv('V54_DB').'-wal',getenv('V54_DB').'-shm'] as $file)if(is_file($file))unlink($file);
}
