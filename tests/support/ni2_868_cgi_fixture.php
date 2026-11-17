<?php
declare(strict_types=1);
$path=getenv('V54_DB')?:'';if(!str_starts_with($path,'/tmp/ni2-868-cgi-'))throw new RuntimeException('Banco temporário exigido');
require dirname(__DIR__).'/_v54_bootstrap.php';
$c=Fx::clinic($pdo,'Clínica CGI TESTE');$u=Fx::user($pdo,$c,'Nutri CGI TESTE','owner');$p=Fx::patient($pdo,$c,'Paciente CGI TESTE');Fx::act($u);
$pdo->prepare('UPDATE users SET email=?,password_hash=? WHERE id=?')->execute(['cgi868@example.test',password_hash('SenhaCGITest@2026',PASSWORD_DEFAULT),$u['user_id']]);
$pdo->prepare("INSERT INTO onboarding_progress (clinic_id,user_id,current_step,is_completed,practice_preferences,created_at,updated_at) VALUES (?,?,5,1,'{}',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$c,$u['user_id']]);
(new \ClyoNutri\Domain\Legal\LegalAcceptanceService($pdo))->recordUserAcceptance($u['user_id'],$c,'registration','127.0.0.1','CGI TESTE');
(new \ClyoNutri\Domain\Subscription\SubscriptionService($pdo))->getClinicSubscription($c);
$day=date('Y-m-d',strtotime('+14 days'));$ids=[];$svc=new \ClyoNutri\Domain\Appointment\AppointmentService($pdo);
foreach(['day','week','month'] as $view)foreach(['confirmed','cancelled','no_show'] as $status){$a=$svc->schedule($p,$u['professional_id'],$day.' 10:00:00','follow_up',45,null,true,$u['user_id']);$ids[]=['view'=>$view,'status'=>$status,'id'=>$a['id']];}
echo json_encode(['date'=>$day,'appointments'=>$ids]);
