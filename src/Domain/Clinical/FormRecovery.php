<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Clinical;
use ClyoNutri\Core\Auth\TenantContext;
final class FormRecovery
{
    private static function key(int $patientId,string $kind): string
    { return TenantContext::getClinicId().':'.TenantContext::getUserId().':'.$patientId.':'.$kind; }
    public static function remember(int $patientId,string $kind,array $input): void
    {
        $values=[];
        foreach($input as $name=>$value) {
            if(str_starts_with((string)$name,'_')||in_array($name,['save_mode','version','view_version','revision_from','patient_id'],true))continue;
            if(is_string($value)&&strlen($value)<=200000)$values[$name]=$value;
            elseif(is_array($value)&&count($value)<=100&&count(array_filter($value,'is_string'))===count($value))$values[$name]=array_map(static fn($v)=>substr($v,0,1000),$value);
        }
        $_SESSION['clinical_form_recovery'][self::key($patientId,$kind)]=['expires'=>time()+3600,'values'=>$values];
    }
    public static function clear(int $patientId,string $kind): void
    { unset($_SESSION['clinical_form_recovery'][self::key($patientId,$kind)]); }
    public static function forCurrentPage(): ?array
    {
        $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
        if(!preg_match('~^/pacientes/(\d+)/(anamnese|antropometria)$~',(string)$path,$m)||isset($_GET['version'])||isset($_GET['view_version'])||isset($_GET['revision_from'])||isset($_GET['v1']))return null;
        $key=self::key((int)$m[1],$m[2]);$entry=$_SESSION['clinical_form_recovery'][$key]??null;
        if(!$entry)return null;
        if(($entry['expires']??0)<time()){unset($_SESSION['clinical_form_recovery'][$key]);return null;}
        return ['action'=>$path,'values'=>$entry['values']];
    }
}
