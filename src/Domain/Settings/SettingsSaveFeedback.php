<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Settings;
use ClyoNutri\Core\Auth\TenantContext;
/** One-use confirmation issued only after the existing domain service returns successfully. */
final class SettingsSaveFeedback
{
    public static function issue(string $section): string
    {
        $token=bin2hex(random_bytes(24));
        $_SESSION['settings_save_feedback'][$token]=['clinic'=>TenantContext::getClinicId(),'user'=>TenantContext::getUserId(),'section'=>$section,'expires'=>time()+300];
        foreach($_SESSION['settings_save_feedback'] as $k=>$v)if(($v['expires']??0)<time())unset($_SESSION['settings_save_feedback'][$k]);
        if(count($_SESSION['settings_save_feedback'])>20)array_shift($_SESSION['settings_save_feedback']);
        return $token;
    }
    public static function consume(mixed $token): ?string
    {
        if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return null;
        $r=$_SESSION['settings_save_feedback'][$token]??null;unset($_SESSION['settings_save_feedback'][$token]);
        return $r&&$r['clinic']===TenantContext::getClinicId()&&$r['user']===TenantContext::getUserId()&&$r['expires']>=time()?$r['section']:null;
    }
}
