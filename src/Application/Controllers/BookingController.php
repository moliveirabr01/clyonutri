<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Booking\BookingConflictException;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Booking\BookingUnavailableException;
use ClyoNutri\Domain\Booking\HoldExpiredException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException;
use ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter;
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

    /** Limite por IP (hash; o IP não é gravado em claro). Lança 429 via exceção. */
    private function rateLimit(string $bucket, int $max, int $windowSeconds): void
    {
        // Ajustável por ambiente (ex.: BOOKING_RATE_LIMIT_HOLD=50); o padrão é o valor de produção.
        $env = getenv('BOOKING_RATE_LIMIT_' . strtoupper($bucket));
        if (is_string($env) && ctype_digit($env) && (int)$env > 0) {
            $max = (int)$env;
        }
        (new TeleconsultationRateLimiter($this->db))->hit('booking_' . $bucket, (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), $max, $windowSeconds);
    }

    private function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        $json = json_decode($raw, true);
        return is_array($json) ? $json : $_POST;
    }

    private function internalError(\Throwable $e, string $message): void
    {
        // Observabilidade sem dados pessoais: só classe, código e local.
        error_log('[ClyoNutri][booking] ' . get_class($e) . ' #' . $e->getCode() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
        $this->respondJson(500, ['error' => $message, 'code' => 'INTERNAL_ERROR']);
    }

    /**
     * GET /api/v1/booking/slots?professional_id=&date=AAAA-MM-DD&modality=
     * A clínica e a duração vêm do cadastro do profissional, não do cliente.
     */
    public function getSlots(): void
    {
        $professionalId = isset($_GET['professional_id']) ? (int)$_GET['professional_id'] : 0;
        $clinicParam = isset($_GET['clinic_id']) ? (int)$_GET['clinic_id'] : null;
        $date = trim((string)($_GET['date'] ?? ''));
        $modality = trim((string)($_GET['modality'] ?? 'presential'));

        if ($professionalId <= 0 || $date === '') {
            $this->respondJson(400, ['error' => 'Parâmetros obrigatórios ausentes: professional_id e date.', 'code' => 'MISSING_PARAMS']);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($modality, BookingService::MODALITIES, true)) {
            $this->respondJson(422, ['error' => 'Use date=AAAA-MM-DD e modality=presential ou telehealth.', 'code' => 'INVALID_ARGUMENT']);
            return;
        }
        try {
            $this->rateLimit('slots', 120, 600);
            $prof = $this->service->publicProfessional($professionalId);
            if ($prof === null || ($clinicParam !== null && $clinicParam !== $prof['clinic_id'])) {
                throw new BookingUnavailableException();
            }
            $slots = $this->service->getAvailableSlots($prof['clinic_id'], $professionalId, $date, $prof['duration_minutes'], $modality);
            $this->respondJson(200, [
                'clinic_id' => $prof['clinic_id'],
                'professional_id' => $professionalId,
                'date' => $date,
                'duration_minutes' => $prof['duration_minutes'],
                'available_slots' => $slots,
            ]);
        } catch (BookingUnavailableException $e) {
            $this->respondJson(404, ['error' => $e->getMessage(), 'code' => 'BOOKING_NOT_AVAILABLE']);
        } catch (TeleconsultationRateLimitException $e) {
            $this->respondJson(429, ['error' => $e->getMessage(), 'code' => 'RATE_LIMITED']);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'INVALID_ARGUMENT']);
        } catch (\Throwable $e) {
            $this->internalError($e, 'Erro interno ao consultar disponibilidade.');
        }
    }

    /**
     * POST /api/v1/booking/holds
     */
    public function createHold(): void
    {
        $body = $this->body();
        $professionalId = isset($body['professional_id']) ? (int)$body['professional_id'] : 0;
        $clinicParam = isset($body['clinic_id']) ? (int)$body['clinic_id'] : null;
        $scheduledAt = trim((string)($body['scheduled_at'] ?? ''));
        $serviceType = trim((string)($body['service_type'] ?? 'first_visit'));
        $modality = trim((string)($body['modality'] ?? 'presential'));
        $idempotencyKey = trim((string)($body['idempotency_key'] ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '')));

        if ($professionalId <= 0 || $scheduledAt === '') {
            $this->respondJson(400, ['error' => 'Parâmetros obrigatórios: professional_id e scheduled_at.', 'code' => 'MISSING_PARAMS']);
            return;
        }

        try {
            $this->rateLimit('hold', 10, 600);
            $prof = $this->service->publicProfessional($professionalId);
            if ($prof === null || ($clinicParam !== null && $clinicParam !== $prof['clinic_id'])) {
                throw new BookingUnavailableException();
            }
            $hold = $this->service->createHold(
                $prof['clinic_id'],
                $professionalId,
                $scheduledAt,
                $prof['duration_minutes'],
                $serviceType,
                $modality,
                $idempotencyKey !== '' ? $idempotencyKey : null
            );
            $this->respondJson($hold['idempotent_replay'] ? 200 : 201, $hold);
        } catch (BookingUnavailableException $e) {
            $this->respondJson(404, ['error' => $e->getMessage(), 'code' => 'BOOKING_NOT_AVAILABLE']);
        } catch (TeleconsultationRateLimitException $e) {
            $this->respondJson(429, ['error' => $e->getMessage(), 'code' => 'RATE_LIMITED']);
        } catch (BookingConflictException $e) {
            $this->respondJson(409, ['error' => $e->getMessage(), 'code' => 'SLOT_UNAVAILABLE']);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'UNPROCESSABLE_ENTITY']);
        } catch (\Throwable $e) {
            $this->internalError($e, 'Erro ao criar reserva temporária.');
        }
    }

    /**
     * POST /api/v1/booking/confirm
     */
    public function confirmBooking(): void
    {
        $body = $this->body();
        $holdToken = trim((string)($body['hold_token'] ?? ''));
        if ($holdToken === '') {
            $this->respondJson(400, ['error' => 'hold_token é obrigatório.', 'code' => 'MISSING_HOLD_TOKEN']);
            return;
        }

        try {
            $this->rateLimit('confirm', 20, 600);
            $result = $this->service->confirmBooking($holdToken, [
                'name' => $body['name'] ?? '',
                'email' => $body['email'] ?? '',
                'phone' => $body['phone'] ?? '',
                'notes' => $body['notes'] ?? '',
                'terms_accepted' => $body['terms_accepted'] ?? false,
                'consent_term_id' => $body['consent_term_id'] ?? 0,
            ]);
            $this->respondJson(200, $result);
        } catch (TeleconsultationRateLimitException $e) {
            $this->respondJson(429, ['error' => $e->getMessage(), 'code' => 'RATE_LIMITED']);
        } catch (HoldExpiredException $e) {
            $this->respondJson(410, ['error' => $e->getMessage(), 'code' => 'HOLD_EXPIRED']);
        } catch (BookingConflictException $e) {
            $this->respondJson(409, ['error' => $e->getMessage(), 'code' => 'SLOT_CONFLICT']);
        } catch (InvalidArgumentException $e) {
            $this->respondJson(422, ['error' => $e->getMessage(), 'code' => 'UNPROCESSABLE_ENTITY']);
        } catch (\Throwable $e) {
            $this->internalError($e, 'Erro interno ao confirmar agendamento.');
        }
    }

    /**
     * POST /api/v1/booking/release
     */
    public function releaseHold(): void
    {
        $holdToken = trim((string)($this->body()['hold_token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $holdToken)) {
            $this->respondJson(400, ['error' => 'hold_token é obrigatório.', 'code' => 'MISSING_HOLD_TOKEN']);
            return;
        }
        $released = $this->service->releaseHold($holdToken);
        $this->respondJson(200, ['success' => true, 'released' => $released]);
    }

    /**
     * GET /agendar/{professionalId}: página pública contextual do profissional.
     * Sem profissional apto (inexistente, inativo ou com autoagendamento desligado): 404.
     * Não lista profissionais de outras clínicas (marketplace está fora do escopo).
     */
    public function publicBookingPage(array $params = []): void
    {
        $profId = isset($params['professionalId']) ? (int)$params['professionalId'] : (isset($_GET['prof']) ? (int)$_GET['prof'] : 0);
        $selectedProf = $this->service->publicProfessional($profId);
        if ($selectedProf === null) {
            http_response_code(404);
            header('Cache-Control: no-store');
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Agendamento online indisponível - ClyoNutri</title></head>'
                . '<body style="font-family: system-ui, sans-serif; max-width: 560px; margin: 48px auto; padding: 0 16px; color: #0f172a;">'
                . '<main><h1 style="font-size: 1.4rem;">Agendamento online indisponível</h1>'
                . '<p>Este link de agendamento não está ativo. Peça ao seu nutricionista o link atualizado ou entre em contato com o consultório.</p></main></body></html>';
            return;
        }
        $professionals = [$selectedProf];
        $profId = $selectedProf['id'];
        $clinicId = $selectedProf['clinic_id'];
        $settings = $this->service->getSettings($clinicId);
        $consentTerm = $this->service->activeClinicalCareTerm();

        require __DIR__ . '/../../../views/booking/public_booking.php';
    }

}
