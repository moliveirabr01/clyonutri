<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Clinical;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\ClinicalProtocol\ClinicProtocolEditor;
use PDO;

/** Optimistic concurrency against actual canonical records; receipts contain no clinical answers. */
final class WorkflowWrites
{
    public function __construct(private PDO $db) {}

    public function state(string $kind,int $id,int $patient=0): array
    {
        $clinic=TenantContext::getClinicId();$user=(int)TenantContext::getUserId();
        (new ClinicProtocolEditor($this->db))->authorize($clinic,$user);
        if($kind==='plan') {
            TenantGuard::assertMealPlanBelongsToClinic($id,$clinic,$this->db);
            $row=$this->one('SELECT * FROM meal_plans WHERE id=? AND clinic_id=?',[$id,$clinic]);
            $meals=$this->all('SELECT * FROM meal_plan_meals WHERE meal_plan_id=? ORDER BY id',[$id]);
            $items=$this->all('SELECT i.* FROM meal_plan_items i JOIN meal_plan_meals m ON m.id=i.meal_id WHERE m.meal_plan_id=? ORDER BY i.id',[$id]);
            $subs=$this->all('SELECT s.* FROM meal_plan_substitutions s JOIN meal_plan_items i ON i.id=s.item_id JOIN meal_plan_meals m ON m.id=i.meal_id WHERE m.meal_plan_id=? ORDER BY s.id',[$id]);
            $data=[$row,$meals,$items,$subs];$status=$row['status']==='draft'?'saved':'published';$url='/planos/'.$id;
        } elseif($kind==='anamnesis') {
            TenantGuard::assertPatientBelongsToClinic($id,$clinic,$this->db);
            $row=$this->one('SELECT * FROM anamneses WHERE patient_id=? AND clinic_id=? ORDER BY version DESC,id DESC LIMIT 1',[$id,$clinic]);
            $data=$row;$status=!$row?'not_started':((int)$row['is_draft']===1?'saved':'completed');$url='/pacientes/'.$id.'/anamnese';
        } elseif($kind==='exam') {
            TenantGuard::assertPatientBelongsToClinic($patient,$clinic,$this->db);
            $row=$id?$this->one('SELECT * FROM exam_requests WHERE id=? AND clinic_id=? AND patient_id=?',[$id,$clinic,$patient]):[];
            if($id&&!$row)throw new \DomainException('Solicitação não encontrada.');
            $data=[$row,$id?$this->all('SELECT * FROM exam_request_items WHERE exam_request_id=? ORDER BY id',[$id]):[]];
            $status=!$row?'not_started':(['draft'=>'saved','review'=>'review','issued'=>'issued','cancelled'=>'completed'][$row['status']]??'not_started');
            $url='/pacientes/'.$patient.'/exames'.($id?'/'.$id.'/editar':'/novo');
        } else throw new \InvalidArgumentException('Recurso inválido.');
        return ['kind'=>$kind,'id'=>$id,'revision'=>hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)), 'state'=>$status,'label'=>WorkflowState::label($status),'saved_at'=>(($sa=$row['updated_at']??$row['created_at']??null)!==null?\ClyoNutri\Core\I18n\LocalTime::local((string)$sa):null), // v54.57 (UX-106)
            'resume_url'=>$url];
    }

    public function write(string $kind,int $id,int $patient,string $key,string $base,array $payload,callable $operation,?callable $beforeCheck=null): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$base))throw new \InvalidArgumentException('Identificador ou revisão de gravação inválidos.');
        $clinic=TenantContext::getClinicId();$user=(int)TenantContext::getUserId();
        $this->state($kind,$id,$patient); // Authorize before probing a receipt.
        $hash=hash('sha256',json_encode([$kind,$id,$patient,$base,$payload],JSON_UNESCAPED_UNICODE));
        $this->db->beginTransaction();
        try {
            if($beforeCheck)$beforeCheck();
            $table=$kind==='plan'?'meal_plans':(($kind==='exam'&&$id>0)?'exam_requests':'patients');$lockId=$kind==='exam'&&$id===0?$patient:$id;
            $st=$this->db->prepare("SELECT id FROM $table WHERE id=? AND clinic_id=?".($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$st->execute([$lockId,$clinic]);
            if(!$st->fetchColumn())throw new \DomainException('Registro não encontrado.');
            $r=$this->one('SELECT * FROM workflow_write_receipts WHERE clinic_id=? AND user_id=? AND request_key=?',[$clinic,$user,$key]);
            if($r){
                if(!hash_equals($r['request_sha256'],$hash))throw new WorkflowConflict('Este identificador já foi usado para outra alteração.');
                $this->db->commit();return json_decode($r['result_json'],true,32,JSON_THROW_ON_ERROR)+['replayed'=>true];
            }
            $current=$this->state($kind,$id,$patient);
            if(!hash_equals($current['revision'],$base))throw new WorkflowConflict('Outra aba salvou alterações. Nada foi gravado; confira a versão salva antes de continuar.');
            $result=$operation();$targetId=(int)($result['resource_id']??$id);
            $state=$this->state($kind,$targetId,$patient);
            $out=['status'=>'saved','workflow'=>$state]+$result;
            $this->db->prepare('INSERT INTO workflow_write_receipts (clinic_id,user_id,request_key,request_sha256,result_json,created_at) VALUES (?,?,?,?,?,?)')->execute([$clinic,$user,$key,$hash,json_encode($out,JSON_UNESCAPED_UNICODE),date('Y-m-d H:i:s')]);
            $this->db->commit();return $out;
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function one(string $sql,array $args): array {$s=$this->db->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:[];}
    private function all(string $sql,array $args): array {$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
}
