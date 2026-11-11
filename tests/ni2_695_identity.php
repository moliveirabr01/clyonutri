<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Core\Release\BuildIdentity as B;
$root=sys_get_temp_dir().'/clyonutri-build-test-'.bin2hex(random_bytes(6));
mkdir($root.'/config',0700,true);mkdir($root.'/src');file_put_contents($root.'/src/one.php','<?php echo 1;');
$checks=[];
function biCheck(string $name,bool $ok):void {global $checks;$checks[]=['name'=>$name,'pass'=>$ok];if(!$ok)throw new RuntimeException($name);}
function writeManifest():void {global $root;file_put_contents($root.'/config/build-manifest.json',json_encode(B::generate($root)));}
try {
    biCheck('missing manifest fails closed',@B::inspect($root)['integrity']==='unverified');
    writeManifest();$before=B::inspect($root);
    biCheck('generated inventory verifies',$before['integrity']==='consistent');
    biCheck('UI version and package reported separately',$before['ui_version']===B::UI_VERSION&&$before['package_version']===B::PACKAGE_VERSION); // v54.33: visual e pacote coincidem nesta versão (NI2-687 muda o visual)
    file_put_contents($root.'/src/one.php','<?php echo 2;');
    biCheck('changed file fails closed',B::inspect($root)['integrity']==='unverified');
    file_put_contents($root.'/src/one.php','<?php echo 1;');
    biCheck('rollback restores same identity',B::inspect($root)===$before);
    file_put_contents($root.'/src/new.php','unexpected');
    biCheck('unexpected runtime file fails closed',B::inspect($root)['integrity']==='unverified');
    unlink($root.'/src/new.php');unlink($root.'/src/one.php');
    biCheck('missing runtime file fails closed',B::inspect($root)['integrity']==='unverified');
    file_put_contents($root.'/src/one.php','<?php echo 1;');
    $m=json_decode(file_get_contents($root.'/config/build-manifest.json'),true);$m['files']=['../../private'=>str_repeat('a',64)];$m['content_sha256']=B::fingerprint($m['files']);$m['build_id']=B::PACKAGE_VERSION.'-'.substr($m['content_sha256'],0,16);file_put_contents($root.'/config/build-manifest.json',json_encode($m));
    biCheck('manifest traversal never reads external file',B::inspect($root)['integrity']==='unverified');
    writeManifest();symlink($root.'/src/one.php',$root.'/src/link.php');
    biCheck('symlink fails closed',B::inspect($root)['integrity']==='unverified');unlink($root.'/src/link.php');
    file_put_contents($root.'/config/build-manifest.json','{');
    biCheck('malformed manifest fails closed',B::inspect($root)['integrity']==='unverified');
    echo json_encode(['suite'=>'NI2-701 identity','checks'=>$checks],JSON_PRETTY_PRINT)."\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f) {if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
