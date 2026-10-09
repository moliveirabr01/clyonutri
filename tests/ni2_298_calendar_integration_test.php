<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Time\ClinicCalendar;
use ClyoNutri\Domain\Appointment\AppointmentService;
$db=Connection::getInstance();
$date=$db->query('SELECT substr(scheduled_at,1,10) FROM appointments WHERE id=1')->fetchColumn();
$next=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
$db->prepare("INSERT INTO appointments(id,clinic_id,patient_id,professional_id,scheduled_at,status) VALUES(10,1,1,1,?,'cancelled'),(11,1,1,1,?,'cancelled')")->execute([$date.' 00:00:00',$date.' 23:59:59']);
$before=$db->query('SELECT * FROM appointment_status_history ORDER BY id')->fetchAll();
$checks=[];
function check298(bool $ok,string $name):void {global $checks;$checks[]=['name'=>$name,'status'=>$ok?'PASS':'FAIL'];echo ($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)throw new RuntimeException($name);}
TenantContext::set(1,['id'=>1,'role'=>'nutritionist']);
foreach([$date.'T20:59:59-03:00',$date.'T21:00:00-03:00',$date.'T22:30:00-03:00',$date.'T23:59:59-03:00',$next.'T00:00:00Z'] as $instant) {
 $service=new AppointmentService($db,new DateTimeImmutable($instant));
 $day=$service->todayDate();$list=$service->listByDay($day);$stats=$service->getTodayStats();$attention=$service->getAttentionRequired();
 check298($day===$date && count($list)===8 && $stats['total_today']===8,"Clock $instant: local day/list/indicator agree including both boundaries");
 check298($stats['scheduled']===1 && $stats['completed']===1 && $stats['cancelled']===3,"Clock $instant: scheduled/completed/cancelled status breakdown preserved");
 $unconfirmed=array_values(array_filter($attention,fn($a)=>$a['type']==='unconfirmed_today'));
 check298(count($unconfirmed)===1 && $unconfirmed[0]['action_url']==='/agenda?id=1',"Clock $instant: same-day pending consultation only");
}
$service=new AppointmentService($db,new DateTimeImmutable($next.'T00:00:00-03:00'));
check298($service->todayDate()===$next && $service->getTodayStats()['total_today']===1 && count($service->listByDay($next))===1,'Local midnight advances every view to next day');
check298(count(array_filter($service->getAttentionRequired(),fn($a)=>$a['type']==='unconfirmed_today'))===1,'Midnight pending list advances to next-day scheduled appointment');
$service=new AppointmentService($db,new DateTimeImmutable($date.'T22:00:00-03:00'));
check298(count($service->listByDay($date,1))===8 && count($service->listByDay($date,2))===0,'Professional filter preserved');
TenantContext::set(2,['id'=>2,'role'=>'nutritionist']);
check298($service->getTodayStats()['total_today']===1 && count($service->listByDay($date))===1,'Tenant two sees only its own appointment');
foreach(['2026-02-30','2026-13-01','garbage',"2026-10-08' OR 1=1 --"] as $bad) {
 try{$service->listByDay($bad);throw new RuntimeException('Invalid date accepted');}catch(InvalidArgumentException $e){check298(true,"Invalid date rejected: $bad");}
}
check298($before===$db->query('SELECT * FROM appointment_status_history ORDER BY id')->fetchAll(),'Read queries preserve appointment status history byte-for-byte');
$calendar=new ClinicCalendar(new DateTimeImmutable('2026-01-01T02:59:59Z'));
check298($calendar->today()==='2025-12-31','UTC year boundary resolves previous local year');
check298($calendar->day('2028-02-29')['end']==='2028-03-01 00:00:00','Leap-day interval ends at next local midnight');
file_put_contents(__DIR__.'/../docs/validation/NI2-298/evidence/calendar-results.json',json_encode(['clock'=>'injected DateTimeImmutable into production service; real SQLite queries','checks'=>$checks],JSON_PRETTY_PRINT));
echo count($checks).'/'.count($checks)." checks passed\n";
