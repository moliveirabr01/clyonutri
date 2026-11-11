<?php
declare(strict_types=1);
ob_start();require __DIR__.'/ni2_688.php';ob_end_clean();
use ClyoNutri\Core\Release\BuildIdentity as B;
use ClyoNutri\Domain\UX\AcceptanceGate as G;
$root=sys_get_temp_dir().'/clyonutri-acceptance-test-'.bin2hex(random_bytes(6));
mkdir($root.'/config',0700,true);mkdir($root.'/bin/homologation',0700,true);mkdir($root.'/evidence');
copy(__DIR__.'/../bin/homologation/GateReport.php',$root.'/bin/homologation/GateReport.php');
file_put_contents($root.'/evidence/UNIT-ONLY.txt','Synthetic unit fixture; never a real approval.');
$m=B::generate($root);file_put_contents($root.'/config/build-manifest.json',json_encode($m));
$receipt=['status'=>'PASS','executed'=>true,'build_id'=>$m['build_id'],'content_sha256'=>$m['content_sha256'],'at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'reviewer'=>'UNIT ONLY','path'=>'UNIT-ONLY.txt','sha256'=>hash_file('sha256',$root.'/evidence/UNIT-ONLY.txt')];
require $root.'/bin/homologation/GateReport.php';
$input=['build_id'=>$m['build_id'],'content_sha256'=>$m['content_sha256'],'evidence'=>array_fill_keys(G::EVIDENCE,$receipt),'approvals'=>array_fill_keys(G::ROLES,$receipt),'clinical_review'=>$receipt+['reviewed'=>true,'p0_open'=>0,'p1_clinical_open'=>0],'human_round'=>unitRound(),'homologation'=>$receipt+['commit'=>str_repeat('a',40),'checks'=>array_map(fn($id)=>['id'=>$id,'priority'=>'P0','status'=>'PASS'],GateReport::PROFILES['web'])]];
$input['human_round']['build']=$m['build_id'];$input['human_round']['build_sha256']=$m['content_sha256'];
foreach($input['human_round']['sessions'] as &$session){$session['build']=$m['build_id'];$session['build_sha256']=$m['content_sha256'];}unset($session);
$checks695=[];$evaluate=fn($x)=>G::evaluate($x,$root,$root.'/evidence');
function gcheck(string $name,bool $ok):void{global $checks695;$checks695[]=['name'=>$name,'pass'=>$ok];if(!$ok)throw new RuntimeException($name);}
try{
 gcheck('complete synthetic fixture only can GO',$evaluate($input)['decision']==='GO');
 gcheck('empty acceptance fails closed',$evaluate([])['decision']==='NO-GO');
 foreach(G::EVIDENCE as $key){$x=$input;unset($x['evidence'][$key]);gcheck('missing '.$key.' blocks',$evaluate($x)['decision']==='NO-GO');}
 foreach(G::ROLES as $key){$x=$input;unset($x['approvals'][$key]);gcheck('missing approval '.$key.' blocks',$evaluate($x)['decision']==='NO-GO');}
 foreach(['p0_open','p1_clinical_open'] as $key){$x=$input;$x['clinical_review'][$key]=1;gcheck('open clinical '.$key.' cannot be waived',$evaluate($x)['decision']==='NO-GO');}
 $x=$input;unset($x['clinical_review']['p0_open']);gcheck('unknown is not zero',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['evidence']['integrity']['sha256']=str_repeat('0',64);gcheck('modified evidence blocks',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['evidence']['integrity']['at_utc']='2000-01-01T00:00:00Z';gcheck('evidence before build blocks',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['evidence']['integrity']['executed']=false;gcheck('prepared evidence is not executed',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['evidence']['integrity']['build_id']='previous';gcheck('other build receipt blocks',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['evidence']['integrity']['path']='../config/build-manifest.json';gcheck('evidence traversal blocks',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['human_round']['sessions']=[];gcheck('automation cannot replace human scorecard',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['homologation']['checks']=[];gcheck('existing official gate cannot be bypassed',$evaluate($x)['decision']==='NO-GO');
 $x=$input;$x['homologation']['commit']='';gcheck('ZIP cannot fabricate source commit',$evaluate($x)['decision']==='NO-GO');
 file_put_contents($root.'/evidence/UNIT-ONLY.txt','changed');gcheck('actual evidence content drift blocks',$evaluate($input)['decision']==='NO-GO');
 echo json_encode(['suite'=>'NI2-695 acceptance gate','unit_test_only'=>true,'checks'=>$checks695],JSON_PRETTY_PRINT)."\n";
}finally{
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
