<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\PublicBookingRateLimiter;
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

    /** Limite por IP (hash; o IP não é gravado em claro). Lança 429 via exceção (SEC-F003). */
    private function rateLimit(string $bucket, int $max, int $windowSeconds): void
    {
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $bookingLimiter = new PublicBookingRateLimiter($this->db);
        $res = $bookingLimiter->attempt($bucket, $clientIp);
        if (!$res['allowed']) {
            if (!headers_sent()) {
                header('Retry-After: ' . (int)$res['retry_after']);
            }
            throw new TeleconsultationRateLimitException('Muitas tentativas. Aguarde um instante e tente novamente.');
        }

        // Ajustável por ambiente (ex.: BOOKING_RATE_LIMIT_HOLD=50); o padrão é o valor de produção.
        $env = getenv('BOOKING_RATE_LIMIT_' . strtoupper($bucket));
        if (is_string($env) && ctype_digit($env) && (int)$env > 0) {
            $max = (int)$env;
        }
        (new TeleconsultationRateLimiter($this->db))->hit('booking_' . $bucket, $clientIp, $max, $windowSeconds);
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
            (new \ClyoNutri\Domain\Booking\BookingFlowMetrics($this->db))->count($prof['clinic_id'],'slots_requested');
            $this->respondJson(200, [
                'timezone' => \ClyoNutri\Domain\Booking\BookingCalendarService::tz()->getName(),
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
        $this->completeBooking(false);
    }

    public function confirmAuthenticatedBooking(): void
    {
        $this->completeBooking(true);
    }

    public function patientBookingContext(): void
    {
        try {
            $this->respondJson(200, ['patient' => $this->service->authenticatedPatient()]);
        } catch (BookingUnavailableException $e) {
            $this->respondJson(404, ['error' => $e->getMessage(), 'code' => 'BOOKING_NOT_AVAILABLE']);
        }
    }

    private function completeBooking(bool $authenticated): void
    {
        $body = $this->body();
        $holdToken = trim((string)($body['hold_token'] ?? ''));
        if ($holdToken === '') {
            $this->respondJson(400, ['error' => 'hold_token é obrigatório.', 'code' => 'MISSING_HOLD_TOKEN']);
            return;
        }

        try {
            $this->rateLimit('confirm', 20, 600);
            $method = $authenticated ? 'confirmBookingForPatient' : 'confirmBooking';
            $result = $this->service->$method($holdToken, [
                'name' => $body['name'] ?? '',
                'email' => $body['email'] ?? '',
                'phone' => $body['phone'] ?? '',
                'notes' => $body['notes'] ?? '',
                'terms_accepted' => $body['terms_accepted'] ?? false,
                'consent_term_id' => $body['consent_term_id'] ?? 0,
            ]);
            $this->respondJson(200, $result);
        } catch (BookingUnavailableException $e) {
            $this->respondJson(404, ['error' => $e->getMessage(), 'code' => 'BOOKING_NOT_AVAILABLE']);
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
    public function waitlistPage(): void
    {
        $profId=(int)($_GET['prof']??0);$selectedProf=$this->service->publicProfessional($profId);
        $waitlistCanJoin=$selectedProf && !empty((new \ClyoNutri\Domain\Booking\BookingCalendarService($this->db))->policy($selectedProf['clinic_id'])['waitlist_enabled']);
        if(!$selectedProf)$selectedProf=['name'=>'Seus pedidos','clinic_name'=>'Portal do Paciente'];
        header('Cache-Control: no-store');$consentTerm=$this->service->activeClinicalCareTerm();
        require __DIR__.'/../../../views/booking/waitlist.php';
    }

    public function waitlistApi(string $action, int $id=0): void
    {
        try {
            $service=new \ClyoNutri\Domain\Booking\BookingWaitlistService($this->db);
            if($action==='list')$result=['items'=>$service->mine()];
            elseif($action==='join'){$this->rateLimit('waitlist',10,600);$result=$service->join($this->body());}
            elseif($action==='cancel'){$service->cancel($id);$result=['cancelled'=>true];}
            else throw new \InvalidArgumentException('Ação inválida.');
            $this->respondJson(200,$result);
        }catch(BookingUnavailableException $e){$this->respondJson(404,['error'=>$e->getMessage()]);}
        catch(\InvalidArgumentException $e){$this->respondJson(422,['error'=>$e->getMessage()]);}
        catch(TeleconsultationRateLimitException $e){$this->respondJson(429,['error'=>'Muitas tentativas. Aguarde e tente novamente.']);}
        catch(\Throwable $e){$this->internalError($e,'Não foi possível atualizar a lista de espera.');}
    }

    public function publicBookingPage(array $params = []): void
    {
        if (!PublicBookingRateLimiter::checkOrRespond($this->db, 'booking_page', 'html')) {
            return;
        }

        $identifier = (string)($params['professionalId'] ?? ($params['identifier'] ?? ($_GET['prof'] ?? '')));
        $selectedProf = null;
        if ($identifier !== '' && ctype_digit($identifier)) {
            // NI2-596: link antigo com número. Enquanto BOOKING_LEGACY_NUMERIC_LINKS estiver ligado
            // (padrão até a data de desligamento no LEIA-ME), redireciona (302, não fica em cache) para o
            // apelido único. Desligado: 404, e o número deixa de revelar profissionais.
            $legacy = getenv('BOOKING_LEGACY_NUMERIC_LINKS');
            $legacy = $legacy === false ? ($_ENV['BOOKING_LEGACY_NUMERIC_LINKS'] ?? 'true') : $legacy;
            $prof = filter_var($legacy, FILTER_VALIDATE_BOOL) ? $this->service->publicProfessional((int)$identifier) : null;
            if ($prof !== null) {
                $slug = (new \ClyoNutri\Domain\Booking\PublicBookingSlug($this->db))->ensure((int)$prof['id'], (string)$prof['name']);
                if ($slug !== null) {
                    header('Cache-Control: no-store');
                    header('Location: /agendar/' . rawurlencode($slug), true, 302);
                    return;
                }
                $selectedProf = $prof; // banco ainda sem a migração 77: mantém o comportamento da v53
            }
        } elseif ($identifier !== '') {
            $selectedProf = $this->service->publicProfessional($identifier);
        }
        if ($selectedProf === null) {
            http_response_code(404);
            header('Cache-Control: no-store');
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Agendamento online indisponível - Clyo Nutri</title></head>'
                . '<body style="font-family: system-ui, sans-serif; max-width: 560px; margin: 48px auto; padding: 0 16px; color: var(--botanical-forest-900, #0F3E17);">'
                . '<main><h1 style="font-size: 1.4rem;">Agendamento online indisponível</h1>'
                . '<p>Este link de agendamento não está ativo. Peça ao seu nutricionista o link atualizado ou entre em contato com o consultório.</p></main></body></html>';
            return;
        }
        $professionals = [$selectedProf];
        $profId = $selectedProf['id'];
        $clinicId = $selectedProf['clinic_id'];
        $settings = $this->service->getSettings($clinicId);
        $consentTerm = $this->service->activeClinicalCareTerm();
        $waitlistEnabled = !empty((new \ClyoNutri\Domain\Booking\BookingCalendarService($this->db))->policy($clinicId)['waitlist_enabled']);
        // NI2-603: sem horários confirmados (ou sem dias de atendimento), a página diz que a agenda online ainda não
        // está disponível, em vez de pedir para a paciente tentar outro dia.
        try {
            $bookingState = (new \ClyoNutri\Domain\Booking\BookingSetupState($this->db))->forProfessional((int)$clinicId, (int)$profId);
        } catch (\Throwable $e) {
            $bookingState = \ClyoNutri\Domain\Booking\BookingSetupState::NO_SLOTS;
        }
        $bookingUnavailable = in_array($bookingState, [\ClyoNutri\Domain\Booking\BookingSetupState::HOURS_UNCONFIRMED, \ClyoNutri\Domain\Booking\BookingSetupState::NO_HOURS, \ClyoNutri\Domain\Booking\BookingSetupState::DISABLED], true);
        $bookingNoSlotsSoon = $bookingState === \ClyoNutri\Domain\Booking\BookingSetupState::NO_SLOTS;

        require __DIR__ . '/../../../views/booking/public_booking.php';
    }

    public const DIRECTORY_TOPICS = [
        'Emagrecimento',
        'Reeducação Alimentar',
        'Hipertrofia e Esporte',
        'Saúde Intestinal',
        'Diabetes e Metabólica',
        'Saúde da Mulher',
        'Teleconsulta',
        'Presencial',
    ];

    /**
     * GET /nutricionistas: vitrine pública de nutricionistas com link online e autoatendimento ativos.
     */
    public function publicDirectoryPage(): void
    {
        if (!PublicBookingRateLimiter::checkOrRespond($this->db, 'directory', 'html')) {
            return;
        }

        $query = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 120, 'UTF-8')) : '';
        $rawTopic = is_string($_GET['tema'] ?? null) ? trim(mb_substr($_GET['tema'], 0, 80, 'UTF-8')) : '';
        $topic = '';
        if ($rawTopic !== '') {
            foreach (self::DIRECTORY_TOPICS as $allowed) {
                if (strcasecmp($allowed, $rawTopic) === 0) {
                    $topic = $allowed;
                    break;
                }
            }
        }
        try {
            $professionals = $this->service->listPublicDirectory($query !== '' ? $query : null, $topic !== '' ? $topic : null);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][directory] ' . get_class($e) . ': ' . $e->getMessage());
            $professionals = [];
        }
        $timezoneName = \ClyoNutri\Domain\Booking\BookingCalendarService::tz()->getName();
        if (!headers_sent()) {
            header('Cache-Control: no-store');
        }
        require __DIR__ . '/../../../views/booking/public_directory.php';
    }

}
