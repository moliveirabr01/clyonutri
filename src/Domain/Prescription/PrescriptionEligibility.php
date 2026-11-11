<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Prescription;
use DomainException;

/** No ingredient is inferred as permitted from its category or from another ingredient's rule. */
final class PrescriptionEligibility
{
    public function __construct(private PrescriptionRepository $repo) {}
    public function actor(int $clinic,int $user): array
    {
        $r=$this->repo->one("SELECT p.id AS professional_id,p.crn,p.crn_region,u.name,c.name AS clinic_name,c.phone AS clinic_phone FROM professionals p JOIN users u ON u.id=p.user_id JOIN clinic_members m ON m.user_id=u.id AND m.clinic_id=? JOIN clinics c ON c.id=m.clinic_id WHERE u.id=? AND u.status='active' AND c.status='active' AND m.status='active' AND u.role IN ('nutritionist','owner','admin') AND m.role IN ('nutritionist','owner','admin')",[$clinic,$user]);
        if (!$r) throw new DomainException('Acesso restrito ao profissional clínico ativo desta clínica.');
        return $r;
    }
    public function patient(int $clinic,int $patient): array
    {
        $p=$this->repo->one("SELECT id,name,birth_date,status FROM patients WHERE id=? AND clinic_id=? AND status='active'",[$patient,$clinic]);
        if (!$p) throw new DomainException('Paciente não encontrado ou inativo nesta clínica.');
        return $p;
    }
    public function validate(int $clinic,array $actor,array $patient,array $payload): array
    {
        $today=gmdate('Y-m-d');
        $policies=$this->repo->rows('SELECT * FROM rx_policy_versions WHERE clinic_id=? AND enabled=1 AND effective_from<=? AND effective_until>=? ORDER BY id DESC LIMIT 2',[$clinic,$today,$today]);
        if (count($policies)!==1) throw new DomainException('Emissão bloqueada: política regulatória vigente ausente ou ambígua. Solicite validação ao responsável técnico.');
        $policy=$policies[0];
        if ($policy['signature_profile']!=='cms_detached' || !$policy['clinical_reviewer'] || !$policy['legal_reviewer'] || !$policy['evidence'] || (int)$policy['retention_days']<1 || !$policy['retention_basis']) throw new DomainException('Política sem aprovação clínica/jurídica ou perfil de assinatura suportado.');
        $q=$this->repo->one('SELECT * FROM rx_qualifications WHERE clinic_id=? AND professional_id=? ORDER BY id DESC LIMIT 1',[$clinic,$actor['professional_id']]);
        if (!$q || $q['revoked_at'] || $q['registration_status']!=='active' || $q['valid_until']<gmdate('Y-m-d H:i:s') || $q['crn']!==$actor['crn'] || $q['crn_region']!==$actor['crn_region'] || !$q['verified_by'] || !$q['evidence'] || !preg_match('/^[a-f0-9]{64}$/',$q['certificate_sha256'])) throw new DomainException('Habilitação bloqueada: CRN e certificado precisam de verificação vigente por fonte documentada.');
        \ClyoNutri\Domain\LaboratoryExam\ProfessionalEligibilityValidator::assertCrnFormat($actor['crn'],$actor['crn_region']);
        if (empty($payload['clinical_review_confirmed']) || trim($payload['clinical_context']??'')==='') throw new DomainException('Revise alergias, medicamentos, condições clínicas e interações e registre a avaliação.');
        $dob=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$patient['birth_date']);
        if (!$dob || $dob->format('Y-m-d')!==$patient['birth_date'] || $dob>new \DateTimeImmutable('today',new \DateTimeZone('UTC'))) throw new DomainException('Informe uma data de nascimento válida no cadastro do paciente.');
        $age=$dob->diff(new \DateTimeImmutable('today',new \DateTimeZone('UTC')))->y;
        $population=$payload['population']??'';
        if (!in_array($population,['general','pregnant','lactating'],true)) throw new DomainException('Confirme a situação populacional do paciente.');
        $items=$payload['items']??[];
        if (!$items || count($items)>20) throw new DomainException('Inclua de 1 a 20 ativos classificados.');
        $ids=array_map(static fn($i)=>(int)($i['ingredient_id']??0),$items);sort($ids);
        if (count(array_unique($ids))!==count($ids)) throw new DomainException('Não repita um ativo na composição.');
        $category=null;$resolved=[];
        foreach ($items as $item) {
            $rule=$this->repo->one('SELECT r.*,i.name,i.category FROM rx_ingredient_rules r JOIN rx_ingredients i ON i.id=r.ingredient_id AND i.clinic_id=r.clinic_id WHERE r.clinic_id=? AND r.policy_id=? AND r.ingredient_id=? AND i.enabled=1',[$clinic,$policy['id'],(int)$item['ingredient_id']]);
            if (!$rule || $rule['decision']!=='allow' || !in_array($rule['category'],['supplement','magistral_supplement','phytotherapy'],true) || !$rule['justification'] || !filter_var($rule['source_url'],FILTER_VALIDATE_URL)) throw new DomainException('Ativo não classificado, vedado ou sem fonte normativa aprovada.');
            if ($category!==null && $category!==$rule['category']) throw new DomainException('Categorias diferentes não podem compor a mesma fórmula.');
            $category=$rule['category'];
            if ($category==='phytotherapy' && !(int)$q['phytotherapy']) throw new DomainException('Fitoterapia exige habilitação adicional verificada.');
            $c=json_decode($rule['constraints_json'],true,512,JSON_THROW_ON_ERROR);
            foreach (['unit','min_daily','max_daily','min_age','max_age','max_days','routes','forms','populations','allowed_combinations','mandatory_instructions'] as $key) if (!array_key_exists($key,$c)) throw new DomainException('Regra incompleta; ativo bloqueado.');
            $dose=$item['dose']??'';
            if (!is_scalar($dose) || !preg_match('/^\d{1,8}(?:\.\d{1,6})?$/',(string)$dose) || (float)$dose<=0) throw new DomainException('Dose inválida. Informe o valor por administração, sem unidade no campo numérico.');
            $daily=(float)$dose*(int)$payload['frequency_per_day'];
            if ($age<(int)$c['min_age'] || $age>(int)$c['max_age'] || !in_array($population,$c['populations'],true) || $daily<(float)$c['min_daily'] || $daily>(float)$c['max_daily'] || $payload['duration_days']>(int)$c['max_days'] || !in_array($payload['route'],$c['routes'],true) || !in_array($payload['form'],$c['forms'],true)) throw new DomainException('Composição fora das condições aprovadas de dose diária, população, idade, duração, forma ou via.');
            if (count($ids)>1) {
                $allowed=false;
                foreach ($c['allowed_combinations'] as $combination) {$combination=array_map('intval',$combination);sort($combination);if ($combination===$ids) $allowed=true;}
                if (!$allowed) throw new DomainException('Combinação sem revisão explícita de compatibilidade e segurança.');
            }
            $resolved[]=['ingredient_id'=>(int)$rule['ingredient_id'],'name'=>$rule['name'],'category'=>$category,'dose'=>(string)$dose,'unit'=>$c['unit'],'daily_dose'=>$daily,'rule_id'=>(int)$rule['id'],'constraints'=>$c,'source'=>$rule['source_url'],'justification'=>$rule['justification']];
        }
        return ['policy'=>$policy,'qualification'=>$q,'items'=>$resolved,'age'=>$age];
    }
}
