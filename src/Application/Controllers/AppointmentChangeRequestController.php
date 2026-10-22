<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\AppointmentConflictException;
use ClyoNutri\Domain\Appointment\PatientAppointmentService;

/**
 * Pedidos de remarcação feitos pela paciente no app (NI2-137). A equipe clínica (dono, admin, nutricionista)
 * aceita ou recusa, como no reagendamento da agenda. Aceitar usa o mesmo caminho, com checagem de conflito.
 */
final class AppointmentChangeRequestController
{
    private function isClinicStaff(): bool
    {
        $stmt = Connection::getInstance()->prepare("SELECT cm.role FROM clinic_members cm JOIN users u ON u.id = cm.user_id AND u.status = 'active'
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active'");
        $stmt->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $role = $stmt->fetchColumn();
        // Mesmo limite do reagendamento na agenda: a recepção não remarca (ClinicalAuthorization), então não aceita pedidos.
        return is_string($role) && in_array($role, ['owner', 'admin', 'nutritionist'], true);
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Acesso restrito à equipe clínica do consultório.';
    }

    public function index(array $params = []): void
    {
        if (!$this->isClinicStaff()) { $this->deny(); return; }
        $service = new PatientAppointmentService();
        $pending = $service->pendingRequestsForClinic();
        $recent = $service->recentDecisionsForClinic();
        $csrfToken = Csrf::token();
        $flash = $_SESSION['change_request_flash'] ?? null; unset($_SESSION['change_request_flash']);
        $title = 'Pedidos de remarcação';
        $activeNav = 'agenda';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        require __DIR__ . '/../../../views/agenda/change_requests.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function approve(array $params = []): void
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Sessão expirada. Recarregue a página.'; return; }
        if (!$this->isClinicStaff()) { $this->deny(); return; }
        try {
            $updated = (new PatientAppointmentService())->approveRequest((int)($params['id'] ?? 0), (int)TenantContext::getUserId());
            $this->back('success', 'Pedido aceito. A consulta foi para ' . date('d/m/Y', strtotime((string)$updated['scheduled_at'])) . ' às ' . date('H:i', strtotime((string)$updated['scheduled_at']))
                . ' e voltou para "agendada": a paciente precisa confirmar o novo horário.'
                . (\ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType((string)($updated['type'] ?? '')) ? ' Envie um novo convite da teleconsulta.' : ''));
        } catch (AppointmentConflictException $e) {
            $this->back('error', 'Horário ocupado na agenda: ' . $e->getMessage() . ' Recuse o pedido ou libere o horário.');
        } catch (\InvalidArgumentException $e) {
            $this->back('error', $e->getMessage());
        } catch (TenantSecurityException) {
            $this->back('error', 'Pedido não encontrado.');
        }
    }

    public function decline(array $params = []): void
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Sessão expirada. Recarregue a página.'; return; }
        if (!$this->isClinicStaff()) { $this->deny(); return; }
        $note = is_string($_POST['decision_note'] ?? null) ? $_POST['decision_note'] : '';
        try {
            (new PatientAppointmentService())->declineRequest((int)($params['id'] ?? 0), (int)TenantContext::getUserId(), $note);
            $this->back('success', 'Pedido recusado. A consulta continua no horário atual e a paciente vê a resposta no app.');
        } catch (\ClyoNutri\Domain\Appointment\PatientAppointmentActionException $e) {
            $this->back('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->back('error', $e->getMessage());
        }
    }

    private function back(string $type, string $message): void
    {
        $_SESSION['change_request_flash'] = ['type' => $type, 'message' => $message];
        header('Location: /agenda/pedidos-remarcacao');
    }
}
