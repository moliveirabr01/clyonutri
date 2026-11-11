<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';
\ClyoNutri\Core\Security\Env::load(dirname(__DIR__).'/.env');
try {
    $service=new \ClyoNutri\Domain\Prescription\PrescriptionGovernance(\ClyoNutri\Core\Database\Connection::getInstance());
    if (($argv[1]??'')==='import' && isset($argv[2])) {
        $bytes=file_get_contents($argv[2]);if ($bytes===false || strlen($bytes)>2097152) throw new DomainException('Arquivo inválido ou maior que 2 MB.');
        $service->import(json_decode($bytes,true,512,JSON_THROW_ON_ERROR));
    } elseif (($argv[1]??'')==='revoke' && count($argv)===5) $service->revoke((int)$argv[2],$argv[3],(int)$argv[4]);
    else throw new DomainException('Uso: php bin/prescription_governance.php import /arquivo-revisado.json | revoke CLINIC_ID policy|qualification ID');
    echo "Registro concluído. Esta importação não substitui aprovação clínica/jurídica externa.\n";
} catch (Throwable $e) {fwrite(STDERR,($e instanceof DomainException?$e->getMessage():'Falha na importação; nenhuma aprovação confirmada.')."\n");exit(1);}
