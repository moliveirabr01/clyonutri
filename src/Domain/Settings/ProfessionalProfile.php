<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Settings;
use ClyoNutri\Core\Auth\TenantContext;
use PDO;
final class ProfessionalProfile
{
    public function __construct(private PDO $db) {}
    public function read(bool $lock = false): array
    {
        $query = "SELECT u.name, u.email, u.phone, COALESCE(op.practice_preferences, '{}') AS practice_preferences, p.specialty, p.bio, p.crn, p.crn_region, c.name AS clinic_name, c.email AS clinic_email, c.document AS clinic_document, c.phone AS clinic_phone,
            c.address_street AS clinic_address_street, c.address_number AS clinic_address_number,
            c.address_complement AS clinic_address_complement, c.address_neighborhood AS clinic_address_neighborhood,
            c.address_city AS clinic_address_city, c.address_state AS clinic_address_state, c.address_zip AS clinic_address_zip,
            cm.role AS member_role
            FROM users u JOIN professionals p ON p.user_id=u.id
            JOIN clinic_members cm ON cm.user_id=u.id JOIN clinics c ON c.id=cm.clinic_id
            LEFT JOIN onboarding_progress op ON op.user_id=u.id AND op.clinic_id=cm.clinic_id
            WHERE u.id=? AND cm.clinic_id=? AND u.role='nutritionist' AND u.status='active'
            AND cm.status='active' AND c.status='active' AND cm.role IN ('owner','admin','nutritionist')";
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $query .= ' FOR UPDATE';
        $stmt=$this->db->prepare($query);$stmt->execute([TenantContext::getUserId(),TenantContext::getClinicId()]);
        $row=$stmt->fetch();if(!$row) throw new \DomainException('Acesso ao perfil não autorizado.');
        return $row;
    }
    public static function revision(array $profile): string { return hash('sha256',json_encode($profile,JSON_THROW_ON_ERROR)); }
    public function save(array $input): void
    {
        $this->db->beginTransaction();
        try {
            $current=$this->read(true);
            if (!is_string($input['revision']??null)||!hash_equals(self::revision($current),$input['revision'])) throw new \DomainException('O perfil mudou em outra aba. Recarregue e confira os valores atuais.');
            $data=[];foreach(['name'=>120,'specialty'=>100,'bio'=>2000,'clinic_name'=>150,'clinic_document'=>20] as $field=>$max){
                $value=$input[$field]??($current[$field]??null);
                if($value===null&&$field==='clinic_document')$value='';
                if(!is_scalar($value)) throw new \InvalidArgumentException('Confira os campos do perfil.');
                $data[$field]=trim((string)$value);if(mb_strlen($data[$field])>$max) throw new \InvalidArgumentException('O campo '.$field.' excede o limite permitido.');
            }
            if($data['name']===''||$data['clinic_name']==='') throw new \InvalidArgumentException('Informe nome e nome do consultório.');
            $canManageClinic=in_array($current['member_role'],['owner','admin'],true);
            if(!in_array(strlen(preg_replace('/\D/','',$data['clinic_document'])),[11,14],true))throw new \DomainException('Informe o CPF (11 dígitos) ou CNPJ (14 dígitos) em Configurações → Perfil → Dados do consultório para cobrança. Ele é necessário para a assinatura e a nota fiscal.');
            if($data['clinic_name']!==$current['clinic_name']&&!$canManageClinic) throw new \InvalidArgumentException('Somente a gestão pode alterar o nome do consultório.');
            $clinicFields=['clinic_email'=>255,'clinic_document'=>20,'clinic_phone'=>30,'clinic_address_street'=>255,'clinic_address_number'=>30,'clinic_address_complement'=>100,'clinic_address_neighborhood'=>100,'clinic_address_city'=>100,'clinic_address_state'=>2,'clinic_address_zip'=>20];
            foreach($clinicFields as $field=>$max){
                $value=$input[$field]??$current[$field]??'';
                if(!is_scalar($value)||mb_strlen(trim((string)$value))>$max)throw new \InvalidArgumentException('Confira os dados do consultório para cobrança.');
                $data[$field]=trim((string)$value);
                if($data[$field]!==(string)($current[$field]??'')&&!$canManageClinic)throw new \DomainException('Somente a gestão do consultório pode alterar os dados de cobrança.');
            }
            if($data['clinic_email']!==''&&!filter_var($data['clinic_email'],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Informe um e-mail válido para o consultório.');
            $document=preg_replace('/\D/','',$data['clinic_document']);
            if(!in_array(strlen($document),[11,14],true))throw new \DomainException('Informe o CPF (11 dígitos) ou CNPJ (14 dígitos) em Configurações → Perfil → Dados do consultório para cobrança. Ele é necessário para a assinatura e a nota fiscal.');
            $data['clinic_document']=$document;
            if($data['clinic_phone']!==''&&(strlen(preg_replace('/\D/','',$data['clinic_phone']))<10||strlen(preg_replace('/\D/','',$data['clinic_phone']))>15||!preg_match('/^[0-9+(). \-]{10,30}$/D',$data['clinic_phone'])))throw new \InvalidArgumentException('Informe um telefone válido para o consultório, com DDD.');
            $zip=preg_replace('/\D/','',$data['clinic_address_zip']);
            if($zip!==''&&strlen($zip)!==8)throw new \InvalidArgumentException('Informe um CEP válido, com 8 dígitos.');
            $data['clinic_address_zip']=$zip;
            $data['clinic_address_state']=strtoupper($data['clinic_address_state']);
            if($data['clinic_address_state']!==''&&!preg_match('/^[A-Z]{2}$/D',$data['clinic_address_state']))throw new \InvalidArgumentException('Informe a UF do consultório com duas letras.');
            foreach(['email'=>255,'phone'=>30,'crn'=>20,'crn_region'=>10] as $field=>$max){
                $value=$input[$field]??$current[$field]??'';
                if(!is_scalar($value)||mb_strlen((string)$value)>$max)throw new \InvalidArgumentException('Confira e-mail, celular e identificação profissional.');
                $data[$field]=trim((string)$value);
            }
            $data['email']=strtolower($data['email']);
            if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Informe um e-mail válido.');
            if($data['phone']!==''&&(!preg_match('/^[0-9+(). \-]{8,30}$/D',$data['phone'])||strlen(preg_replace('/\D/','',$data['phone']))<8||strlen(preg_replace('/\D/','',$data['phone']))>15))throw new \InvalidArgumentException('Informe um celular válido.');
            if($data['crn']===''||$data['crn_region']==='')throw new \InvalidArgumentException('Informe o registro e a região do CRN.');
            if($data['email']!==strtolower($current['email'])){
                $this->verifyPassword($input['current_password']??'');
                $check=$this->db->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id<>?');$check->execute([$data['email'],TenantContext::getUserId()]);
                if($check->fetchColumn())throw new \InvalidArgumentException('Não foi possível utilizar este e-mail. Informe outro.');
                $this->db->prepare('DELETE FROM password_resets WHERE LOWER(email)=LOWER(?)')->execute([$current['email']]);
            }
            $changedIdentity=$data['email']!==strtolower($current['email'])||$data['crn']!==$current['crn']||$data['crn_region']!==$current['crn_region'];
            $this->db->prepare('UPDATE users SET name=?,email=?,phone=?,is_verified=CASE WHEN ?=1 THEN 0 ELSE is_verified END,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$data['name'],$data['email'],$data['phone'],(int)$changedIdentity,TenantContext::getUserId()]);
            $this->db->prepare('UPDATE professionals SET specialty=?,bio=?,crn=?,crn_region=?,updated_at=CURRENT_TIMESTAMP WHERE user_id=?')->execute([$data['specialty'],$data['bio'],$data['crn'],$data['crn_region'],TenantContext::getUserId()]);
            $this->db->prepare('UPDATE clinics SET name=?,email=?,document=?,phone=?,address_street=?,address_number=?,address_complement=?,address_neighborhood=?,address_city=?,address_state=?,address_zip=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$data['clinic_name'],$data['clinic_email'],$data['clinic_document'],$data['clinic_phone']!==''?$data['clinic_phone']:($canManageClinic?$data['phone']:''),$data['clinic_address_street'],$data['clinic_address_number'],$data['clinic_address_complement'],$data['clinic_address_neighborhood'],$data['clinic_address_city'],$data['clinic_address_state'],$data['clinic_address_zip'],TenantContext::getClinicId()]);
            $this->db->prepare("INSERT INTO audit_logs(clinic_id,user_id,action,resource,resource_id,details) VALUES(?,?,'PROFESSIONAL_PROFILE_UPDATED','users',?,'{}')")->execute([TenantContext::getClinicId(),TenantContext::getUserId(),(string)TenantContext::getUserId()]);
            $this->db->commit();$_SESSION['user_email']=$data['email'];$_SESSION['user_name']=$data['name'];$_SESSION['clinic_name']=$data['clinic_name'];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function verifyPassword(mixed $password): string
    {
        $key='settings_password_attempts_'.TenantContext::getUserId();
        $_SESSION[$key]=array_values(array_filter($_SESSION[$key]??[],static fn($time)=>$time>time()-900));
        if(count($_SESSION[$key])>=5)throw new \InvalidArgumentException('Muitas tentativas. Aguarde 15 minutos antes de conferir sua senha novamente.');
        $stmt=$this->db->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([TenantContext::getUserId()]);$hash=(string)$stmt->fetchColumn();
        if(!is_string($password)||!password_verify($password,$hash)){$_SESSION[$key][]=time();throw new \InvalidArgumentException('A senha atual está incorreta.');}
        unset($_SESSION[$key]);
        return $hash;
    }
    public function changePassword(array $input): void
    {
        $this->db->beginTransaction();
        try{
            $this->read(true);$old=$this->verifyPassword($input['current_password']??'');$new=$input['new_password']??'';
            if(!is_string($new)||strlen($new)<8||strlen($new)>72)throw new \InvalidArgumentException('A nova senha deve ter entre 8 e 72 caracteres.');
            if($new!==($input['password_confirmation']??null))throw new \InvalidArgumentException('A confirmação da nova senha não confere.');
            if(password_verify($new,$old))throw new \InvalidArgumentException('Escolha uma senha diferente da atual.');
            $stmt=$this->db->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND password_hash=?');
            $stmt->execute([password_hash($new,PASSWORD_BCRYPT,['cost'=>12]),TenantContext::getUserId(),$old]);
            if($stmt->rowCount()!==1)throw new \DomainException('A senha mudou em outra sessão. Entre novamente.');
            $this->db->prepare('DELETE FROM password_resets WHERE LOWER(email)=(SELECT LOWER(email) FROM users WHERE id=?)')->execute([TenantContext::getUserId()]);
            $this->db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([TenantContext::getUserId()]);
            $this->db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type IN ('user','professional') AND tokenable_id=?")->execute([TenantContext::getUserId()]);
            $this->db->prepare("INSERT INTO audit_logs(clinic_id,user_id,action,resource,resource_id,details) VALUES(?,?,'PASSWORD_CHANGED','users',?,'{}')")->execute([TenantContext::getClinicId(),TenantContext::getUserId(),(string)TenantContext::getUserId()]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** @var list<string> dias cuja pausa ficou fora do expediente e foi descartada no último saveAvailability */
    public array $droppedBreaks=[];
    public function saveAvailability(array $input): void
    {
        $this->droppedBreaks=[];
        $this->db->beginTransaction();
        try{
            $current=$this->read(true);
            if(!is_string($input['revision']??null)||!hash_equals(self::revision($current),$input['revision']))throw new \DomainException('As configurações mudaram em outra aba. Recarregue e confira os valores atuais.');
            $weekly=[];$names=['seg'=>'segunda-feira','ter'=>'terça-feira','qua'=>'quarta-feira','qui'=>'quinta-feira','sex'=>'sexta-feira','sab'=>'sábado','dom'=>'domingo'];
            foreach(['seg','ter','qua','qui','sex','sab','dom'] as $day){$dayName=$names[$day];
                $row=$input['weekly'][$day]??[];if(!is_array($row))throw new \InvalidArgumentException('Confira os horários da semana.');
                $enabled=($row['enabled']??'')==='1';$weekly[$day]=['enabled'=>$enabled,'start'=>'','end'=>'','break_start'=>'','break_end'=>''];
                if(!$enabled)continue;
                foreach(['start','end','break_start','break_end'] as $field){$value=$row[$field]??'';if(!is_string($value)||($value!==''&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value)))throw new \InvalidArgumentException('Informe horários válidos na '.$dayName.'.');$weekly[$day][$field]=$value;}
                $r=$weekly[$day];if($r['start']===''||$r['end']==='')throw new \InvalidArgumentException('Informe o início e o fim do atendimento na '.$dayName.'.');
                if($r['start']>=$r['end'])throw new \InvalidArgumentException('Na '.$dayName.', o início ('.$r['start'].') deve ser anterior ao fim ('.$r['end'].').');
                if(($r['break_start']==='')!==($r['break_end']===''))throw new \InvalidArgumentException('Na '.$dayName.', preencha o início e o fim da pausa, ou deixe os dois vazios.');
                // v54.39: pausa que fica toda fora do expediente (ex.: atende 14:00–18:00 e a pausa
                // ficou 12:00–13:00 de uma sugestão antiga) não se aplica ao dia: é descartada em vez
                // de bloquear o salvamento de TODA a semana.
                if($r['break_start']!==''&&($r['break_end']<=$r['start']||$r['break_start']>=$r['end'])){$weekly[$day]['break_start']='';$weekly[$day]['break_end']='';$this->droppedBreaks[]=$dayName;continue;}
                if($r['break_start']!==''&& !($r['start']<$r['break_start']&&$r['break_start']<$r['break_end']&&$r['break_end']<$r['end']))throw new \InvalidArgumentException('Na '.$dayName.', a pausa ('.$r['break_start'].'–'.$r['break_end'].') precisa ficar dentro do atendimento ('.$r['start'].'–'.$r['end'].').');
            }
            $duration=$input['default_duration_minutes']??'';
            if(!is_string($duration)||!ctype_digit($duration)||(int)$duration<15||(int)$duration>240)throw new \InvalidArgumentException('A duração deve estar entre 15 e 240 minutos.');
            $prefs=json_decode($current['practice_preferences'],true)?:[];$prefs['weekly_availability']=$weekly;$prefs['default_duration_minutes']=(int)$duration;$prefs['working_days']=array_keys(array_filter($weekly,static fn($r)=>$r['enabled']));
            $progress=(new \ClyoNutri\Domain\Onboarding\OnboardingService($this->db))->getOrCreateProgress(TenantContext::getUserId(),TenantContext::getClinicId());
            $this->db->prepare('UPDATE onboarding_progress SET practice_preferences=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([json_encode($prefs,JSON_THROW_ON_ERROR),$progress['id']]);
            $this->db->prepare("INSERT INTO audit_logs(clinic_id,user_id,action,resource,resource_id,details) VALUES(?,?,'AVAILABILITY_UPDATED','professionals',?,'{}')")->execute([TenantContext::getClinicId(),TenantContext::getUserId(),(string)TenantContext::getUserId()]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

}
