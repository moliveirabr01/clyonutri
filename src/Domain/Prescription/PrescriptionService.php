<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Prescription;
use PDO;
use DomainException;
use ClyoNutri\Core\Auth\TenantContext;

final class PrescriptionService
{
    private PrescriptionRepository $repo;
    private PrescriptionEligibility $eligibility;
    public function __construct(PDO $db)
    {
        $this->repo=new PrescriptionRepository($db);$this->eligibility=new PrescriptionEligibility($this->repo);
    }
    private function context(int $patient): array
    {
        $clinic=TenantContext::getClinicId();$user=(int)TenantContext::getUserId();
        $actor=$this->eligibility->actor($clinic,$user);$p=$this->eligibility->patient($clinic,$patient);
        return [$clinic,$user,$actor,$p];
    }
    public function overview(int $patient): array
    {
        [$clinic,,$actor,$p]=$this->context($patient);
        $catalog=(new PrescriptionAvailability($this->repo->db))->catalog($clinic);
        return [
            'patient'=>$p,'actor'=>$actor,'catalog'=>$catalog,
            'templates'=>$catalog ? $this->repo->rows('SELECT id,name FROM rx_templates WHERE clinic_id=? AND professional_id=? ORDER BY name LIMIT 100',[$clinic,$actor['professional_id']]) : [],
            'prescriptions'=>$catalog ? $this->repo->rows('SELECT id,status,version_number,created_at,issued_at FROM rx_prescriptions WHERE clinic_id=? AND patient_id=? AND professional_id=? ORDER BY id DESC LIMIT 100',[$clinic,$patient,$actor['professional_id']]) : [],
        ];
    }
    public function detail(int $patient,int $id): array
    {
        [$clinic,$user,$actor]=$this->context($patient);$r=$this->repo->get($clinic,$patient,(int)$actor['professional_id'],$id);
        $this->repo->audit($clinic,$id,'professional',$user,'VIEW');
        unset($r['document_pdf']);
        $r['payload']=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);
        $r['snapshot']=$r['snapshot_json']?json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR):null;
        $r['followups']=$this->repo->rows('SELECT note,created_at FROM rx_followups WHERE clinic_id=? AND prescription_id=? ORDER BY id DESC LIMIT 100',[$clinic,$id]);
        return $r;
    }
    private function payload(array $input): array
    {
        $text=static function($v,int $max):string {
            if (!is_scalar($v)) throw new DomainException('Campo textual inválido.');
            $s=trim((string)$v);if (mb_strlen($s)>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$s)) throw new DomainException('Texto muito longo ou com caracteres inválidos.');return $s;
        };
        $p=[];
        foreach (['form'=>80,'route'=>80,'quantity'=>160,'instructions'=>4000,'clinical_context'=>4000,'population'=>20] as $k=>$len) $p[$k]=$text($input[$k]??'',$len);
        foreach (['frequency_per_day'=>24,'duration_days'=>365] as $k=>$max) {
            $v=$input[$k]??null;if (!is_scalar($v) || !preg_match('/^[1-9]\d{0,2}$/',(string)$v) || (int)$v>$max) throw new DomainException('Frequência ou duração inválida.');$p[$k]=(int)$v;
        }
        foreach (['form','route','quantity','instructions'] as $k) if ($p[$k]==='') throw new DomainException('Preencha forma, via, quantidade e orientações.');
        $p['clinical_review_confirmed']=($input['clinical_review_confirmed']??'')==='1' || ($input['clinical_review_confirmed']??null)===true;
        $p['items']=[];
        if (!is_array($input['items']??null) || count($input['items'])<1 || count($input['items'])>20) throw new DomainException('Inclua de 1 a 20 ativos.');
        foreach ($input['items'] as $i) {
            if (!is_array($i) || !is_scalar($i['ingredient_id']??null) || !ctype_digit((string)$i['ingredient_id'])) throw new DomainException('Ativo inválido.');
            $p['items'][]=['ingredient_id'=>(int)$i['ingredient_id'],'dose'=>$text($i['dose']??'',30)];
        }
        return $p;
    }
    private function links(int $clinic,int $patient,int $professional,array $input): array
    {
        $links=[];
        foreach (['appointment_id'=>'appointments','meal_plan_id'=>'meal_plans'] as $k=>$table) {
            $id=(int)($input[$k]??0);$links[$k]=$id ?: null;
            if (!$id) continue;
            $sql="SELECT id FROM $table WHERE id=? AND clinic_id=? AND patient_id=?";
            $args=[$id,$clinic,$patient];
            if ($k==='appointment_id') {$sql.=' AND professional_id=?';$args[]=$professional;}
            if (!$this->repo->one($sql,$args)) throw new DomainException('Consulta ou plano alimentar fora do contexto do paciente/profissional.');
        }
        return $links;
    }
    public function save(int $patient,array $input,?int $id=null): int
    {
        [$clinic,$user,$actor]=$this->context($patient);$prof=(int)$actor['professional_id'];$payload=$this->payload($input);$links=$this->links($clinic,$patient,$prof,$input);
        // Drafts can be saved while regulation is pending, but unclassified ingredient IDs are never accepted.
        foreach ($payload['items'] as $i) if (!$this->repo->one('SELECT id FROM rx_ingredients WHERE clinic_id=? AND id=?',[$clinic,$i['ingredient_id']])) throw new DomainException('Ativo não cadastrado nesta clínica.');
        return $this->transaction(function()use($clinic,$user,$prof,$patient,$payload,$links,$id,$input){
            $now=gmdate('Y-m-d H:i:s');
            if ($id) {
                $r=$this->repo->get($clinic,$patient,$prof,$id,true);
                if ($r['status']!=='draft') throw new DomainException('Documento em revisão ou assinado é imutável. Crie uma nova versão.');
                if ((int)($input['revision']??0)!==(int)$r['revision']) throw new DomainException('Este rascunho mudou. Recarregue antes de salvar.');
                $this->repo->execute('UPDATE rx_prescriptions SET payload_json=?,appointment_id=?,meal_plan_id=?,revision=revision+1,updated_at=? WHERE id=? AND clinic_id=?',[PrescriptionRepository::json($payload),$links['appointment_id'],$links['meal_plan_id'],$now,$id,$clinic]);
            } else {
                $this->repo->execute('INSERT INTO rx_prescriptions (clinic_id,patient_id,professional_id,appointment_id,meal_plan_id,payload_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[$clinic,$patient,$prof,$links['appointment_id'],$links['meal_plan_id'],PrescriptionRepository::json($payload),$now,$now]);$id=(int)$this->repo->db->lastInsertId();
                $this->repo->execute('UPDATE rx_prescriptions SET root_id=? WHERE id=?',[$id,$id]);
            }
            $this->repo->audit($clinic,$id,'professional',$user,'DRAFT_SAVED');return $id;
        });
    }
    public function review(int $patient,int $id,int $revision): void
    {
        [$clinic,$user,$actor,$p]=$this->context($patient);$prof=(int)$actor['professional_id'];
        $r=$this->repo->get($clinic,$patient,$prof,$id);
        if ($r['status']!=='draft' || (int)$r['revision']!==$revision) throw new DomainException('Rascunho alterado ou já revisado. Recarregue a página.');
        $payload=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);$valid=$this->eligibility->validate($clinic,$actor,$p,$payload);
        $snapshot=['number'=>'RX-'.$clinic.'-'.$r['root_id'],'version'=>(int)$r['version_number'],'prepared_at'=>gmdate('Y-m-d H:i:s'),'patient'=>$p,'professional'=>$actor,'payload'=>$payload,'eligibility'=>$valid];
        // Slow PDF rendering is outside transaction; CAS below rejects a concurrent edit.
        $pdf=PrescriptionDocument::render($snapshot);
        $this->transaction(function()use($clinic,$user,$prof,$patient,$id,$revision,$snapshot,$pdf,$valid){
            $current=$this->repo->get($clinic,$patient,$prof,$id,true);
            if ($current['status']!=='draft' || (int)$current['revision']!==$revision) throw new DomainException('Rascunho mudou durante a prévia. Revise novamente.');
            $this->repo->execute("UPDATE rx_prescriptions SET status='review',snapshot_json=?,document_pdf=?,pdf_sha256=?,policy_id=?,prepared_at=?,revision=revision+1,updated_at=? WHERE id=? AND clinic_id=?",[PrescriptionRepository::json($snapshot),$pdf,hash('sha256',$pdf),$valid['policy']['id'],$snapshot['prepared_at'],$snapshot['prepared_at'],$id,$clinic]);
            $this->repo->audit($clinic,$id,'professional',$user,'REVIEW_PREPARED',['pdf_sha256'=>hash('sha256',$pdf),'policy_id'=>(int)$valid['policy']['id']]);
        });
    }
    public function publish(int $patient,int $id,string $signature,bool $confirmed): void
    {
        if (!$confirmed) throw new DomainException('Confirme a composição e a responsabilidade clínica antes de publicar.');
        [$clinic,$user,$actor,$p]=$this->context($patient);$prof=(int)$actor['professional_id'];
        $r=$this->repo->get($clinic,$patient,$prof,$id);
        if ($r['status']!=='review') throw new DomainException('Prepare e revise o documento antes de assinar.');
        $this->assertPdf($r);$payload=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);
        $valid=$this->eligibility->validate($clinic,$actor,$p,$payload);
        $snapshot=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if (PrescriptionRepository::json($valid)!==PrescriptionRepository::json($snapshot['eligibility']) || PrescriptionRepository::json($actor)!==PrescriptionRepository::json($snapshot['professional']) || PrescriptionRepository::json($p)!==PrescriptionRepository::json($snapshot['patient'])) throw new DomainException('Dados do paciente, profissional ou regras mudaram. Cancele e gere nova versão para revisar.');
        $verified=(new PrescriptionSignature())->verify($r['document_pdf'],$signature,$valid['qualification']['certificate_sha256']);
        $this->transaction(function()use($clinic,$user,$actor,$p,$prof,$patient,$id,$r,$valid,$signature,$verified,$payload){
            $current=$this->repo->get($clinic,$patient,$prof,$id,true);
            if ($current['status']!=='review' || !hash_equals($r['pdf_sha256'],$current['pdf_sha256'])) throw new DomainException('Documento alterado durante a assinatura.');
            // Recheck the active registration and policy immediately before the transition.
            $latestActor=$this->eligibility->actor($clinic,$user);$latestPatient=$this->eligibility->patient($clinic,$patient);
            $latest=$this->eligibility->validate($clinic,$latestActor,$latestPatient,$payload);
            if (PrescriptionRepository::json($actor)!==PrescriptionRepository::json($latestActor) || PrescriptionRepository::json($p)!==PrescriptionRepository::json($latestPatient) || PrescriptionRepository::json($valid)!==PrescriptionRepository::json($latest)) throw new DomainException('Habilitação ou política mudou durante a assinatura.');
            $this->repo->execute('INSERT INTO rx_signatures (clinic_id,prescription_id,signature_der,certificate_sha256,pdf_sha256,qualification_id,verified_at,profile) VALUES (?,?,?,?,?,?,?,?)',[$clinic,$id,$signature,$verified['certificate_sha256'],$r['pdf_sha256'],$valid['qualification']['id'],$verified['verified_at'],$verified['profile']]);
            $now=gmdate('Y-m-d H:i:s');
            $this->repo->execute("UPDATE rx_prescriptions SET status='issued',issued_at=?,retain_until=?,updated_at=? WHERE clinic_id=? AND id=?",[$now,gmdate('Y-m-d',time()+86400*(int)$valid['policy']['retention_days']),$now,$clinic,$id]);
            if ($r['previous_id']) $this->repo->execute("UPDATE rx_prescriptions SET status='superseded',updated_at=? WHERE clinic_id=? AND id=? AND status='issued'",[$now,$clinic,$r['previous_id']]);
            $this->repo->execute("INSERT INTO rx_deliveries (clinic_id,prescription_id,patient_id,channel,action,created_at) VALUES (?,?,?,'portal','published',?)",[$clinic,$id,$patient,$now]);
            $this->repo->audit($clinic,$id,'professional',$user,'ISSUED',['pdf_sha256'=>$r['pdf_sha256'],'qualification_id'=>(int)$valid['qualification']['id']]);
        });
    }
    public function renew(int $patient,int $id): int
    {
        [$clinic,$user,$actor]=$this->context($patient);$prof=(int)$actor['professional_id'];
        return $this->transaction(function()use($clinic,$user,$prof,$patient,$id){
            $r=$this->repo->get($clinic,$patient,$prof,$id,true);
            if (!in_array($r['status'],['issued','cancelled','review'],true)) throw new DomainException('Crie nova versão a partir de uma revisão, emissão ou cancelamento.');
            if ($this->repo->one('SELECT id FROM rx_prescriptions WHERE clinic_id=? AND previous_id=?',[$clinic,$id])) throw new DomainException('Já existe uma nova versão deste documento.');
            $payload=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);$payload['clinical_review_confirmed']=false;
            $now=gmdate('Y-m-d H:i:s');
            $this->repo->execute('INSERT INTO rx_prescriptions (clinic_id,patient_id,professional_id,appointment_id,meal_plan_id,root_id,previous_id,version_number,payload_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$clinic,$patient,$prof,$r['appointment_id'],$r['meal_plan_id'],$r['root_id'],$id,(int)$r['version_number']+1,PrescriptionRepository::json($payload),$now,$now]);$new=(int)$this->repo->db->lastInsertId();
            if ($r['status']==='review') $this->repo->execute("UPDATE rx_prescriptions SET status='cancelled',cancelled_at=?,cancellation_reason='Substituída por nova revisão',updated_at=? WHERE clinic_id=? AND id=?",[$now,$now,$clinic,$id]);
            $this->repo->audit($clinic,$new,'professional',$user,'VERSION_CREATED',['previous_id'=>$id]);return $new;
        });
    }
    public function cancel(int $patient,int $id,string $reason): void
    {
        [$clinic,$user,$actor]=$this->context($patient);
        if (mb_strlen(trim($reason))<5 || mb_strlen($reason)>2000) throw new DomainException('Informe o motivo do cancelamento (5 a 2000 caracteres).');
        $this->transaction(function()use($clinic,$user,$actor,$patient,$id,$reason){
            $r=$this->repo->get($clinic,$patient,(int)$actor['professional_id'],$id,true);
            if (!in_array($r['status'],['draft','review','issued'],true)) throw new DomainException('Documento já cancelado ou substituído.');
            $now=gmdate('Y-m-d H:i:s');$this->repo->execute("UPDATE rx_prescriptions SET status='cancelled',cancelled_at=?,cancellation_reason=?,updated_at=? WHERE clinic_id=? AND id=?",[$now,trim($reason),$now,$clinic,$id]);$this->repo->audit($clinic,$id,'professional',$user,'CANCELLED');
        });
    }
    public function followup(int $patient,int $id,string $note): void
    {
        [$clinic,$user,$actor]=$this->context($patient);
        if (mb_strlen(trim($note))<5 || mb_strlen($note)>4000) throw new DomainException('Registre o acompanhamento em 5 a 4000 caracteres.');
        $this->transaction(function()use($clinic,$user,$actor,$patient,$id,$note){
            $r=$this->repo->get($clinic,$patient,(int)$actor['professional_id'],$id,true);
            if (!$r['issued_at']) throw new DomainException('Acompanhamento disponível após a emissão.');
            $this->repo->execute('INSERT INTO rx_followups (clinic_id,prescription_id,professional_id,note,created_at) VALUES (?,?,?,?,?)',[$clinic,$id,$actor['professional_id'],trim($note),gmdate('Y-m-d H:i:s')]);$this->repo->audit($clinic,$id,'professional',$user,'FOLLOWUP_RECORDED');
        });
    }
    public function saveTemplate(int $patient,int $id,string $name): void
    {
        [$clinic,$user,$actor]=$this->context($patient);
        if (mb_strlen(trim($name))<3 || mb_strlen($name)>160) throw new DomainException('Nome do modelo: 3 a 160 caracteres, sem identificação do paciente.');
        $r=$this->repo->get($clinic,$patient,(int)$actor['professional_id'],$id);
        $payload=json_decode($r['payload_json'],true,512,JSON_THROW_ON_ERROR);
        // Templates keep composition only; clinical review, patient directions and population never carry over.
        $template=array_intersect_key($payload,array_flip(['items','form','route','frequency_per_day','duration_days']));
        $this->transaction(function()use($clinic,$user,$actor,$id,$name,$template){
            $this->repo->execute('INSERT INTO rx_templates (clinic_id,professional_id,name,payload_json,created_at) VALUES (?,?,?,?,?)',[$clinic,$actor['professional_id'],trim($name),PrescriptionRepository::json($template),gmdate('Y-m-d H:i:s')]);
            $this->repo->audit($clinic,$id,'professional',$user,'TEMPLATE_SAVED');
        });
    }
    public function template(int $patient,int $id): array
    {
        [$clinic,,$actor]=$this->context($patient);
        $t=$this->repo->one('SELECT payload_json FROM rx_templates WHERE clinic_id=? AND professional_id=? AND id=?',[$clinic,$actor['professional_id'],$id]);
        if (!$t) throw new DomainException('Modelo não encontrado neste contexto.');
        return json_decode($t['payload_json'],true,512,JSON_THROW_ON_ERROR);
    }
    public function download(int $patient,int $id,bool $signature=false): array
    {
        [$clinic,$user,$actor]=$this->context($patient);$r=$this->repo->get($clinic,$patient,(int)$actor['professional_id'],$id);
        $file=$this->file($r,$signature);$this->repo->audit($clinic,$id,'professional',$user,'DOWNLOAD',['signature'=>$signature]);return $file;
    }
    public function patientList(int $clinic,int $patient): array
    {
        $this->patientIdentity($clinic,$patient);
        if (!(new PrescriptionAvailability($this->repo->db))->available($clinic)) return [];
        return $this->repo->rows("SELECT id,version_number,status,issued_at,cancelled_at FROM rx_prescriptions WHERE clinic_id=? AND patient_id=? AND issued_at IS NOT NULL ORDER BY id DESC LIMIT 100",[$clinic,$patient]);
    }
    public function patientDownload(int $clinic,int $patient,int $id,bool $signature=false): array
    {
        $this->patientIdentity($clinic,$patient);
        $r=$this->repo->one('SELECT * FROM rx_prescriptions WHERE clinic_id=? AND patient_id=? AND id=? AND issued_at IS NOT NULL',[$clinic,$patient,$id]);
        if (!$r) throw new DomainException('Documento não encontrado.');
        $file=$this->file($r,$signature);
        $this->transaction(function()use($clinic,$patient,$id,$signature){
            $this->repo->execute("INSERT INTO rx_deliveries (clinic_id,prescription_id,patient_id,channel,action,created_at) VALUES (?,?,?,'portal','downloaded',?)",[$clinic,$id,$patient,gmdate('Y-m-d H:i:s')]);$this->repo->audit($clinic,$id,'patient',$patient,'DOWNLOAD',['signature'=>$signature]);
        });return $file;
    }
    private function patientIdentity(int $clinic,int $patient): void
    {
        $user=TenantContext::getUser();
        if (($user['role']??'')!=='patient' || (int)TenantContext::getUserId()!==$patient || TenantContext::getClinicId()!==$clinic) throw new DomainException('Acesso restrito ao paciente autenticado.');
        $this->eligibility->patient($clinic,$patient);
    }
    private function file(array $r,bool $signature): array
    {
        $this->assertPdf($r);
        if ($signature) {
            $s=$this->repo->one('SELECT signature_der,pdf_sha256 FROM rx_signatures WHERE clinic_id=? AND prescription_id=?',[$r['clinic_id'],$r['id']]);
            if (!$s || !hash_equals($r['pdf_sha256'],$s['pdf_sha256'])) throw new DomainException('Assinatura indisponível.');
            return ['bytes'=>$s['signature_der'],'filename'=>'prescricao-'.$r['id'].'.p7s','type'=>'application/pkcs7-signature'];
        }
        return ['bytes'=>$r['document_pdf'],'filename'=>'prescricao-'.$r['id'].'.pdf','type'=>'application/pdf'];
    }
    private function assertPdf(array $r): void
    {
        if (!$r['document_pdf'] || !$r['pdf_sha256'] || !hash_equals($r['pdf_sha256'],hash('sha256',$r['document_pdf']))) throw new DomainException('Integridade do PDF não confirmada. Download bloqueado.');
    }
    private function transaction(callable $fn): mixed
    {
        if ($this->repo->db->inTransaction()) throw new \LogicException('Transação externa não permitida.');
        $this->repo->db->beginTransaction();
        try {$result=$fn();$this->repo->db->commit();return $result;}catch(\Throwable $e){if ($this->repo->db->inTransaction()) $this->repo->db->rollBack();throw $e;}
    }
}
