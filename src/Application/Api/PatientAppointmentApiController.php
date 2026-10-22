<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Api;

use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Appointment\PatientAppointmentActionException;
use ClyoNutri\Domain\Appointment\PatientAppointmentService;
use PDO;

/**
 * API da paciente para a próxima consulta (NI2-137). A autenticação (Bearer ou cookie web com Origin e
 * cabeçalho do app) é feita antes, em public/index.php, e já fixa a clínica no TenantContext.
 *
 * Erros: 404 APPOINTMENT_NOT_FOUND / REQUEST_NOT_FOUND (sem ids na mensagem), 403 ACTION_NOT_ALLOWED,
 * 409 INVALID_STATE (com current_status) / SLOT_CONFLICT / REQUEST_PENDING, 422 POLICY_VIOLATION / VALIDATION_ERROR.
 */
final class PatientAppointmentApiController
{
    public function __construct(private PDO $db)
    {
    }

    private function service(): PatientAppointmentService
    {
        return new PatientAppointmentService($this->db);
    }

    public function confirm(int $patientId, int $appointmentId): void
    {
        $this->run(fn() => $this->service()->confirm($patientId, $appointmentId));
    }

    public function cancel(int $patientId, int $appointmentId, array $input): void
    {
        $reason = $input['reason'] ?? '';
        if (!is_string($reason)) {
            $this->respond(422, ['error' => 'Motivo inválido.', 'code' => 'VALIDATION_ERROR']);
            return;
        }
        $this->run(fn() => $this->service()->cancel($patientId, $appointmentId, $reason));
    }

    public function rescheduleOptions(int $patientId, int $appointmentId): void
    {
        $this->run(fn() => $this->service()->rescheduleOptions($patientId, $appointmentId));
    }

    public function requestReschedule(int $patientId, int $appointmentId, array $input): void
    {
        $newAt = $input['requested_scheduled_at'] ?? ($input['new_scheduled_at'] ?? '');
        $reason = $input['reason'] ?? '';
        if (!is_string($newAt) || !is_string($reason)) {
            $this->respond(422, ['error' => 'Escolha um dos horários livres da lista.', 'code' => 'VALIDATION_ERROR']);
            return;
        }
        $this->run(fn() => $this->service()->requestReschedule($patientId, $appointmentId, $newAt, $reason), 202);
    }

    public function withdrawRequest(int $patientId, int $requestId): void
    {
        $this->run(fn() => $this->service()->withdrawRequest($patientId, $requestId));
    }

    private function run(callable $fn, int $successStatus = 200): void
    {
        try {
            $this->respond($successStatus, $fn());
        } catch (PatientAppointmentActionException $e) {
            $this->respond($e->httpStatus, $e->toArray());
        } catch (TenantSecurityException) {
            $this->respond(404, ['error' => 'Consulta não encontrada.', 'code' => 'APPOINTMENT_NOT_FOUND']);
        } catch (\ClyoNutri\Domain\Appointment\InvalidAppointmentTransitionException $e) {
            $this->respond(409, ['error' => 'A consulta mudou e esta ação não está mais disponível. Atualize a tela.', 'code' => 'INVALID_STATE']);
        } catch (\InvalidArgumentException $e) {
            // Corrida: outra pessoa alterou a consulta entre a leitura e a gravação.
            $this->respond(409, ['error' => 'A consulta mudou e esta ação não está mais disponível. Atualize a tela.', 'code' => 'INVALID_STATE']);
        }
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
    }
}
