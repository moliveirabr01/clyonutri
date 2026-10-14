<?php

declare(strict_types=1);
namespace ClyoNutri\Domain\PatientPortal;
use PDO;
use DomainException;

/** First access only: invitations cannot reset an existing patient's credential. */
final class PatientOnboardingService
{
    public function __construct(private PDO $db) {}

    public function create(int $clinicId, int $userId, int $patientId): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT p.id FROM patients p JOIN clinics c ON c.id=p.clinic_id
                JOIN clinic_members m ON m.clinic_id=c.id AND m.user_id=? AND m.status='active'
                JOIN users u ON u.id=m.user_id AND u.status='active' AND u.role='nutritionist'
                JOIN professionals pr ON pr.user_id=u.id
                WHERE p.id=? AND p.clinic_id=? AND p.status='active' AND c.status='active'
                AND m.role IN ('owner','admin','nutritionist')
                AND COALESCE(p.password_hash,'')='' AND COALESCE(p.access_pin,'')=''");
            $stmt->execute([$userId, $patientId, $clinicId]);
            if (!$stmt->fetch()) throw new \ClyoNutri\Core\Security\TenantSecurityException('Convite indisponível. Verifique o cadastro e se o paciente já possui acesso ao app.');
            $now = gmdate('Y-m-d H:i:s');
            $this->db->prepare('UPDATE patient_app_invitations SET revoked_at=? WHERE patient_id=? AND clinic_id=? AND accepted_at IS NULL AND revoked_at IS NULL')->execute([$now,$patientId,$clinicId]);
            $token = bin2hex(random_bytes(32));
            $expires = gmdate('Y-m-d H:i:s', time()+48*3600);
            $this->db->prepare('INSERT INTO patient_app_invitations(clinic_id,patient_id,created_by,token_hash,expires_at) VALUES(?,?,?,?,?)')->execute([$clinicId,$patientId,$userId,hash('sha256',$token),$expires]);
            $this->db->commit();
            return ['invitation_code'=>$token,'expires_at'=>$expires.' UTC'];
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    private function invitation(string $token, bool $lock = false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) throw new DomainException('Convite inválido ou expirado. Solicite um novo convite ao nutricionista.');
        $sql = "SELECT i.id AS invitation_id,i.clinic_id,p.id,p.name,p.email,p.phone,c.name AS clinic_name,u.name AS professional_name
            FROM patient_app_invitations i JOIN patients p ON p.id=i.patient_id AND p.clinic_id=i.clinic_id
            JOIN clinics c ON c.id=i.clinic_id AND c.status='active'
            JOIN users u ON u.id=i.created_by AND u.status='active' AND u.role='nutritionist'
            JOIN professionals pr ON pr.user_id=u.id
            JOIN clinic_members m ON m.user_id=u.id AND m.clinic_id=i.clinic_id AND m.status='active' AND m.role IN ('owner','admin','nutritionist')
            WHERE i.token_hash=? AND i.expires_at>? AND i.accepted_at IS NULL AND i.revoked_at IS NULL
            AND p.status='active' AND COALESCE(p.password_hash,'')='' AND COALESCE(p.access_pin,'')=''";
        if ($lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $sql .= ' FOR UPDATE';
        $stmt=$this->db->prepare($sql); $stmt->execute([hash('sha256',strtolower($token)),gmdate('Y-m-d H:i:s')]);
        $row=$stmt->fetch();
        if (!$row) throw new DomainException('Convite inválido ou expirado. Solicite um novo convite ao nutricionista.');
        return $row;
    }

    public function preview(string $token): array
    {
        $row=$this->invitation(trim($token));
        return ['patient'=>['name'=>$row['name'],'email'=>$row['email']??'','phone'=>$row['phone']??''], 'clinic_name'=>$row['clinic_name'],'professional_name'=>$row['professional_name']];
    }

    public function complete(array $input): array
    {
        foreach (['invitation_code','name','email','phone','password','password_confirmation'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) throw new DomainException('Preencha os dados do primeiro acesso.');
        }
        $name=trim($input['name']); $email=strtolower(trim($input['email'])); $phone=trim($input['phone']); $password=$input['password'];
        if ($name==='' || mb_strlen($name)>255 || strlen($email)>255 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($phone)>30) throw new DomainException('Confira seu nome, e-mail e celular.');
        if (strlen($password)<8 || strlen($password)>72) throw new DomainException('Use uma senha de 8 a 72 caracteres.');
        if ($password!==$input['password_confirmation']) throw new DomainException('As senhas não coincidem.');
        $this->db->beginTransaction();
        try {
            $row=$this->invitation(trim($input['invitation_code']),true);
            $duplicate=$this->db->prepare("SELECT p.id FROM patients p JOIN clinics c ON c.id=p.clinic_id AND c.status='active' WHERE LOWER(p.email)=? AND p.status='active' AND p.id<>?");
            $duplicate->execute([$email,$row['id']]);
            if ($duplicate->fetch()) throw new DomainException('Não foi possível usar este e-mail. Confirme o cadastro com seu nutricionista.');
            $now=gmdate('Y-m-d H:i:s');
            $accept=$this->db->prepare('UPDATE patient_app_invitations SET accepted_at=? WHERE id=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>?');
            $accept->execute([$now,$row['invitation_id'],$now]);
            if ($accept->rowCount()!==1) throw new DomainException('Convite indisponível. Solicite um novo convite.');
            $update=$this->db->prepare("UPDATE patients SET name=?,email=?,phone=?,password_hash=?,otp_code=NULL,otp_expires_at=NULL,updated_at=? WHERE id=? AND clinic_id=? AND COALESCE(password_hash,'')='' AND COALESCE(access_pin,'')=''");
            $update->execute([$name,$email,$phone,password_hash($password,PASSWORD_DEFAULT),$now,$row['id'],$row['clinic_id']]);
            if ($update->rowCount()!==1) throw new DomainException('Este paciente já possui acesso ao app.');
            $token=bin2hex(random_bytes(32)); $expires=gmdate('Y-m-d H:i:s',time()+30*86400);
            $this->db->prepare("INSERT INTO personal_access_tokens(tokenable_type,tokenable_id,clinic_id,name,token,expires_at) VALUES('patient',?,?,'mobile_app',?,?)")->execute([$row['id'],$row['clinic_id'],hash('sha256',$token),$expires]);
            $this->db->commit();
            return ['token'=>$token,'expires_at'=>$expires,'patient'=>['id'=>(int)$row['id'],'clinic_id'=>(int)$row['clinic_id'],'name'=>$name,'email'=>$email]];
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
