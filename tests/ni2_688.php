<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use ClyoNutri\Domain\UX\Scorecard;
$checks=[];
function check688(string $test,bool $pass):void{global $checks;$checks[]=['test'=>$test,'pass'=>$pass];if(!$pass)throw new RuntimeException($test);}
function approved():array{return ['approved'=>true,'reviewer'=>'UNIT-REVIEWER','at'=>'2026-11-04T01:00:00Z','evidence'=>'UNIT-TEST-ONLY'];}
function unitRound():array{
 $r=['schema_version'=>2,'study_design'=>'paired_counterbalanced','protocol_id'=>'UX19-2','tasks_id'=>'UX19-T1-T8-P1-P3','profiles_id'=>'three-strata-v2','conditions_id'=>'UNIT-CONDITIONS','fixture_id'=>'UNIT-FIXTURE','build'=>'UNIT-CANDIDATE','build_sha256'=>str_repeat('a',64),'environment'=>'UNIT-TEST','browser'=>'UNIT-BROWSER','evaluated_at'=>'2026-11-04T02:00:00Z','raters'=>['UNIT-A','UNIT-B'],'sample'=>array_fill_keys(Scorecard::PROFILES,5),'evidence_reviewed'=>true,'clinical_go'=>true,'p0_count'=>0,'p1_clinical_count'=>0,'accessibility_review'=>approved(),'sessions'=>[]];
 foreach(['ux','qa','product','engineering','clinical'] as $role)$r['approvals'][$role]=approved();
 foreach(Scorecard::PROFILES as $profile)for($n=0;$n<5;$n++){
  $s=['participant_code'=>'UNIT-'.$profile.'-'.$n,'profile'=>$profile,'source'=>'human','synthetic_data_only'=>true,'consent_reference'=>'UNIT-CONSENT','recording_reference'=>'UNIT-RECORDING','consented_at'=>'2026-11-04T00:00:00Z','started_at'=>'2026-11-04T00:01:00Z','build_order'=>$n%2?'candidate_first':'baseline_first','device'=>'UNIT-DEVICE','browser'=>'UNIT-BROWSER','limitations'=>'Unit test only; never report these as participant data.','sus_responses'=>[5,1,5,1,5,1,5,1,5,1]];
  foreach(['protocol_id','build','build_sha256','conditions_id','fixture_id'] as $key)$s[$key]=$r[$key];
  foreach(Scorecard::TASKS[$profile] as $id)$s['tasks'][]=['task_id'=>$id,'executed'=>true,'completed_without_help'=>true,'outcome'=>'completed','started_at'=>'2026-11-04T00:02:00Z','finished_at'=>'2026-11-04T00:03:00Z','clicks'=>1,'input_change_events'=>2,'document_reloads'=>0,'assistance'=>0,'use_errors'=>0,'clinical_errors_reviewed'=>true,'clinical_errors'=>[],'evidence_reference'=>'UNIT-TASK'];
  foreach(Scorecard::LAWS as $law)for($i=1;$i<=5;$i++)$s['laws'][$law][]=['criterion_id'=>$law.'-'.$i,'applicable'=>true,'executed'=>true,'rater_a'=>4,'rater_b'=>4,'evidence'=>'UNIT-EVIDENCE','observed_at'=>'2026-11-04T00:02:00Z'];
  $r['sessions'][]=$s;
 }return $r;
}
$r=unitRound();$out=Scorecard::evaluate($r);
check688('complete synthetic contract accepted in unit test only',$out['release_gate']==='GO'&&$out['sample']['patient']===5);
check688('empty round seven untested and NO-GO',Scorecard::evaluate([])['scores']===array_fill_keys(Scorecard::LAWS,null)&&Scorecard::evaluate([])['release_gate']==='NO-GO');
$x=$r;unset($x['sessions']);$x['human_sessions_complete']=true;$x['consent_recorded']=true;check688('legacy summary booleans cannot certify',!Scorecard::evaluate($x)['target_met']);
$x=$r;foreach($x['sessions'] as &$s)foreach($s['laws']['Hick'] as &$i)$i['rater_a']=$i['rater_b']=3;unset($s,$i);check688('six laws four and Hick three fail independently',Scorecard::evaluate($x)['scores']['Hick']===3&& !Scorecard::evaluate($x)['target_met']);
$x=$r;foreach($x['sessions'] as &$s)if($s['profile']==='patient')foreach($s['laws']['Miller'] as &$i)$i['rater_a']=$i['rater_b']=3;unset($s,$i);check688('weak patient stratum is not hidden',Scorecard::evaluate($x)['scores']['Miller']===3);
$x=$r;$x['p0_count']=1;check688('declared P0 independently blocks release',Scorecard::evaluate($x)['release_gate']==='NO-GO');
$x=$r;$x['sessions'][0]['tasks'][0]['clinical_errors']=[['severity'=>'P1','evidence'=>'UNIT-P1']];check688('observed P1 overrides declared zero',Scorecard::evaluate($x)['clinical_gate']==='NO-GO');
$x['sessions'][0]['source']='automation';check688('P1 in invalid automated record still blocks clinical gate',Scorecard::evaluate($x)['clinical_gate']==='NO-GO');
foreach(['executed','applicable'] as $flag){$x=$r;$x['sessions'][0]['laws']['Fitts'][0][$flag]=false;check688('criterion '.$flag.' false stays untested',Scorecard::evaluate($x)['scores']['Fitts']===null);}
$x=$r;$x['sessions'][0]['laws']['Fitts'][1]['criterion_id']='Fitts-1';check688('duplicate criterion refused',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sessions'][0]['laws']['Fitts'][0]['rater_a']=0;$x['sessions'][0]['laws']['Fitts'][0]['rater_b']=0;check688('zero is valid observed rating',Scorecard::evaluate($x)['sessions'][0]['scores']['Fitts']===3.2);
$x=$r;$x['sessions'][0]['laws']['Fitts'][0]['rater_a']=1;check688('large rater difference requires calibration',!Scorecard::evaluate($x)['target_met']);
$x['sessions'][0]['laws']['Fitts'][0]['calibration']=approved();check688('calibration preserves original unequal ratings',Scorecard::evaluate($x)['interrater']['mean_absolute_difference']>0);
$x=$r;$x['raters']=['SAME','SAME'];check688('raters must be distinct',!Scorecard::evaluate($x)['human_validation_complete']);
foreach(['source','consent_reference','recording_reference','build_sha256'] as $field){$x=$r;$x['sessions'][0][$field]='';check688('session '.$field.' required',!Scorecard::evaluate($x)['target_met']);}
$x=$r;$x['sessions'][1]['participant_code']=$x['sessions'][0]['participant_code'];check688('duplicate participant not counted twice',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sessions'][0]['consented_at']='2026-11-05T00:00:00Z';check688('consent must precede collection',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sessions'][0]['tasks'][0]['finished_at']='2026-11-03T00:00:00Z';check688('negative duration refused',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sessions'][0]['tasks'][0]['clicks']=null;check688('missing metric never zero-filled',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sessions'][0]['tasks'][0]['assistance']=1;check688('help contradicting unaided success refused',!Scorecard::evaluate($x)['target_met']);
$x=$r;array_pop($x['sessions'][0]['tasks']);check688('unexecuted task blocks certification',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sample']['beginner']=99;check688('declared sample must match sessions',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sessions'][0]['sus_responses'][0]=null;check688('missing SUS never imputed',!Scorecard::evaluate($x)['target_met']);
check688('SUS standard anchors',Scorecard::sus([5,1,5,1,5,1,5,1,5,1])===100.0&&Scorecard::sus([1,5,1,5,1,5,1,5,1,5])===0.0);
foreach(['ux','qa','product','engineering','clinical'] as $role){$x=$r;unset($x['approvals'][$role]);check688('approval '.$role.' required',Scorecard::evaluate($x)['release_gate']==='NO-GO');}
$x=$r;$x['baseline']=$r;$x['baseline']['build_sha256']=str_repeat('b',64);foreach($x['baseline']['sessions'] as &$s)$s['build_sha256']=str_repeat('b',64);unset($s);check688('complete matched protocol comparison allowed',Scorecard::evaluate($x)['comparison_allowed']===true);
foreach(['protocol_id','tasks_id','profiles_id','conditions_id','fixture_id'] as $key){$y=$x;$y['baseline'][$key]='DIFFERENT';check688('incompatible '.$key.' blocks comparison',Scorecard::evaluate($y)['comparison_allowed']===false);}
$x['baseline']['sessions']=[];check688('missing baseline sessions blocks comparison',Scorecard::evaluate($x)['comparison_allowed']===false);
$x=$r;$x['sessions'][0]['tasks'][0]['clinical_errors']='invalid';check688('malformed errors fail closed',!Scorecard::evaluate($x)['target_met']);
$x=$r;foreach($x['sessions'] as &$s)$s['build_order']='baseline_first';unset($s);check688('uncounterbalanced round cannot certify',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['sessions'][0]['laws']['Fitts'][0]['observed_at']='2026-11-05T00:00:00Z';check688('observation cannot postdate evaluation',!Scorecard::evaluate($x)['target_met']);
$x=$r;$x['baseline']=$r;$x['baseline']['build_sha256']=str_repeat('b',64);foreach($x['baseline']['sessions'] as &$s)$s['build_sha256']=str_repeat('b',64);unset($s);$x['baseline']['sessions'][0]['participant_code']='UNIT-DIFFERENT';check688('unmatched paired cohort cannot compare',Scorecard::evaluate($x)['comparison_allowed']===false);
$x=$r;unset($x['approvals']['clinical']);check688('clinical approval is independent of human completion',Scorecard::evaluate($x)['human_validation_complete']&&Scorecard::evaluate($x)['release_gate']==='NO-GO');
check688('timestamps require timezone',!Scorecard::evaluate(array_replace($r,['evaluated_at'=>'2026-11-04 02:00:00']))['target_met']);
echo json_encode(['issue'=>'NI2-688','unit_test_only'=>true,'no_actual_participants'=>true,'passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
