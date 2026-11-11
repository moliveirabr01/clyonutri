<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Appointment;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\ClinicalProtocol\ClinicProtocolEditor;
use ClyoNutri\Domain\Clinical\WorkflowConflict;
use PDO;
final class CareWorkflowPolicy
{
    public const TYPES=['first_visit'=>'Primeira consulta','follow_up'=>'Acompanhamento','return'=>'Retorno'];
    public function __construct(private PDO $db){}
    public function read(int $clinic,string $type): array
    {
        if($clinic!==TenantContext::getClinicId())throw new \DomainException('Clínica não autorizada.');
        (new ClinicProtocolEditor($this->db))->authorize($clinic,(int)TenantContext::getUserId());
        $s=$this->db->prepare('SELECT id,policy_json FROM care_workflow_policies WHERE clinic_id=? AND consultation_type=? ORDER BY id DESC LIMIT 1');$s->execute([$clinic,$type]);$row=$s->fetch(PDO::FETCH_ASSOC);
        return ['steps'=>$row?json_decode($row['policy_json'],true,16,JSON_THROW_ON_ERROR):[],'revision'=>hash('sha256',json_encode($row?:[]))];
    }
    public function save(int $clinic,string $type,array $steps,string $revision,string $key): void
    {
        $user=(int)TenantContext::getUserId();$this->read($clinic,$type);
        $s=$this->db->prepare("SELECT 1 FROM clinic_members WHERE clinic_id=? AND user_id=? AND status='active' AND role IN ('owner','admin')");$s->execute([$clinic,$user]);if(!$s->fetchColumn())throw new \DomainException('Apenas a gestão pode definir padrões.');
        if(!isset(self::TYPES[$type])||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key)||count($steps)!==4)throw new \InvalidArgumentException('Política inválida.');
        $canonical=[];foreach(CareProgress::STEPS as $k=>$label){if(!in_array($steps[$k]??null,['required','optional'],true))throw new \InvalidArgumentException('Defina as quatro etapas.');$canonical[$k]=$steps[$k];}
        $json=json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT id FROM clinics WHERE id=?'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$s->execute([$clinic]);$s->fetchColumn();
            $s=$this->db->prepare('SELECT * FROM care_workflow_policies WHERE clinic_id=? AND request_key=?');$s->execute([$clinic,$key]);$old=$s->fetch(PDO::FETCH_ASSOC);
            if($old){if($old['consultation_type']!==$type||$old['policy_json']!==$json||(int)$old['user_id']!==$user)throw new WorkflowConflict('Identificador já utilizado.');$this->db->commit();return;}
            if(!hash_equals($this->read($clinic,$type)['revision'],$revision))throw new WorkflowConflict('A política mudou. Confira antes de salvar.');
            $this->db->prepare('INSERT INTO care_workflow_policies (clinic_id,user_id,consultation_type,policy_json,request_key,created_at) VALUES (?,?,?,?,?,?)')->execute([$clinic,$user,$type,$json,$key,date('Y-m-d H:i:s')]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
