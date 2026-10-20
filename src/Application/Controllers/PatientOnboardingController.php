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
        $patientId = (int)($params['id'] ?? 0);
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Solicitação inválida. Volte ao perfil e tente novamente.';
            return;
        }

        $db = Connection::getInstance();
        $pStmt = $db->prepare('SELECT id, name, email, phone, status, password_hash, access_pin FROM patients WHERE id = ? AND clinic_id = ?');
        $pStmt->execute([$patientId, TenantContext::getClinicId()]);
        $patient = $pStmt->fetch(\PDO::FETCH_ASSOC) ?: ['id' => $patientId, 'name' => 'Paciente', 'email' => '', 'phone' => ''];

        try {
            $invitation = (new PatientOnboardingService($db))->create(
                TenantContext::getClinicId(),
                (int)TenantContext::getUserId(),
                $patientId
            );
            $error = null;
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            http_response_code(403);
            $error = 'Convite indisponível para este paciente. Verifique se o cadastro está ativo e se o paciente já possui senha cadastrada (caso já possua senha, ele pode entrar direto no app ou redefinir a senha).';
        } catch (\DomainException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            http_response_code(503);
            $error = 'Não foi possível gerar o convite. Tente novamente.';
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'clyonutri.com';
        $code = $invitation['invitation_code'] ?? '';
        $directLink = $scheme . '://' . $host . '/app?code=' . urlencode($code);
        $portalBaseUrl = $scheme . '://' . $host . '/app';

        $title = 'Primeiro acesso ao app';
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';

        ob_start();
        require __DIR__ . '/../../../views/patients/app-invitation.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
