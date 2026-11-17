<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ni2_868_cgi_fixture.php';ob_end_clean();
$a=Fx::user($pdo,$c,'Admin CGI TESTE','admin',null,null,'superadmin');
$orphan=Fx::user($pdo,$c,'Sem Clínica CGI TESTE','admin',null,null,'superadmin');
$pdo->prepare('DELETE FROM clinic_members WHERE user_id=?')->execute([$orphan['user_id']]);
foreach([[$a['user_id'],'admin75@example.test'],[$orphan['user_id'],'orphan75@example.test']] as [$id,$email]){
 $pdo->prepare('UPDATE users SET email=?,password_hash=? WHERE id=?')->execute([$email,password_hash('SenhaCGITest@2026',PASSWORD_DEFAULT),$id]);
 (new \ClyoNutri\Domain\Legal\LegalAcceptanceService($pdo))->recordUserAcceptance($id,$c,'registration','127.0.0.1','CGI TESTE');
}
echo json_encode(['admin'=>$a['user_id'],'clinic'=>$c]);
