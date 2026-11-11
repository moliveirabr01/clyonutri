<?php
declare(strict_types=1);
namespace ClyoNutri\Core\Auth;
use ClyoNutri\Core\Database\Connection;
use PDO;
final class ProfessionalViewContext
{
    public static function resolve(?PDO $db=null): array
    {
        $missing=['clinicName'=>'Contexto da clínica indisponível','userName'=>'Identidade indisponível'];
        if(!TenantContext::hasClinic() || !TenantContext::getUserId()) return $missing;
        $db ??= Connection::getInstance();
        $st=$db->prepare("SELECT c.name AS clinic_name,u.name AS user_name FROM clinic_members m JOIN clinics c ON c.id=m.clinic_id JOIN users u ON u.id=m.user_id WHERE m.clinic_id=? AND m.user_id=? AND m.status='active' AND c.status='active' AND u.status='active'");
        $st->execute([TenantContext::getClinicId(),TenantContext::getUserId()]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        return $row?['clinicName'=>$row['clinic_name'],'userName'=>$row['user_name']]:$missing;
    }
}
