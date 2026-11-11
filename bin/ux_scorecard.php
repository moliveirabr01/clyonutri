<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../vendor/autoload.php';
if(empty($argv[1])||$argc>3||($argc===3&&$argv[2]!=='--gate')){fwrite(STDERR,"Uso: php bin/ux_scorecard.php arquivo-da-rodada.json [--gate]\n");exit(2);}
try{
    $json=file_get_contents($argv[1]);
    if(!json_decode($json,false,64,JSON_THROW_ON_ERROR) instanceof \stdClass)throw new InvalidArgumentException('Objeto esperado.');
    $input=json_decode($json,true,64,JSON_THROW_ON_ERROR);
    $result=\ClyoNutri\Domain\UX\Scorecard::evaluate($input);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    if(($argv[2]??null)==='--gate'&&$result['release_gate']!=='GO')exit(1);
}catch(Throwable $e){fwrite(STDERR,"Arquivo de avaliação inválido.\n");exit(2);}
