<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Core\Release\BuildIdentity;
$root = dirname(__DIR__);
if (($argv[1]??'') === '--generate' && $argc === 2) {
    $manifest = BuildIdentity::generate($root);
    $dest = $root.'/config/build-manifest.json';
    $tmp = tempnam(dirname($dest), '.build-');
    if ($tmp === false) throw new RuntimeException('Cannot create manifest');
    try {
        if (file_put_contents($tmp,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n") === false
            || !rename($tmp,$dest)) throw new RuntimeException('Cannot persist manifest');
    } finally { if (is_file($tmp)) unlink($tmp); }
} elseif (($argv[1]??'') !== '--verify' || $argc !== 2) {
    fwrite(STDERR,"Uso: php bin/build_identity.php --generate|--verify\n"); exit(2);
}
$result = BuildIdentity::inspect($root);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
exit($result['integrity']==='consistent'?0:1);
