<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Dietary;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Domain\Clinical\{ClinicalAuthor,AllergenSafetyValidator,WorkflowWrites,WorkflowConflict};
use ClyoNutri\Domain\ClinicalProtocol\{PatientConditionService,ProtocolWorkspace,ProtocolCatalog};
use PDO;

final class DietarySuggestionService
{
    public function __construct(private PDO $db) {}

    private function actor():array
    {
        $clinic=(int)TenantContext::getClinicId();$user=(int)TenantContext::getUserId();
        $professional=ClinicalAuthor::resolve($this->db,$clinic,$user);
        if(!$professional)throw new \DomainException(ClinicalAuthor::SEM_CRN_MSG);
        return [$clinic,$user,$professional];
    }

    public function preview(int $planId):array
    {
        [$clinic]=$this->actor();
        $workflow=(new WorkflowWrites($this->db))->state('plan',$planId);
        $q=$this->db->prepare('SELECT * FROM meal_plans WHERE id=? AND clinic_id=?');
        $q->execute([$planId,$clinic]);$plan=$q->fetch(PDO::FETCH_ASSOC);
        if(!$plan||!$plan['patient_id'])throw new \DomainException('Vincule um paciente ao plano antes de solicitar sugestões.');
        $patient=(int)$plan['patient_id'];
        $enabled=(new ProtocolWorkspace($this->db))->globallyEnabled();
        $conditions=(new PatientConditionService($this->db))->list($clinic,$patient);
        $approved=(new ProtocolCatalog($this->db))->approvedByCode($clinic);
        $q=$this->db->prepare('SELECT p.code FROM clinic_protocol_settings s JOIN clinical_protocols p ON p.id=s.protocol_id WHERE s.clinic_id=? AND s.enabled=1 AND (p.clinic_id IS NULL OR p.clinic_id=?) ORDER BY p.code');
        $q->execute([$clinic,$clinic]);$enabledCodes=$q->fetchAll(PDO::FETCH_COLUMN);
        $active=array_values(array_filter($conditions,static fn($c)=>$c['confirmed_by_user_id']!==null&&in_array($c['status'],['reported','documented'],true)&&(!$c['valid_until']||$c['valid_until']>=date('Y-m-d'))));
        // Renal context is a contraindication to combining generic guidance, even if its own module is disabled.
        $kinds=[];$unmapped=false;$uncertain=false;
        foreach($conditions as $c){
            if($c['confirmed_by_user_id']===null||!in_array($c['status'],PatientConditionService::ACTIVE,true)||($c['valid_until']&&$c['valid_until']<date('Y-m-d')))continue;
            if($c['status']==='suspected')$uncertain=true;
            $code=$c['protocol_code'];
            $kind=$approved[$code]['definition']['dietary_condition']??(isset(DietaryDefinition::CONDITIONS[$code])?$code:null);
            if($kind)$kinds[]=$kind;else $unmapped=true;
        }
        $allergy=(new AllergenSafetyValidator($this->db))->evaluatePatientAllergyState($patient);
        $cards=[];$messages=[];
        if(!$enabled)$messages[]='As sugestões por comorbidade estão desativadas. Solicite a habilitação dos protocolos clínicos.';
        foreach($active as $condition){
            $code=$condition['protocol_code'];$p=$approved[$code]??null;
            if(!$enabled||!$p||!in_array($code,$enabledCodes,true))continue;
            if($p['review_overdue']){$messages[]='O protocolo '.$p['name'].' precisa renovar a revisão clínica.';continue;}
            $def=$p['definition'];
            try{DietaryDefinition::validate($def);}catch(\InvalidArgumentException $e){$messages[]='Um protocolo dietético está inválido e foi ignorado.';continue;}
            foreach($def['dietary_suggestions']??[] as $rule){
                $blocked=[];
                if($unmapped)$blocked[]='Há condição confirmada sem mapeamento dietético revisado. Individualize o conjunto antes de incluir orientações por este fluxo.';
                if($uncertain)$blocked[]='Há condição em investigação. Esclareça o contexto antes de incluir orientações por este fluxo.';
                if(array_intersect($rule['exclude_allergens']??[],$allergy['allergen_groups']??[]))$blocked[]='A orientação conflita com um grupo de alergia ou restrição registrado.';
                if($rule['review_only'])$blocked[]='Exige avaliação individual; não pode ser incluída por este fluxo.';
                if(in_array('renal',$kinds,true))$blocked[]='Há doença renal no contexto. Individualize a conduta com os dados clínicos e a equipe responsável.';
                $conflicts=array_values(array_intersect($rule['exclude_conditions'],$kinds));
                if($conflicts)$blocked[]='Combinação não liberada: '.implode(', ',array_map(fn($k)=>DietaryDefinition::CONDITIONS[$k],$conflicts)).'.';
                if(($def['dietary_condition']??null)==='celiaca'&&$condition['status']!=='documented')$blocked[]='A orientação para doença celíaca exige condição documentada.';
                if($plan['status']!=='draft')$blocked[]='Este plano foi publicado ou arquivado e não pode receber alterações.';
                $id=$p['version_id'].':'.$rule['key'];
                // Same rule can be listed only once even if historical condition rows repeat.
                if(isset($cards[$id]))continue;
                $cards[$id]=['id'=>$id,'condition_id'=>(int)$condition['id'],'condition_status'=>$condition['status'],
                    'protocol_code'=>$code,'protocol_name'=>$p['name'],'version'=>$p['version'],'version_id'=>$p['version_id'],
                    'condition'=>$def['dietary_condition']??null,'rule'=>$rule,'blocked'=>$blocked];
            }
        }
        if(!$cards&&$enabled)$messages[]='Nenhuma sugestão aprovada e habilitada corresponde às condições confirmadas deste paciente. Suspeitas e texto livre não geram orientações.';
        if(count($active)>1)$messages[]='Há múltiplas condições. Revise a compatibilidade do conjunto; o sistema não resolve interações clínicas automaticamente.';
        $q=$this->db->prepare('SELECT id,created_at,user_id FROM dietary_confirmations WHERE clinic_id=? AND plan_id=? ORDER BY id DESC');
        $q->execute([$clinic,$planId]);$history=$q->fetchAll(PDO::FETCH_ASSOC);
        $snapshot=['workflow'=>$workflow['revision'],'conditions'=>$conditions,'approved'=>$approved,'enabled'=>$enabledCodes,
            'global_enabled'=>$enabled,'allergy'=>$allergy,'cards'=>array_values($cards)];
        return ['plan'=>$plan,'workflow'=>$workflow,'context_sha256'=>hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
            'cards'=>array_values($cards),'messages'=>array_values(array_unique($messages)),'allergy'=>$allergy,'active_conditions'=>$active,'history'=>$history];
    }

    /** Explicit clinical confirmation is the only write path. No GET, checkbox default or browser-only safeguard. */
    public function confirm(int $planId,string $requestKey,string $revision,string $context,array $selection,array $texts,string $reason,bool $confirmed):array
    {
        [$clinic,$user,$professional]=$this->actor();
        if(!$confirmed)throw new \InvalidArgumentException('Confirme a revisão clínica antes de incluir orientações.');
        if(!preg_match('/^[a-f0-9]{64}$/D',$context)||!array_is_list($selection)||!$selection||count($selection)>10)throw new \InvalidArgumentException('Selecione sugestões válidas, sem repetição.');
        foreach($selection as $id)if(!is_string($id)||!preg_match('/^[0-9]+:[a-z][a-z0-9_]{1,59}$/D',$id))throw new \InvalidArgumentException('Sugestão inválida.');
        if(count(array_unique($selection))!==count($selection))throw new \InvalidArgumentException('Não repita sugestões na mesma confirmação.');
        $reason=trim($reason);
        if(mb_strlen($reason)<10||mb_strlen($reason)>1500||preg_match('/<[^>]*>|[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$reason))throw new \InvalidArgumentException('Registre a justificativa clínica em texto simples (10 a 1500 caracteres).');
        $final=[];
        foreach($selection as $id){
            $text=$texts[$id]??null;
            if(!is_string($text)||trim($text)===''||mb_strlen($text)>1500||preg_match('/<[^>]*>|[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$text))throw new \InvalidArgumentException('Revise o texto de cada orientação (até 1500 caracteres, sem HTML).');
            $final[$id]=trim($text);
        }
        $payload=['context'=>$context,'selected'=>$selection,'texts'=>$final,'reason'=>$reason,'confirmed'=>true];
        $hint=$this->db->prepare('SELECT patient_id FROM meal_plans WHERE id=? AND clinic_id=?');
        $hint->execute([$planId,$clinic]);$patientHint=(int)$hint->fetchColumn();
        return (new WorkflowWrites($this->db))->write('plan',$planId,0,$requestKey,$revision,$payload,function()use($planId,$clinic,$user,$professional,$requestKey,$context,$selection,$final,$reason){
            // Recheck canonical actor and every condition/version, rather than trusting posted titles or reference URLs.
            $this->actor();$preview=$this->preview($planId);
            if(!hash_equals($preview['context_sha256'],$context))throw new WorkflowConflict('O contexto clínico ou o protocolo mudou. Recarregue e revise as sugestões.');
            if($preview['plan']['status']!=='draft')throw new \DomainException('Somente planos em rascunho podem receber orientações.');
            $patient=(int)$preview['plan']['patient_id'];
            $cards=array_column($preview['cards'],null,'id');$accepted=[];
            foreach($selection as $id){
                if(!isset($cards[$id])||$cards[$id]['blocked'])throw new \DomainException('Uma sugestão não está disponível para este paciente. Nada foi incluído.');
                $accepted[]=$cards[$id]+['confirmed_text'=>$final[$id]];
            }
            $notes=trim((string)$preview['plan']['notes']);
            $append="Orientações revisadas pelo nutricionista:\n".implode("\n",array_map(fn($v)=>'• '.$v,$final));
            $notes=$notes===''?$append:$notes."\n\n".$append;
            if(mb_strlen($notes)>30000)throw new \DomainException('As orientações excedem o tamanho disponível no plano.');
            $q=$this->db->prepare("UPDATE meal_plans SET notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND clinic_id=? AND status='draft'");
            $q->execute([$notes,$planId,$clinic]);
            $this->db->prepare('INSERT INTO dietary_confirmations (clinic_id,patient_id,plan_id,user_id,professional_id,request_key,context_sha256,suggestions_json,rationale,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$clinic,$patient,$planId,$user,$professional,$requestKey,$context,json_encode($accepted,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$reason,gmdate('Y-m-d H:i:s')]);
            $confirmation=(int)$this->db->lastInsertId();
            (new AuditLogger($this->db))->log('DIETARY_SUGGESTIONS_CONFIRMED','meal_plans',(string)$planId,['confirmation_id'=>$confirmation,'count'=>count($accepted),'professional_id'=>$professional]);
            return ['resource_id'=>$planId,'confirmation_id'=>$confirmation,'accepted_count'=>count($accepted)];
        },function()use($patientHint,$clinic){
            // Match publication's patient-before-plan lock order; plan revision revalidates the binding afterwards.
            $patient=$patientHint;
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
                foreach([
                    ['SELECT id FROM patients WHERE id=? AND clinic_id=? FOR UPDATE',[$patient,$clinic]],
                    ['SELECT id FROM patient_conditions WHERE clinic_id=? AND patient_id=? FOR UPDATE',[$clinic,$patient]],
                    ['SELECT v.id FROM clinical_protocol_versions v JOIN clinical_protocols p ON p.id=v.protocol_id WHERE (p.clinic_id IS NULL OR p.clinic_id=?) FOR UPDATE',[$clinic]],
                    ['SELECT id FROM clinic_protocol_settings WHERE clinic_id=? FOR UPDATE',[$clinic]],
                    ["SELECT setting_key FROM platform_settings WHERE setting_key='feature_flag_clinical_protocols' FOR UPDATE",[]],
                ] as [$sql,$args]){$s=$this->db->prepare($sql);$s->execute($args);$s->fetchAll();}
            }
            \ClyoNutri\Core\Settings\PlatformSettings::clearCache();
        });
    }
}
