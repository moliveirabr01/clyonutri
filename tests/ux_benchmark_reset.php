<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$dsn=getenv('ADAPTIVE_TEST_DSN')?:'';if(!str_contains($dsn,'test'))throw new RuntimeException('Exige DSN de testes.');
$db=new PDO($dsn,getenv('ADAPTIVE_TEST_USER')?:null,getenv('ADAPTIVE_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
ClyoNutri\Core\Database\Connection::setInstance($db);$path=getenv('ADAPTIVE_FIXTURE_FILE');$f=json_decode(file_get_contents($path),true);ClyoNutri\Core\Auth\TenantContext::set($f['clinic'],['id'=>$f['user'],'role'=>'nutritionist']);
$s=new ClyoNutri\Domain\MealPlan\MealPlanService($db);$f['baseline_plan']=$s->createPlan($f['patient'],$f['professional'],'TESTE benchmark v54.17')['id'];$f['quick_plan']=$s->createPlan($f['patient'],$f['professional'],'TESTE benchmark v54.18')['id'];file_put_contents($path,json_encode($f));echo "Dois planos sintéticos vazios criados.\n";
