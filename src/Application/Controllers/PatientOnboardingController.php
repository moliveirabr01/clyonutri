<?php
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\PatientPortal\PatientOnboardingService;
final class PatientOnboardingController
{
    public function invite(array $params): void
    {
        header('Cache-Control: no-store');
        $patientId=(int)($params['id']??0);
        if (!Csrf::validate($_POST['_csrf_token']??'')) { http_response_code(403); echo 'Solicitação inválida. Volte ao perfil e tente novamente.'; return; }
        try {
            $invitation=(new PatientOnboardingService(Connection::getInstance()))->create(TenantContext::getClinicId(),(int)TenantContext::getUserId(),$patientId);
            $error=null;
        } catch (\DomainException $e) { $error=$e->getMessage(); }
        catch (\Throwable $e) { http_response_code(503); $error='Não foi possível gerar o convite. Tente novamente.'; }
        $title='Primeiro acesso ao app'; $activeNav='pacientes';
        $clinicName=$_SESSION['clinic_name']??'Minha Clínica'; $userName=$_SESSION['user_name']??'';
        ob_start();
        require __DIR__.'/../../../views/patients/app-invitation.php';
        $content=ob_get_clean();
        require __DIR__.'/../../../views/layouts/professional-shell.php';
    }
}
