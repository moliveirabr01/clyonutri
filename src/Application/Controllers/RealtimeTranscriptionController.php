<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\EvolutionApprovalService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\NutritionalSummaryGenerator;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\RealtimeTranscriptionService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentService;
use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Controller de API para Transcrição em Tempo Real na Consulta (NI2-820).
 * Rotas seguras por tenant para consentimento, streaming de segmentos,
 * alternância de modo (ao vivo/discreto), geração de minuta e aprovação.
 */
class RealtimeTranscriptionController
{
    private PDO $db;
    private TranscriptionConsentService $consentService;
    private RealtimeTranscriptionService $transcriptionService;
    private NutritionalSummaryGenerator $summaryGenerator;
    private EvolutionApprovalService $approvalService;
    private AppointmentService $appointmentService;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->consentService = new TranscriptionConsentService($this->db);
        $this->transcriptionService = new RealtimeTranscriptionService($this->db, $this->consentService);
        $this->summaryGenerator = new NutritionalSummaryGenerator();
        $this->approvalService = new EvolutionApprovalService($this->db);
        $this->appointmentService = new AppointmentService();
    }

    /**
     * POST /api/consulta/{id}/transcription/consent  (DESCONTINUADO — NI2-830 / SEC-001)
     * O profissional não concede aceite em nome do paciente. Esta rota não grava nada; só registra a tentativa na
     * auditoria e orienta a pedir o aceite ao paciente (POST .../consent/request).
     */
    public function grantConsent(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        try {
            (new \ClyoNutri\Core\Audit\AuditLogger($this->db))->log('AI_TRANSCRIPTION_CONSENT_GRANT_DENIED', 'appointment', (string)$appointmentId, [
                'patient_id' => (int)$app['patient_id'], 'user_id' => (int)TenantContext::getUserId(), 'reason' => 'professional_cannot_grant',
            ]);
        } catch (Throwable $e) {
            error_log('AI_TRANSCRIPTION_CONSENT_GRANT_DENIED audit failed: ' . get_class($e));
        }
        $this->jsonResponse([
            'error' => 'O aceite deve ser feito pelo próprio paciente. Peça o aceite ao paciente: ele recebe um link ou usa o portal e decide por conta própria.',
            'consent_required' => true,
            'request_endpoint' => '/api/consulta/' . $appointmentId . '/transcription/consent/request',
        ], 403);
    }

    /**
     * POST /api/consulta/{id}/transcription/consent/request
     * Pede o aceite ao paciente: devolve link de uso único (15 min). Não concede nada.
     */
    public function requestConsent(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $userId = (int)TenantContext::getUserId();
        $patientId = (int)$app['patient_id'];
        try {
            (new \ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter($this->db))
                ->hit('ai_consent_request', TenantContext::getClinicId() . '|' . $appointmentId, 6, 600);
            $res = $this->consentService->createRequest($appointmentId, $patientId, $userId);
            $payload = ['success' => true, 'created' => $res['created'], 'consent' => $res['consent']];
            if (!empty($res['token'])) {
                $payload['link'] = $this->publicBaseUrl() . '/consentimento-transcricao/' . $res['token'];
            }
            $this->jsonResponse($payload);
        } catch (\ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException $e) {
            $this->jsonResponse(['error' => 'Muitos pedidos de aceite para este atendimento. Aguarde alguns minutos.'], 429);
        } catch (DomainException $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            error_log('transcription consent request failed: ' . get_class($e));
            $this->jsonResponse(['error' => 'Não foi possível criar o pedido de aceite agora.'], 400);
        }
    }

    /**
     * POST /api/consulta/{id}/transcription/consent/revoke
     * Revoga o consentimento.
     */
    public function revokeConsent(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $userId = (int)TenantContext::getUserId();
        $patientId = (int)$app['patient_id'];

        try {
            $revoked = $this->consentService->revokeConsent($appointmentId, $patientId, $userId);
            // Pausa sessão ativa se houver
            $session = $this->transcriptionService->getActiveSession($appointmentId);
            if ($session && in_array($session['status'], ['capturing', 'connected'], true)) {
                $this->transcriptionService->pauseSession((int)$session['id']);
            }
            $this->jsonResponse(['success' => true, 'revoked' => $revoked]);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/consulta/{id}/transcription/session
     * Consulta status da sessão, consentimento, modo e segmentos recuperados.
     */
    public function getSessionStatus(array $params): void
    {
        if (!$this->requireAuth()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $patientId = (int)$app['patient_id'];
        $consentActive = $this->consentService->isConsentActive($appointmentId, $patientId);
        $consent = $this->consentService->getConsent($appointmentId, $patientId) ?? ['state' => 'none'];
        $session = $this->transcriptionService->getActiveSession($appointmentId);

        $segments = [];
        if ($session) {
            $afterSeq = isset($_GET['after_seq']) ? (int)$_GET['after_seq'] : 0;
            $segments = $this->transcriptionService->getSegments((int)$session['id'], $afterSeq);
        }

        $draft = $this->approvalService->getDraftByAppointment($appointmentId);

        $this->jsonResponse([
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'consent_active' => $consentActive,
            'consent' => $consent,
            'session' => $session,
            'segments' => $segments,
            'draft' => $draft,
        ]);
    }

    /**
     * POST /api/consulta/{id}/transcription/start
     * Inicia a sessão de transcrição em tempo real.
     */
    public function startSession(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $userId = (int)TenantContext::getUserId();
        $patientId = (int)$app['patient_id'];
        $mode = (string)($_POST['mode'] ?? 'live');

        try {
            $session = $this->transcriptionService->startSession($appointmentId, $patientId, $userId, $mode);
            $this->jsonResponse(['success' => true, 'session' => $session]);
        } catch (DomainException $e) {
            $this->jsonResponse(['error' => $e->getMessage(), 'requires_consent' => true], 403);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/consulta/{id}/transcription/mode
     * Alterna dinamicamente entre 'live' e 'discrete'.
     */
    public function switchMode(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $newMode = (string)($_POST['mode'] ?? '');
        $session = $this->transcriptionService->getActiveSession($appointmentId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Nenhuma sessão ativa para esta consulta.'], 404);
            return;
        }

        try {
            $updated = $this->transcriptionService->switchMode((int)$session['id'], $newMode);
            $this->jsonResponse(['success' => true, 'session' => $updated]);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/consulta/{id}/transcription/pause
     * Pausa a captação.
     */
    public function pause(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $session = $this->transcriptionService->getActiveSession($appointmentId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Nenhuma sessão ativa para pausar.'], 404);
            return;
        }

        try {
            $updated = $this->transcriptionService->pauseSession((int)$session['id']);
            $this->jsonResponse(['success' => true, 'session' => $updated]);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/consulta/{id}/transcription/resume
     * Retoma a captação.
     */
    public function resume(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $session = $this->transcriptionService->getActiveSession($appointmentId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Nenhuma sessão ativa para retomar.'], 404);
            return;
        }

        try {
            $updated = $this->transcriptionService->resumeSession((int)$session['id']);
            $this->jsonResponse(['success' => true, 'session' => $updated]);
        } catch (DomainException $e) {
            $this->jsonResponse(['error' => $e->getMessage(), 'requires_consent' => true], 403);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/consulta/{id}/transcription/segment
     * Ingestão sequencial de segmentos de texto transcrito em tempo real.
     */
    public function ingestSegment(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $session = $this->transcriptionService->getActiveSession($appointmentId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Nenhuma sessão ativa para receber segmentos.'], 404);
            return;
        }

        $seq = (int)($_POST['sequence_number'] ?? 0);
        $text = (string)($_POST['text'] ?? '');
        $speaker = (string)($_POST['speaker'] ?? 'speaker_uncertain');
        $isFinal = !isset($_POST['is_final']) || (bool)$_POST['is_final'];

        if ($seq <= 0) {
            $this->jsonResponse(['error' => 'sequence_number deve ser um inteiro positivo.'], 400);
            return;
        }

        try {
            $result = $this->transcriptionService->ingestSegment((int)$session['id'], $seq, $text, $speaker, $isFinal);
            $this->jsonResponse(['success' => true, 'segment' => $result]);
        } catch (DomainException $e) {
            $this->jsonResponse(['error' => $e->getMessage(), 'requires_consent' => true], 403);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/consulta/{id}/transcription/finish
     * Encerra a sessão de transcrição e gera a Minuta de Evolução Nutricional estruturada.
     */
    public function finish(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $session = $this->transcriptionService->getActiveSession($appointmentId);
        if (!$session) {
            $this->jsonResponse(['error' => 'Nenhuma sessão ativa para finalizar.'], 404);
            return;
        }

        try {
            // 1. Encerra sessão de transcrição
            $completedSession = $this->transcriptionService->completeSession((int)$session['id']);

            // 2. Extrai todo o texto transcrito
            $fullTranscript = $this->transcriptionService->getAllSegmentsText((int)$session['id']);

            // 3. Gera a minuta estruturada sem alucinações clínicas
            $draftFields = $this->summaryGenerator->generateDraft($fullTranscript);

            // 4. Persiste a minuta como rascunho de evolução para revisão humana
            $draft = $this->approvalService->createOrUpdateDraft((int)$session['id'], $draftFields);

            $this->jsonResponse([
                'success' => true,
                'session' => $completedSession,
                'draft' => $draft,
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/consulta/{id}/transcription/draft
     * Retorna a minuta estruturada de evolução para revisão.
     */
    public function getDraft(array $params): void
    {
        if (!$this->requireAuth()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $draft = $this->approvalService->getDraftByAppointment($appointmentId);
        if (!$draft) {
            $this->jsonResponse(['error' => 'Nenhuma minuta de evolução encontrada.'], 404);
            return;
        }

        $this->jsonResponse(['success' => true, 'draft' => $draft]);
    }

    /**
     * POST /api/consulta/{id}/transcription/draft/approve
     * Nutricionista revisa, edita se necessário, e APROVA formalmente a integração ao prontuário.
     */
    public function approveDraft(array $params): void
    {
        if (!$this->requireAuthAndCsrf()) return;
        $appointmentId = (int)($params['id'] ?? 0);
        $app = $this->requireAppointment($appointmentId);
        if (!$app) return;

        $userId = (int)TenantContext::getUserId();
        $draft = $this->approvalService->getDraftByAppointment($appointmentId);
        if (!$draft) {
            $this->jsonResponse(['error' => 'Minuta não encontrada para aprovação.'], 404);
            return;
        }

        $editedText = isset($_POST['full_evolution_text']) && is_string($_POST['full_evolution_text'])
            ? trim($_POST['full_evolution_text'])
            : null;

        try {
            $result = $this->approvalService->approveAndIntegrate((int)$draft['id'], $userId, $editedText);
            $this->jsonResponse(['success' => true, 'approved' => $result]);
        } catch (Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    // =========================================================================
    // Helpers de Segurança e Resposta
    // =========================================================================

    private function requireAuth(): bool
    {
        $clinicId = TenantContext::getClinicId();
        $userId = TenantContext::getUserId();
        if (!$clinicId || !$userId) {
            $this->jsonResponse(['error' => 'Autenticação necessária.'], 401);
            return false;
        }
        return true;
    }

    private function requireAuthAndCsrf(): bool
    {
        if (!$this->requireAuth()) return false;

        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!Csrf::validate($token)) {
            $this->jsonResponse(['error' => 'Token CSRF inválido ou expirado.'], 403);
            return false;
        }
        return true;
    }

    private function requireAppointment(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            $this->jsonResponse(['error' => 'Identificador de atendimento inválido.'], 400);
            return null;
        }
        $app = $this->appointmentService->getAppointment($appointmentId);
        if (!$app) {
            $this->jsonResponse(['error' => 'Atendimento não encontrado ou acesso não autorizado para a clínica.'], 404);
            return null;
        }
        return $app;
    }

    /** Base pública para o link do paciente: APP_URL quando definido; senão o esquema e o Host desta requisição. */
    private function publicBaseUrl(): string
    {
        $appUrl = rtrim((string)(\ClyoNutri\Core\Security\Env::get('APP_URL', '') ?? ''), '/');
        if ($appUrl !== '' && preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?$#', $appUrl)) {
            return $appUrl;
        }
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        return (\ClyoNutri\Core\Http\Transport::isHttps($_SERVER) ? 'https' : 'http') . '://' . $host;
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
