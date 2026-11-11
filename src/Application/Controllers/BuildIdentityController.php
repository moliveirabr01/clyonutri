<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Release\BuildIdentity;

final class BuildIdentityController
{
    public function show(): void
    {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        $user = AuthContext::get();
        if (!$user || !($user->isPlatformAdmin() || $user->isSupport()
            || ($user->isClinical() && in_array($user->getMemberRole(), ['owner','admin'], true)))) {
            http_response_code(403);
            echo 'Acesso não autorizado.';
            return;
        }
        $identity = BuildIdentity::inspect(dirname(__DIR__,3));
        $title='Versão instalada'; $activeNav='configuracoes';
        $clinicName=$user->getClinicName(); $userName=$user->getName();
        ob_start();
        require dirname(__DIR__,3).'/views/settings/build_identity.php';
        $content=ob_get_clean();
        require dirname(__DIR__,3).'/views/layouts/'.($user->isPlatformAdmin()?'admin-shell.php':'professional-shell.php');
    }
}
