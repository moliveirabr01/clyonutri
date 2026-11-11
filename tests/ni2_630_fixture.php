<?php
declare(strict_types=1);
if(!getenv('ADAPTIVE_FIXTURE_FILE'))throw new RuntimeException('Synthetic fixture output required');
putenv('NI2685_FIXTURE_OUT='.getenv('ADAPTIVE_FIXTURE_FILE'));
ob_start();require __DIR__.'/ni2_685.php';ob_end_clean();
$f=json_decode(file_get_contents(getenv('ADAPTIVE_FIXTURE_FILE')),true,64,JSON_THROW_ON_ERROR);
$f['agenda_appointment']=ins('appointments',['clinic_id'=>$c1,'patient_id'=>$f['registration_patient'],'professional_id'=>$professionals[0],'scheduled_at'=>$f['date'].' 08:00:00','duration_minutes'=>60,'type'=>'first_visit','status'=>'scheduled']);
file_put_contents(getenv('ADAPTIVE_FIXTURE_FILE'),json_encode($f));
echo json_encode(['synthetic_fixture_created'=>true,'schema'=>'current migrations','two_clinics'=>true,'agenda_rows_seeded'=>1])."\n";
