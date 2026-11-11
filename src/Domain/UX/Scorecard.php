<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\UX;

/** Offline research only. Evidence and sign-offs still require human review. No clinical data. */
final class Scorecard
{
    public const LAWS=['Fitts','Hick','Zeigarnik','Jakob','Gradiente','Von Restorff','Miller'];
    public const PROFILES=['beginner','experienced','patient'];
    public const TASKS=['beginner'=>['T1','T2','T3','T4','T5','T6','T7','T8'],'experienced'=>['T1','T2','T3','T4','T5','T6','T7','T8'],'patient'=>['P1','P2','P3']];
    public const MINIMUM_PER_PROFILE=5; // Formative round; not statistical certification.

    private static function text(mixed $v): bool {return is_string($v)&&trim($v)!=='';}
    private static function date(mixed $v): bool
    {
        if(!self::text($v)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D',$v))return false;
        try{$date=new \DateTimeImmutable($v);$errors=\DateTimeImmutable::getLastErrors();return $errors===false||($errors['warning_count']===0&&$errors['error_count']===0);}catch(\Exception){return false;}
    }
    private static function approval(mixed $v): bool
    {
        return is_array($v)&&($v['approved']??false)===true&&self::text($v['reviewer']??null)&&self::date($v['at']??null)&&self::text($v['evidence']??null);
    }
    public static function sus(mixed $responses): ?float
    {
        if(!is_array($responses)||!array_is_list($responses)||count($responses)!==10)return null;
        $sum=0;foreach($responses as $i=>$v){if(!is_int($v)||$v<1||$v>5)return null;$sum+=($i%2===0?$v-1:5-$v);}
        return $sum*2.5;
    }
    public static function evaluate(array $input): array
    {
        $issues=[];$scores=array_fill_keys(self::LAWS,null);$profiles=[];$sample=array_fill_keys(self::PROFILES,0);$seen=[];$pairs=[];$sessions=[];$observedClinicalBlock=false;
        foreach(self::PROFILES as $profile)$profiles[$profile]=['sample'=>0,'scores'=>array_fill_keys(self::LAWS,null),'sus'=>[],'tasks'=>[]];
        $metadata=true;
        foreach(['protocol_id','tasks_id','profiles_id','conditions_id','fixture_id','build','environment','browser'] as $k)if(!self::text($input[$k]??null))$metadata=false;
        if(($input['study_design']??null)!=='paired_counterbalanced')$metadata=false;
        if(($input['schema_version']??null)!==2||!self::date($input['evaluated_at']??null)||!is_string($input['build_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['build_sha256']))$metadata=false;
        if(!$metadata)$issues[]='Metadados da rodada incompletos ou formato legado; refazer a coleta no protocolo versionado.';
        $raters=$input['raters']??[];
        $ratersOk=is_array($raters)&&array_is_list($raters)&&count($raters)===2&&self::text($raters[0]??null)&&self::text($raters[1]??null)&&$raters[0]!==$raters[1];
        if(!$ratersOk)$issues[]='Dois avaliadores distintos precisam ser identificados.';
        $raw=$input['sessions']??[];
        if(!is_array($raw)||!array_is_list($raw)){$raw=[];$issues[]='Lista de sessões inválida.';}
        foreach($raw as $index=>$s){
            if(!is_array($s)){$issues[]='Ficha inválida na posição '.$index;continue;}
            $profile=$s['profile']??null;$code=$s['participant_code']??null;
            $ok=in_array($profile,self::PROFILES,true)&&is_string($code)&&preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$code)&&!isset($seen[$code]);
            if(is_string($code))$seen[$code]=true;
            $ok=$ok&&($s['source']??null)==='human'&&($s['synthetic_data_only']??false)===true&&self::text($s['consent_reference']??null)&&self::text($s['recording_reference']??null)&&self::date($s['consented_at']??null)&&self::date($s['started_at']??null);
            if($ok&&strcmp($s['consented_at'],$s['started_at'])!==0)$ok=strtotime($s['consented_at'])<=strtotime($s['started_at']);
            foreach(['protocol_id','build','build_sha256','conditions_id','fixture_id'] as $k)if(($s[$k]??null)!==($input[$k]??null))$ok=false;
            if(!in_array($s['build_order']??null,['baseline_first','candidate_first'],true)||!self::text($s['device']??null)||!self::text($s['browser']??null)||!self::text($s['limitations']??null))$ok=false;
            $taskRows=[];$taskIds=[];$tasks=$s['tasks']??[];
            if(!is_array($tasks)||!array_is_list($tasks)){$tasks=[];$ok=false;}
            foreach($tasks as $t){
                if(!is_array($t)){$ok=false;continue;}
                $id=$t['task_id']??null;$valid=is_string($id)&&!in_array($id,$taskIds,true)&&in_array($id,self::TASKS[$profile]??[],true);
                $taskIds[]=$id;
                $valid=$valid&&($t['executed']??false)===true&&is_bool($t['completed_without_help']??null)&&in_array($t['outcome']??null,['completed','failed','aborted'],true)&&self::date($t['started_at']??null)&&self::date($t['finished_at']??null);
                if($valid)$valid=strtotime($t['finished_at'])>=strtotime($t['started_at'])&&strtotime($t['started_at'])>=strtotime($s['started_at']);
                foreach(['clicks','input_change_events','document_reloads','assistance','use_errors'] as $k)if(!is_int($t[$k]??null)||$t[$k]<0)$valid=false;
                if(($t['completed_without_help']??false)===true&&(($t['outcome']??null)!=='completed'||($t['assistance']??null)!==0))$valid=false;
                if(($t['clinical_errors_reviewed']??false)!==true||!self::text($t['evidence_reference']??null)||!is_array($t['clinical_errors']??null))$valid=false;
                foreach((is_array($t['clinical_errors']??null)?$t['clinical_errors']:[]) as $error){
                    if(is_array($error)&&in_array($error['severity']??null,['P0','P1'],true))$observedClinicalBlock=true;
                    if(!is_array($error)||!in_array($error['severity']??null,['P0','P1','P2','P3'],true)||!self::text($error['evidence']??null))$valid=false;
                }
                if(!$valid)$ok=false;
                if($valid)$taskRows[]=['task_id'=>$id,'outcome'=>$t['outcome'],'completed_without_help'=>$t['completed_without_help'],'seconds'=>strtotime($t['finished_at'])-strtotime($t['started_at']),'clicks'=>$t['clicks'],'input_change_events'=>$t['input_change_events'],'document_reloads'=>$t['document_reloads'],'assistance'=>$t['assistance'],'use_errors'=>$t['use_errors'],'clinical_errors'=>$t['clinical_errors']];
            }
            $expected=self::TASKS[$profile]??[];sort($taskIds);sort($expected);if($taskIds!==$expected)$ok=false;
            $lawValues=[];$localPairs=[];
            foreach(self::LAWS as $law){
                $items=$s['laws'][$law]??[];$valid=is_array($items)&&array_is_list($items)&&count($items)===5;$ratings=[];
                foreach(is_array($items)?$items:[] as $i=>$item){
                    if(!is_array($item)){$valid=false;continue;}
                    $a=$item['rater_a']??null;$b=$item['rater_b']??null;
                    if(($item['criterion_id']??null)!==$law.'-'.($i+1)||($item['executed']??false)!==true||($item['applicable']??true)!==true||!is_int($a)||!is_int($b)||$a<0||$a>5||$b<0||$b>5||!self::text($item['evidence']??null)||!self::date($item['observed_at']??null)){
                        $valid=false;continue;
                    }
                    if(strtotime($item['observed_at'])<strtotime($s['started_at']??'')||strtotime($item['observed_at'])>strtotime($input['evaluated_at']??''))$valid=false;
                    $ratings[]=($a+$b)/2;$localPairs[]=abs($a-$b);
                    if(abs($a-$b)>1&&!self::approval($item['calibration']??null))$valid=false;
                }
                $lawValues[$law]=$valid?array_sum($ratings)/5:null;
                if(!$valid)$ok=false;
            }
            $sus=self::sus($s['sus_responses']??null);if($sus===null)$ok=false;
            $sessions[]=['session_index'=>$index,'participant_code'=>$code,'build_order'=>$s['build_order']??null,'profile'=>in_array($profile,self::PROFILES,true)?$profile:null,'valid'=>$ok,'scores'=>$ok?$lawValues:array_fill_keys(self::LAWS,null),'sus'=>$ok?$sus:null];
            if(!$ok){$issues[]='Sessão '.$index.': incompleta, duplicada, automatizada ou incompatível; sem nota validada.';continue;}
            $sample[$profile]++;$pairs=array_merge($pairs,$localPairs);$profiles[$profile]['sus'][]=$sus;
            foreach($taskRows as $t)$profiles[$profile]['tasks'][$t['task_id']][]=$t;
        }
        foreach(self::PROFILES as $p){
            $profiles[$p]['sample']=$sample[$p];
            foreach(self::LAWS as $law){
                $values=array_column(array_filter($sessions,fn($s)=>$s['valid']&&$s['profile']===$p),'scores');
                $profiles[$p]['scores'][$law]=$values?array_sum(array_column($values,$law))/count($values):null;
            }
        }
        $sampleOk=true;foreach(self::PROFILES as $p)if($sample[$p]<self::MINIMUM_PER_PROFILE||($input['sample'][$p]??null)!==$sample[$p])$sampleOk=false;
        foreach(self::PROFILES as $p){
            $orders=array_column(array_filter($sessions,fn($s)=>$s['valid']&&$s['profile']===$p),'build_order');
            if(!in_array('baseline_first',$orders,true)||!in_array('candidate_first',$orders,true))$sampleOk=false;
        }
        $allSessions=$raw!==[]&&count(array_filter($sessions,fn($s)=>$s['valid']))===count($raw);
        if(!$sampleOk)$issues[]='Amostra válida não coincide com a declarada ou não atinge cinco por perfil.';
        if($allSessions)foreach(self::LAWS as $law){
            $values=array_map(fn($p)=>$profiles[$p]['scores'][$law],self::PROFILES);
            // Conservative minimum across strata; never hide a weak profile in an overall average.
            if(!in_array(null,$values,true))$scores[$law]=min($values);
        }
        $approvals=$input['approvals']??[];$reviews=true;
        foreach(['ux','qa','product','engineering'] as $role)if(!self::approval($approvals[$role]??null))$reviews=false;
        $clinical=!$observedClinicalBlock&&self::approval($approvals['clinical']??null)&&($input['clinical_go']??false)===true&&($input['p0_count']??null)===0&&($input['p1_clinical_count']??null)===0;
        foreach($profiles as $p)foreach($p['tasks'] as $tasks)foreach($tasks as $t)foreach($t['clinical_errors'] as $error)if(in_array($error['severity'],['P0','P1'],true))$clinical=false;
        $human=$metadata&&$ratersOk&&$sampleOk&&$allSessions&&$reviews&&self::approval($input['accessibility_review']??null)&&($input['evidence_reviewed']??false)===true;
        $target=$human&&!in_array(null,$scores,true)&&min($scores)>=4;
        $comparable=null;
        if(array_key_exists('baseline',$input)){
            $base=$input['baseline'];$comparable=is_array($base);
            if($comparable){
                unset($base['baseline']);$result=self::evaluate($base);
                $comparable=$human&&$result['human_validation_complete'];
                foreach(['protocol_id','tasks_id','profiles_id','conditions_id','fixture_id','study_design'] as $k)if(($base[$k]??null)!==($input[$k]??null))$comparable=false;
                $cohort=fn($rows)=>array_map(fn($s)=>[$s['participant_code'],$s['profile'],$s['build_order']],$rows);
                $a=$cohort($sessions);$b=$cohort($result['sessions']);sort($a);sort($b);if($a!==$b)$comparable=false;
                if(($base['build_sha256']??null)===($input['build_sha256']??null)||$result['sample']!==$sample)$comparable=false;
            }
        }
        return ['build'=>$input['build']??null,'scores'=>$scores,'score_rule'=>'Minimum of profile means; five equally weighted criteria; no imputation or N/A denominator reduction','profiles'=>$profiles,'sample'=>$sample,'sessions'=>$sessions,'untested_label'=>'não testado','target_met'=>$target,'clinical_gate'=>$clinical?'GO':'NO-GO','release_gate'=>$target&&$clinical?'GO':'NO-GO','human_validation_complete'=>$human,'comparison_allowed'=>$comparable,'interrater'=>['paired_criteria'=>count($pairs),'mean_absolute_difference'=>$pairs?array_sum($pairs)/count($pairs):null,'exact_agreement'=>$pairs?count(array_filter($pairs,fn($d)=>$d===0))/count($pairs):null],'validation_issues'=>$issues,'limitations'=>$input['limitations']??['Coleta humana não informada'],'certification'=>'Research decision support only; evidence authenticity and sign-offs require human review.'];
    }
}
