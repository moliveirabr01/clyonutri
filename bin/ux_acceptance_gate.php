<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../vendor/autoload.php';
if($argc!==3){fwrite(STDERR,"Uso: php bin/ux_acceptance_gate.php rodada.json pasta-de-evidencias\n");exit(2);}
try{
    $raw=file_get_contents($argv[1]);
    if(!json_decode($raw,false,64,JSON_THROW_ON_ERROR) instanceof stdClass)throw new InvalidArgumentException();
    $result=\ClyoNutri\Domain\UX\AcceptanceGate::evaluate(json_decode($raw,true,64,JSON_THROW_ON_ERROR),dirname(__DIR__),$argv[2]);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit($result['decision']==='GO'?0:1);
}catch(Throwable $e){fwrite(STDERR,"Entrada de aceite inválida.\n");exit(2);}
