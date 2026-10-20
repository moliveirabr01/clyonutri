<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\MobileWebSession;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Booking\BookingConflictException;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Booking\HoldExpiredException;
use InvalidArgumentException;
use PDO;

final class BookingController
{
    private PDO $db;
    private BookingService $service;
    private int $lastStatusCode = 200;
    private array $lastResponseData = [];

    public function __construct(?PDO $db = null, ?BookingService $service = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->service = $service ?? new BookingService($this->db);
    }

    private function respondJson(int $statusCode, array $data): void
    {
        $this->lastStatusCode = $statusCode;
        $this->lastResponseData = $data;

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public function getLastStatusCode(): int
    {
        return $this->lastStatusCode;
    }

    public function getLastResponseData(): array
    {
        return $this->lastResponseData;
    }

    /**
     * GET /api/v1/booking/slots
     */
    public function getSlots(): void
    {
        $clinicId = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : 0;
        $professionalId = isset($_GET['professional_id']) ? (int)$_GET['professional_id'] : 0;
        $date = trim((string)($_GET['date'] ?? ''));
        $duration = isset($_GET['duration']) ? (int)$_GET['duration'] : 60;
        $modality = trim((string)($_GET['modality'] ?? 'presential'));

        if ($clinicId <= 0 || $professionalId <= 0 || $date === '') {
            $this->respondJson(400, [
                'error' => 'Parâmetros obrigatórios ausentes: clinic_id, professional_id e date.',
                'code' => 'MISSING_PARAMS',
            ]);
            return;
        }

        try {
            $slots = $this->service->getAvailableSlots($clinicId, $professionalId, $date, $duration, $modality);
            $this->respondJson(200, [
                'clinic_id' => $clinicId,
                'professional_id' => $professionalId,
                'date' => $date,
                'duration_minutes' => $duration,
                'available_slots' => $slots,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'INVALID_ARGUMENT']);
        } catch (\Throwable $e) {
            $this->respondJson(500, ['error' => 'Erro interno ao consultar disponibilidade.', 'code' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * POST /api/v1/booking/holds
     */
    public function createHold(): void
    {
        $rawInput = (string)file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?: $_POST;

        $clinicId = isset($body['clinic_id']) ? (int)$body['clinic_id'] : 0;
        $professionalId = isset($body['professional_id']) ? (int)$body['professional_id'] : 0;
        $scheduledAt = trim((string)($body['scheduled_at'] ?? ''));
        $duration = isset($body['duration_minutes']) ? (int)$body['duration_minutes'] : 60;
        $serviceType = trim((string)($body['service_type'] ?? 'first_visit'));
        $modality = trim((string)($body['modality'] ?? 'presential'));
        $idempotencyKey = trim((string)($body['idempotency_key'] ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '')));

        if ($clinicId <= 0 || $professionalId <= 0 || $scheduledAt === '') {
            $this->respondJson(400, [
                'error' => 'Parâmetros obrigatórios: clinic_id, professional_id e scheduled_at.',
                'code' => 'MISSING_PARAMS',
            ]);
            return;
        }

        try {
            $hold = $this->service->createHold(
                $clinicId,
                $professionalId,
                $scheduledAt,
                $duration,
                $serviceType,
                $modality,
                $idempotencyKey !== '' ? $idempotencyKey : null,
                $body
            );
            $this->respondJson(201, $hold);
        } catch (BookingConflictException $e) {
            $this->respondJson(409, ['error' => $e->getMessage(), 'code' => 'SLOT_UNAVAILABLE']);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'UNPROCESSABLE_ENTITY']);
        } catch (\Throwable $e) {
            $this->respondJson(500, ['error' => 'Erro ao criar reserva temporária.', 'code' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * POST /api/v1/booking/confirm
     */
    public function confirmBooking(): void
    {
        $rawInput = (string)file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?: $_POST;

        $holdToken = trim((string)($body['hold_token'] ?? ''));
        if ($holdToken === '') {
            $this->respondJson(400, ['error' => 'hold_token é obrigatório.', 'code' => 'MISSING_HOLD_TOKEN']);
            return;
        }

        try {
            $result = $this->service->confirmBooking($holdToken, $body);
            $this->respondJson(200, $result);
        } catch (HoldExpiredException $e) {
            $this->respondJson(410, ['error' => $e->getMessage(), 'code' => 'HOLD_EXPIRED']);
        } catch (BookingConflictException $e) {
            $this->respondJson(409, ['error' => $e->getMessage(), 'code' => 'SLOT_CONFLICT']);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'UNPROCESSABLE_ENTITY']);
        } catch (\Throwable $e) {
            $this->respondJson(500, ['error' => 'Erro interno ao confirmar agendamento.', 'code' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * POST /api/v1/booking/release
     */
    public function releaseHold(): void
    {
        $rawInput = (string)file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?: $_POST;

        $holdToken = trim((string)($body['hold_token'] ?? ''));
        if ($holdToken === '') {
            $this->respondJson(400, ['error' => 'hold_token é obrigatório.', 'code' => 'MISSING_HOLD_TOKEN']);
            return;
        }

        $released = $this->service->releaseHold($holdToken);
        $this->respondJson(200, ['success' => true, 'released' => $released]);
    }

    /**
     * POST /api/v1/patient/appointments/{id}/cancel
     */
    public function cancelByPatient(array $params = []): void
    {
        $patientAuth = $this->getAuthenticatedPatient();
        if (!$patientAuth) {
            $this->respondJson(401, ['error' => 'Paciente não autenticado.', 'code' => 'UNAUTHENTICATED']);
            return;
        }

        $appointmentId = (int)($params['id'] ?? 0);
        $rawInput = (string)file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?: $_POST;
        $reason = trim((string)($body['reason'] ?? ''));

        if ($appointmentId <= 0) {
            $this->respondJson(400, ['error' => 'ID da consulta inválido.', 'code' => 'INVALID_APPOINTMENT_ID']);
            return;
        }

        try {
            $result = $this->service->cancelBookingByPatient(
                $patientAuth['clinic_id'],
                $patientAuth['patient_id'],
                $appointmentId,
                $reason !== '' ? $reason : 'Cancelado pelo paciente via portal'
            );
            $this->respondJson(200, $result);
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            $this->respondJson(403, ['error' => $e->getMessage(), 'code' => 'FORBIDDEN']);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'POLICY_VIOLATION']);
        } catch (\Throwable $e) {
            $this->respondJson(500, ['error' => 'Erro ao cancelar consulta.', 'code' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * POST /api/v1/patient/appointments/{id}/reschedule
     */
    public function rescheduleByPatient(array $params = []): void
    {
        $patientAuth = $this->getAuthenticatedPatient();
        if (!$patientAuth) {
            $this->respondJson(401, ['error' => 'Paciente não autenticado.', 'code' => 'UNAUTHENTICATED']);
            return;
        }

        $appointmentId = (int)($params['id'] ?? 0);
        $rawInput = (string)file_get_contents('php://input');
        $body = json_decode($rawInput, true) ?: $_POST;
        $newTime = trim((string)($body['new_scheduled_at'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));

        if ($appointmentId <= 0 || $newTime === '') {
            $this->respondJson(400, ['error' => 'new_scheduled_at é obrigatório.', 'code' => 'MISSING_NEW_TIME']);
            return;
        }

        try {
            $result = $this->service->rescheduleBookingByPatient(
                $patientAuth['clinic_id'],
                $patientAuth['patient_id'],
                $appointmentId,
                $newTime,
                $reason !== '' ? $reason : 'Reagendamento solicitado pelo paciente'
            );
            $this->respondJson(200, $result);
        } catch (BookingConflictException $e) {
            $this->respondJson(409, ['error' => $e->getMessage(), 'code' => 'SLOT_CONFLICT']);
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            $this->respondJson(403, ['error' => $e->getMessage(), 'code' => 'FORBIDDEN']);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'POLICY_VIOLATION']);
        } catch (\Throwable $e) {
            $this->respondJson(500, ['error' => 'Erro ao reagendar consulta.', 'code' => 'INTERNAL_ERROR']);
        }
    }

    /**
     * GET /agendar e GET /agendar/{professionalId}
     * Página pública amigável de autoagendamento self-service.
     */
    public function publicBookingPage(array $params = []): void
    {
        $profId = isset($params['professionalId']) ? (int)$params['professionalId'] : (isset($_GET['prof']) ? (int)$_GET['prof'] : 0);

        // Buscar profissionais ativos
        $stmt = $this->db->prepare("
            SELECT p.id, cm.clinic_id, u.name, u.email, c.name AS clinic_name, c.logo_path
            FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE u.status = 'active' AND c.status = 'active' AND cm.status = 'active'
            ORDER BY u.name ASC
        ");
        $stmt->execute();
        $professionals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $selectedProf = null;
        if ($profId > 0) {
            foreach ($professionals as $p) {
                if ((int)$p['id'] === $profId) {
                    $selectedProf = $p;
                    break;
                }
            }
        }
        if ($selectedProf === null && !empty($professionals)) {
            $selectedProf = $professionals[0];
            $profId = (int)$selectedProf['id'];
        }

        $clinicId = $selectedProf ? (int)$selectedProf['clinic_id'] : 1;
        $settings = $this->service->getSettings($clinicId);

        require __DIR__ . '/../../../views/booking/public_booking.php';
    }

    private function getAuthenticatedPatient(): ?array
    {
        // 1. Verificar sessão móvel / token
        $raw = $_COOKIE[MobileWebSession::COOKIE_NAME] ?? null;
        if ($raw) {
            $hash = hash('sha256', $raw);
            $stmt = $this->db->prepare("
                SELECT tokenable_id AS patient_id, clinic_id
                FROM personal_access_tokens
                WHERE tokenable_type = 'patient' AND token = :h AND expires_at > CURRENT_TIMESTAMP
                LIMIT 1
            ");
            $stmt->execute(['h' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['patient_id' => (int)$row['patient_id'], 'clinic_id' => (int)$row['clinic_id']];
            }
        }

        // 2. Verificar sessão PHP tradicional
        if (!empty($_SESSION['patient_id']) && !empty($_SESSION['clinic_id'])) {
            return ['patient_id' => (int)$_SESSION['patient_id'], 'clinic_id' => (int)$_SESSION['clinic_id']];
        }

        return null;
    }
}
