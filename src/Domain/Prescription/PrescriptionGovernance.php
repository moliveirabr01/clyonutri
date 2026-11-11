<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Prescription;
use DomainException;
use PDO;
/** Operator-only import of externally reviewed records. Not exposed to the prescriber over HTTP. */
final class PrescriptionGovernance
{
    public function __construct(private PDO $db) {}
    public function import(array $bundle): void
    {
        $r=new PrescriptionRepository($this->db);$clinic=(int)($bundle['clinic_id']??0);
        if (!$r->one("SELECT id FROM clinics WHERE id=? AND status='active'",[$clinic])) throw new DomainException('Clínica inválida.');
        $this->db->beginTransaction();
        try {
            if (isset($bundle['policy'])) {
                $p=$bundle['policy'];
                foreach (['version','effective_from','effective_until','clinical_reviewer','legal_reviewer','evidence','signature_profile','retention_basis'] as $k) if (empty($p[$k]) || !is_string($p[$k])) throw new DomainException('Política incompleta: '.$k);
                foreach (['effective_from','effective_until'] as $k) { $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$p[$k]);if (!$date || $date->format('Y-m-d')!==$p[$k]) throw new DomainException('Vigência inválida.'); }
                if ($p['effective_until']<$p['effective_from'] || $p['signature_profile']!=='cms_detached') throw new DomainException('Vigência ou perfil de assinatura inválido.');
                if (!isset($p['retention_days']) || !is_int($p['retention_days']) || $p['retention_days']<1 || $p['retention_days']>36500) throw new DomainException('Prazo de retenção precisa de definição jurídica (1 a 36500 dias).');
                if ($p['clinical_reviewer']===$p['legal_reviewer']) throw new DomainException('Registre separadamente as revisões clínica e jurídica.');
                if ($r->one('SELECT id FROM rx_policy_versions WHERE clinic_id=? AND enabled=1 AND effective_from<=? AND effective_until>=?',[$clinic,$p['effective_until'],$p['effective_from']])) throw new DomainException('Vigência sobreposta. Revogue a política anterior explicitamente antes da substituição.');
                $r->execute('INSERT INTO rx_policy_versions (clinic_id,version,effective_from,effective_until,clinical_reviewer,legal_reviewer,evidence,signature_profile,retention_days,retention_basis,enabled,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)',[$clinic,$p['version'],$p['effective_from'],$p['effective_until'],$p['clinical_reviewer'],$p['legal_reviewer'],$p['evidence'],$p['signature_profile'],$p['retention_days'],$p['retention_basis'],gmdate('Y-m-d H:i:s')]);$policy=(int)$this->db->lastInsertId();
                foreach ($bundle['ingredients']??[] as $i) {
                    foreach (['code','name','category','decision','constraints','justification','source_url'] as $k) if (!isset($i[$k])) throw new DomainException('Regra incompleta: '.$k);
                    if (!in_array($i['category'],['supplement','magistral_supplement','phytotherapy','medicine','restricted','unclassified'],true) || !in_array($i['decision'],['allow','deny'],true) || !filter_var($i['source_url'],FILTER_VALIDATE_URL) || !str_starts_with($i['source_url'],'https://') || trim($i['justification'])==='') throw new DomainException('Classificação, justificativa ou fonte inválida.');
                    $c=$i['constraints'];
                    if ($i['decision']==='allow') {
                        if (!in_array($i['category'],['supplement','magistral_supplement','phytotherapy'],true)) throw new DomainException('Medicamentos e substâncias restritas não podem ser liberados.');
                        foreach (['unit','min_daily','max_daily','min_age','max_age','max_days','routes','forms','populations','allowed_combinations','mandatory_instructions'] as $k) if (!array_key_exists($k,$c)) throw new DomainException('Condição obrigatória ausente: '.$k);
                        foreach (['min_daily','max_daily','min_age','max_age','max_days'] as $k) if (!is_numeric($c[$k]) || !is_finite((float)$c[$k]) || $c[$k]<0) throw new DomainException('Limite inválido.');
                        if ($c['min_daily']>$c['max_daily'] || $c['min_age']>$c['max_age'] || $c['max_days']<1 || !$c['unit']) throw new DomainException('Limites inconsistentes.');
                        foreach (['routes','forms','populations','allowed_combinations'] as $k) if (!is_array($c[$k]) || ($k!=='allowed_combinations' && !$c[$k])) throw new DomainException('Lista de condições inválida.');
                    }
                    $old=$r->one('SELECT * FROM rx_ingredients WHERE clinic_id=? AND code=?',[$clinic,$i['code']]);
                    if ($old && ($old['name']!==$i['name'] || $old['category']!==$i['category'])) throw new DomainException('Identidade de ativo imutável. Use novo código para corrigir.');
                    if (!$old) {$r->execute('INSERT INTO rx_ingredients (clinic_id,code,name,category,enabled) VALUES (?,?,?,?,1)',[$clinic,$i['code'],$i['name'],$i['category']]);$ingredient=(int)$this->db->lastInsertId();}else $ingredient=(int)$old['id'];
                    $r->execute('INSERT INTO rx_ingredient_rules (clinic_id,policy_id,ingredient_id,decision,constraints_json,justification,source_url) VALUES (?,?,?,?,?,?,?)',[$clinic,$policy,$ingredient,$i['decision'],PrescriptionRepository::json($c),$i['justification'],$i['source_url']]);
                }
                $r->audit($clinic,null,'operator',0,'POLICY_IMPORTED',['policy_id'=>$policy,'bundle_sha256'=>hash('sha256',PrescriptionRepository::json($bundle))]);
            }
            foreach ($bundle['qualifications']??[] as $q) {
                foreach (['professional_id','crn','crn_region','registration_status','phytotherapy','certificate_sha256','verified_by','evidence','valid_until'] as $k) if (!isset($q[$k])) throw new DomainException('Habilitação incompleta: '.$k);
                $professional=$r->one("SELECT p.id,p.crn,p.crn_region FROM professionals p JOIN clinic_members m ON m.user_id=p.user_id AND m.clinic_id=? AND m.status='active' WHERE p.id=?",[$clinic,$q['professional_id']]);
                if (!$professional || $professional['crn']!==$q['crn'] || $professional['crn_region']!==$q['crn_region'] || !preg_match('/^[a-f0-9]{64}$/',$q['certificate_sha256']) || !in_array($q['registration_status'],['active','inactive','unconfirmed'],true) || !$q['verified_by'] || !$q['evidence']) throw new DomainException('Habilitação não corresponde ao cadastro ou à evidência.');
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$q['valid_until'],new \DateTimeZone('UTC'));
                if (!$date || $date->format('Y-m-d H:i:s')!==$q['valid_until']) throw new DomainException('Validade da habilitação inválida.');
                $r->execute('INSERT INTO rx_qualifications (clinic_id,professional_id,crn,crn_region,registration_status,phytotherapy,certificate_sha256,verified_by,evidence,valid_until,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$clinic,$q['professional_id'],$q['crn'],$q['crn_region'],$q['registration_status'],(int)(bool)$q['phytotherapy'],$q['certificate_sha256'],$q['verified_by'],$q['evidence'],$q['valid_until'],gmdate('Y-m-d H:i:s')]);
                $r->audit($clinic,null,'operator',0,'QUALIFICATION_IMPORTED',['qualification_id'=>(int)$this->db->lastInsertId()]);
            }
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function revoke(int $clinic,string $type,int $id): void
    {
        $r=new PrescriptionRepository($this->db);
        $this->db->beginTransaction();
        try {
            if ($type==='policy') $n=$r->execute('UPDATE rx_policy_versions SET enabled=0 WHERE clinic_id=? AND id=?',[$clinic,$id]);
            elseif ($type==='qualification') $n=$r->execute('UPDATE rx_qualifications SET revoked_at=? WHERE clinic_id=? AND id=?',[gmdate('Y-m-d H:i:s'),$clinic,$id]);
            else throw new DomainException('Tipo inválido.');
            if (!$n) throw new DomainException('Registro não encontrado.');
            $r->audit($clinic,null,'operator',0,'GOVERNANCE_REVOKED',['type'=>$type,'id'=>$id]);
            $this->db->commit();
        } catch (\Throwable $e) {$this->db->rollBack();throw $e;}

    }
}
