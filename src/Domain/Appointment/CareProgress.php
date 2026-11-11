<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Appointment;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\ClinicalProtocol\ClinicProtocolEditor;
use ClyoNutri\Domain\Clinical\WorkflowConflict;
use PDO;

/** Explicit appointment associations; never interprets a same-day document as a completed task. */
final class CareProgress
{
    public const STEPS=['anamnese'=>'Anamnese','avaliacao'=>'Avaliação','plano'=>'Plano alimentar','revisao'=>'Revisão e publicação'];
    public const REASONS=['not_indicated'=>'Não indicada neste atendimento','patient_declined'=>'Paciente optou por não realizar','deferred'=>'Adiada por decisão profissional','existing_record'=>'Registro vigente suficiente','care_type'=>'Conforme o tipo de consulta'];
    public function __construct(private PDO $db){}
    private function row(string $sql,array $args): ?array {$s=$this->db->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
    private function appointment(int $clinic,int $patient,int $id): array
    {
        if($clinic!==TenantContext::getClinicId())throw new \DomainException('Atendimento não encontrado.');
        (new ClinicProtocolEditor($this->db))->authorize($clinic,(int)TenantContext::getUserId());
        $a=$this->row('SELECT a.* FROM appointments a JOIN patients p ON p.id=a.patient_id AND p.clinic_id=a.clinic_id WHERE a.id=? AND a.clinic_id=? AND a.patient_id=?',[$id,$clinic,$patient]);
        if(!$a)throw new \DomainException('Atendimento não encontrado.');
        return $a;
    }
    public function snapshot(int $clinic,int $patient,int $id): array
    {
        $a=$this->appointment($clinic,$patient,$id);$args=[$clinic,$patient];$base='/pacientes/'.$patient;
        $an=$this->row('SELECT id,version,created_at FROM anamneses WHERE clinic_id=? AND patient_id=? AND COALESCE(is_draft,0)=0 ORDER BY version DESC,id DESC LIMIT 1',$args);
        $draft=$this->row('SELECT id,version,created_at FROM anamneses WHERE clinic_id=? AND patient_id=? AND is_draft=1 ORDER BY version DESC,id DESC LIMIT 1',$args);
        $av=$this->row('SELECT id,assessed_at,created_at FROM anthropometric_assessments WHERE clinic_id=? AND patient_id=? ORDER BY COALESCE(assessed_at,created_at) DESC,id DESC LIMIT 1',$args);
        $plan=$this->row("SELECT id,status,updated_at,created_at FROM meal_plans WHERE clinic_id=? AND patient_id=? ORDER BY CASE WHEN status='draft' THEN 0 ELSE 1 END,id DESC LIMIT 1",$args);
        $pub=$this->row("SELECT id,version_number,published_at FROM meal_plan_versions WHERE clinic_id=? AND patient_id=? AND status='published' ORDER BY published_at DESC,id DESC LIMIT 1",$args);
        $evidence=['anamnese'=>$an,'avaliacao'=>$av,'plano'=>$plan,'revisao'=>$pub];
        $urls=['anamnese'=>$base.'/anamnese','avaliacao'=>$base.'/antropometria','plano'=>$plan?'/planos/'.(int)$plan['id']:$base.'/plano','revisao'=>$plan?'/planos/'.(int)$plan['id']:$base.'/plano']; // NI2-711
        foreach($urls as $k=>$u)$urls[$k]=$u.'?atendimento='.$id; // v54.47 Z01: preserva o atendimento ao navegar
        $s=$this->db->prepare('SELECT * FROM care_progress_events WHERE clinic_id=? AND appointment_id=? ORDER BY id');$s->execute([$clinic,$id]);$events=$s->fetchAll(PDO::FETCH_ASSOC);$policy=[];$confirmed=[];
        $template=(new CareWorkflowPolicy($this->db))->read($clinic,(string)$a['type']);
        foreach($template['steps'] as $k=>$v)$policy[$k]=['decision'=>$v,'reason_code'=>''];
        foreach($events as $e){if(in_array($e['decision'],['required','optional','waived'],true))$policy[$e['step_key']]=$e;elseif($e['decision']==='confirm')$confirmed[$e['step_key']]=$e;}
        $steps=[];$done=0;$total=0;$configured=count($policy)===count(self::STEPS);
        foreach(self::STEPS as $key=>$label){
            $ev=$evidence[$key];$hash=$ev?hash('sha256',json_encode($ev,JSON_UNESCAPED_UNICODE)):'';$p=$policy[$key]??null;$waived=($p['decision']??'')==='waived';
            $verified=$ev&&isset($confirmed[$key])&&hash_equals($confirmed[$key]['evidence_sha256'],$hash);
            $drafting=$key==='anamnese'&&$draft&&(!$an||(int)$draft['version']>(int)$an['version']);
            if($drafting)$verified=false;
            // A saved draft is progress, never publication or a completed plan.
            if($key==='plano'&&($plan['status']??'')==='draft')$verified=false;
            $state=$waived?'not_applicable':($verified?'done':(($drafting||($key==='plano'&&($plan['status']??'')==='draft'))?'in_progress':($ev?'review':'pending')));
            $source=$ev?' · Registro #'.(int)$ev['id'].(isset($ev['version'])?' · v'.(int)$ev['version']:'').(isset($ev['version_number'])?' · v'.(int)$ev['version_number']:''):'';
            $detail=$waived?self::REASONS[$p['reason_code']]:($verified?'Registro persistido vinculado a este atendimento':($ev?'Há registro persistido; confirme sua aplicação a este atendimento':'Nenhum registro persistido disponível'));
            if($drafting)$detail='Rascunho v'.(int)$draft['version'].' persistido; a versão concluída permanece vigente';
            if($key==='plano'&&$state==='in_progress')$detail='Plano alimentar em rascunho; continuar edição e revisar antes de publicar';
            $required=($p['decision']??'')==='required';if($required){$total++;if($verified)$done++;}
            $steps[]=['key'=>$key,'label'=>$label,'state'=>$state,'detail'=>$detail.$source,'url'=>$urls[$key],'policy'=>$p['decision']??'unconfigured','evidence'=>$hash,'can_confirm'=>(bool)$ev&&!$drafting&&!($key==='plano'&&($plan['status']??'')==='draft')];
        }
        $next=null;foreach(['in_progress','review','pending'] as $state)foreach($steps as $step)if(!$next&&$step['state']===$state)$next=$step;
        // v54.47 Z01/G01: verbo da próxima ação vem do estado persistido (nunca do navegador).
        if($next)$next['action']=(['in_progress'=>'Continuar','review'=>'Revisar','pending'=>'Registrar'][$next['state']]).' '.mb_strtolower($next['label'],'UTF-8');
        // Última etapa efetivamente persistida (maior data de registro entre as evidências).
        $last=null;$lastAt='';foreach($steps as $st){$ev=$evidence[$st['key']];if($st['key']==='anamnese'&&$draft&&(!$an||(int)$draft['version']>(int)$an['version']))$ev=$draft;$at=$ev?(string)($ev['updated_at']??$ev['published_at']??$ev['assessed_at']??$ev['created_at']??''):'';if($at!==''&&$at>$lastAt){$lastAt=$at;$last=['key'=>$st['key'],'label'=>$st['label'],'at'=>$at];}}
        return ['appointment_id'=>$id,'patient_id'=>$patient,'steps'=>$steps,'next'=>$next,'done'=>$done,'total'=>$total,'remaining_required'=>$total-$done,'last_saved'=>$last,'quantitative'=>$configured&&$total>0,'revision'=>hash('sha256',json_encode([$a['status'],$a['type'],$template['revision'],$events,$evidence,$draft],JSON_UNESCAPED_UNICODE))];
    }
    public function decide(int $clinic,int $patient,int $id,string $step,string $decision,string $reason,string $evidence,string $base,string $key): void
    {
        $this->appointment($clinic,$patient,$id);
        if(!isset(self::STEPS[$step])||!in_array($decision,['required','optional','waived','confirm'],true)||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new \InvalidArgumentException('Decisão inválida.');
        if($decision==='waived'&&!isset(self::REASONS[$reason]))throw new \InvalidArgumentException('Informe o motivo da dispensa.');
        $reason=$decision==='waived'?$reason:'';$evidence=$decision==='confirm'?$evidence:'';
        $this->db->beginTransaction();
        try{
            $lock=$this->db->prepare('SELECT id FROM appointments WHERE id=? AND clinic_id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$lock->execute([$id,$clinic]);$lock->fetchColumn();
            $old=$this->row('SELECT * FROM care_progress_events WHERE clinic_id=? AND appointment_id=? AND request_key=?',[$clinic,$id,$key]);
            if($old){if($old['step_key']!==$step||$old['decision']!==$decision||$old['reason_code']!==$reason||$old['evidence_sha256']!==$evidence||(int)$old['user_id']!==(int)TenantContext::getUserId())throw new WorkflowConflict('Identificador já usado para outra decisão.');$this->db->commit();return;}
            $now=$this->snapshot($clinic,$patient,$id);
            if(!hash_equals($now['revision'],$base))throw new WorkflowConflict('O atendimento mudou. Recarregue e confira antes de decidir.');
            $selected=array_values(array_filter($now['steps'],fn($s)=>$s['key']===$step))[0];
            if($decision==='confirm'&&(!$selected['can_confirm']||!hash_equals($selected['evidence'],$evidence)))throw new \DomainException('Registro não disponível para conclusão.');
            $this->db->prepare('INSERT INTO care_progress_events (clinic_id,appointment_id,user_id,step_key,decision,reason_code,evidence_sha256,request_key,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$clinic,$id,TenantContext::getUserId(),$step,$decision,$reason,$evidence,$key,date('Y-m-d H:i:s')]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
