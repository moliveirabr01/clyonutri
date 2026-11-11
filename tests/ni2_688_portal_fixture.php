<?php
declare(strict_types=1);
// Local synthetic database only; no production connection or external delivery.
require __DIR__.'/../vendor/autoload.php';
$dbFile=$argv[1]??'';$fixtureFile=$argv[2]??'';
if(!str_starts_with($dbFile,'/tmp/clyonutri-ni2688-')||!str_contains($dbFile,'test.sqlite')||!str_starts_with($fixtureFile,'/tmp/clyonutri-ni2688-'))throw new RuntimeException('Isolated NI2-688 test paths required');
$f=json_decode(file_get_contents($fixtureFile),true,64,JSON_THROW_ON_ERROR);
$db=new PDO('sqlite:'.$dbFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$email='patient688-'.$f['clinic'].'@example.invalid';
$db->prepare('UPDATE patients SET email=?,password_hash=? WHERE id=? AND clinic_id=?')->execute([$email,password_hash('Synthetic-local-688!',PASSWORD_DEFAULT),$f['patient'],$f['clinic']]);
$f['patient_email']=$email;file_put_contents($fixtureFile,json_encode($f));
echo json_encode(['synthetic_patient_credentials_seeded'=>true])."\n";
