<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Evolution\PatientEvolutionService;

/**
 * Ações do profissional sobre a evolução da paciente no app (NI2-135): habilitar métricas de autorrelato
 * e criar/encerrar metas. Só prescritor (dono, admin ou nutricionista com registro profissional ativo).
 */
final class PatientEvolutionController
{
    private function prescriberProfessionalId(): ?int
    {
        $st = Connection::getInstance()->prepare("SELECT pr.id FROM clinic_members cm JOIN users u ON u.id = cm.user_id AND u.status = 'active'
            JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active' AND cm.role IN ('owner','admin','nutritionist') LIMIT 1");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Valida CSRF e permissão; devolve o id do profissional ou encerra com 403. */
    private function authorize(): ?int
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Sessão expirada. Recarregue a página.'; return null; }
        $prof = $this->prescriberProfessionalId();
        if ($prof === null) { http_response_code(403); echo 'Somente nutricionistas do consultório podem alterar a evolução no app.'; return null; }
        return $prof;
    }

    private function back(int $patientId, string $type, string $message): void
    {
        $_SESSION['app_evolution_flash'] = ['type' => $type, 'message' => $message];
        header('Location: /pacientes/' . $patientId . '#app-evolucao');
    }

    public function permissions(array $params = []): void
    {
        if ($this->authorize() === null) return;
        $pid = (int)($params['id'] ?? 0);
        $metrics = $_POST['metrics'] ?? [];
        if (!is_array($metrics)) $metrics = [];
        try {
            $enabled = (new PatientEvolutionService())->setEnabledMetrics($pid, array_filter($metrics, 'is_string'), (int)TenantContext::getUserId());
            $this->back($pid, 'success', $enabled ? 'Medidas liberadas para registro no app atualizadas.' : 'A paciente não pode registrar medidas pelo app.');
        } catch (\InvalidArgumentException $e) {
            $this->back($pid, 'error', $e->getMessage());
        } catch (TenantSecurityException $e) {
            http_response_code(404); echo 'Paciente não encontrado.';
        }
    }

    public function createGoal(array $params = []): void
    {
        if (($prof = $this->authorize()) === null) return;
        $pid = (int)($params['id'] ?? 0);
        try {
            (new PatientEvolutionService())->createGoal($pid, $prof, (int)TenantContext::getUserId(), [
                'metric_type' => is_string($_POST['metric_type'] ?? null) ? $_POST['metric_type'] : '',
                'direction' => is_string($_POST['direction'] ?? null) ? $_POST['direction'] : '',
                'target_value' => is_string($_POST['target_value'] ?? null) ? $_POST['target_value'] : '',
                'target_date' => is_string($_POST['target_date'] ?? null) ? $_POST['target_date'] : '',
                'message_for_patient' => is_string($_POST['message_for_patient'] ?? null) ? $_POST['message_for_patient'] : '',
            ]);
            $this->back($pid, 'success', 'Meta criada. A paciente passa a vê-la na aba Evolução do app.');
        } catch (\InvalidArgumentException $e) {
            $this->back($pid, 'error', $e->getMessage());
        } catch (TenantSecurityException $e) {
            http_response_code(404); echo 'Paciente não encontrado.';
        }
    }

    public function closeGoal(array $params = []): void
    {
        if ($this->authorize() === null) return;
        $pid = (int)($params['id'] ?? 0);
        try {
            $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
            (new PatientEvolutionService())->closeGoal($pid, (int)($params['goalId'] ?? 0), $status, (int)TenantContext::getUserId());
            $this->back($pid, 'success', $status === 'achieved' ? 'Meta marcada como atingida.' : 'Meta encerrada.');
        } catch (\InvalidArgumentException $e) {
            $this->back($pid, 'error', $e->getMessage());
        } catch (TenantSecurityException $e) {
            http_response_code(404); echo 'Paciente não encontrado.';
        }
    }
}
