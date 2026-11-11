<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\UX;
use ClyoNutri\Core\Release\BuildIdentity;

/** Complements Scorecard and the existing homologation gate; never replaces either. */
final class AcceptanceGate
{
    public const EVIDENCE = ['integrity','accessibility','reflow','journey_A','journey_B','journey_C','journey_D','journey_E','journey_F','assistive_physical','hostgator_rollback'];
    public const ROLES = ['ux','qa','product','engineering','clinical','security','privacy'];

    public static function evaluate(array $input,string $root,string $evidenceRoot): array
    {
        $build=BuildIdentity::inspect($root);$blocking=[];
        $block=static function(string $id,string $reason)use(&$blocking):void{$blocking[]=['id'=>$id,'reason'=>$reason];};
        if($build['integrity']!=='consistent')$block('build','Installed artifact is unverified');
        if(($input['build_id']??null)!==$build['build_id']||($input['content_sha256']??null)!==$build['content_sha256'])$block('binding','Acceptance belongs to another or unspecified build');
        foreach(self::EVIDENCE as $id){
            $record=$input['evidence'][$id]??[];
            if(!self::receipt($record,$build,$evidenceRoot))$block($id,'Missing, unexecuted, stale or unverifiable evidence');
        }
        foreach(self::ROLES as $role){
            $record=$input['approvals'][$role]??[];
            if(!self::receipt($record,$build,$evidenceRoot)||!self::nonempty($record['reviewer']??null))$block('approval_'.$role,'Explicit build-bound approval and reviewer required');
        }
        // Counts must be reviewed, explicit and numeric. Absence is never inferred zero.
        $clinical=$input['clinical_review']??[];
        if(($clinical['reviewed']??null)!==true||($clinical['p0_open']??null)!==0||($clinical['p1_clinical_open']??null)!==0
            ||!self::receipt($clinical,$build,$evidenceRoot)||!self::nonempty($clinical['reviewer']??null))$block('clinical_review','Reviewed zero open clinical P0/P1 required');
        try{
            $round=$input['human_round']??[];
            $scorecard=Scorecard::evaluate($round);
            if(($round['build']??null)!==$build['build_id']||($round['build_sha256']??null)!==$build['content_sha256']||($scorecard['release_gate']??null)!=='GO')$block('human_round','Existing human scorecard is incomplete, stale or NO-GO');
        }catch(\Throwable $e){$block('human_round','Invalid human scorecard');$scorecard=null;}
        require_once $root.'/bin/homologation/GateReport.php';
        $official=$input['homologation']??[];
        $decision=\GateReport::decide($official['checks']??[],'web');
        // The official gate also needs source custody: no commit can be inferred from ZIP files.
        if(($decision['decision']??null)!=='APPROVED'||!preg_match('/^[a-f0-9]{40}$/',(string)($official['commit']??''))
            ||!self::receipt($official,$build,$evidenceRoot))$block('homologation','Existing web gate, source trace and target homologation receipt required');
        return ['schema'=>1,'issue'=>'NI2-695','build'=>$build,'decision'=>$blocking?'NO-GO':'GO','blocking'=>$blocking,
            'human_scores'=>$scorecard['scores']??null,'homologation_decision'=>$decision['decision'],
            'note'=>'Technical evidence does not substitute human research, assistive review or independent approvals. Receipts require review and custody outside this local checker.'];
    }

    private static function nonempty(mixed $v):bool{return is_string($v)&&trim($v)!=='';}
    private static function receipt(array $r,array $build,string $base):bool
    {
        if(($r['status']??null)!=='PASS'||($r['executed']??null)!==true||($r['build_id']??null)!==$build['build_id']
            ||($r['content_sha256']??null)!==$build['content_sha256']||!self::nonempty($r['at_utc']??null)
            ||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/',$r['at_utc']))return false;
        $time=strtotime($r['at_utc']);$built=strtotime($build['built_at_utc']??'');
        if($time===false||$built===false||$time<$built||$time>time()+300)return false;
        $path=$r['path']??null;
        if(!is_string($path)||str_contains($path,'\\')||str_starts_with($path,'/')||preg_match('~(^|/)\.\.?(/|$)~',$path)||!self::nonempty($path))return false;
        $base=realpath($base);$full=$base?realpath($base.'/'.$path):false;
        if(!$base||!$full||!str_starts_with($full,$base.DIRECTORY_SEPARATOR)||!is_file($full)||is_link($base.'/'.$path))return false;
        $hash=$r['sha256']??null;
        return is_string($hash)&&preg_match('/^[a-f0-9]{64}$/',$hash)===1&&hash_equals($hash,hash_file('sha256',$full));
    }
}
