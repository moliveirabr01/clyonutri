<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationLegacyRouteException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationStateException;
use ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter;
use ClyoNutri\Domain\Telehealth\TeleconsultationDraftService;
use ClyoNutri\Domain\Telehealth\TeleconsultationNotifier;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;
use ClyoNutri\Domain\Telehealth\TeleconsultationSignalingService;
use PDO;

/**
 * Consultório Online — HTTP (NI2-221).
 *
 * Nutricionista: rotas autenticadas; o usuário logado é convertido em
 * professionals.id e precisa ser o responsável pelo agendamento. Todo POST
 * exige CSRF válido (antes o retorno de Csrf::validate era ignorado).
 *
 * Paciente: convite por token (só hash no banco), CSRF por cabeçalho nas ações,
 * limite de tentativas por IP e por convite. Nenhuma resposta ao paciente
 * contém sala ou credencial antes da admissão.
 *
 * Sinalização WebRTC: credencial curta no cabeçalho X-Teleconsulta-Credential
 * (fora da URL), revalidada junto com o estado da sessão a cada chamada.
 */
class TeleconsultationController
{

    private PDO $db;
    private TeleconsultationService $service;

    public function __construct(?PDO $db = null, ?TeleconsultationService $service = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->service = $service ?? new TeleconsultationService($this->db);
    }

    // ================================================================
    // Nutricionista
    // ================================================================

    /**
     * GET sem efeito colateral (F07): abre a sessão existente ou mostra a confirmação.
     * A criação acontece só no POST (createFromAppointment), com CSRF.
     */
    public function openFromAppointment(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext();
        $appointmentId = (int)($params['appointmentId'] ?? 0);
        $stmt = $this->db->prepare('SELECT id, professional_id FROM teleconsultation_sessions WHERE appointment_id = :a AND clinic_id = :c');
        $stmt->execute(['a' => $appointmentId, 'c' => $clinicId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && (int)$existing['professional_id'] === $professionalId) {
            $this->redirect('/teleconsulta/' . (int)$existing['id'] . '/atendimento');
        }
        // NI2-24 (B1): consulta de outra clínica (ou inexistente) não mostra a tela de abrir o consultório.
        $own = $this->db->prepare('SELECT 1 FROM appointments WHERE id = :a AND clinic_id = :c');
        $own->execute(['a' => $appointmentId, 'c' => $clinicId]);
        if (!$own->fetchColumn()) {
            http_response_code(404);
            echo 'Consulta não encontrada.';
            return;
        }
        $csrf = Csrf::generate();
        $this->securityHeaders();
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Consultório Online</title><link rel="stylesheet" href="/assets/css/teleconsulta.css"></head><body>'
            . '<main class="tc-narrow"><div class="tc-card"><h2>Abrir o Consultório Online</h2>'
            . '<p>Isso cria a sala desta consulta e gera o convite do paciente.</p>'
            . '<form method="POST" action="/teleconsulta/sessao-agenda/' . $appointmentId . '">'
            . '<input type="hidden" name="_csrf" value="' . $e($csrf) . '">'
            . '<div class="tc-controls"><button type="submit" class="tc-btn tc-btn-primary">Abrir consultório</button>'
            . '<a class="tc-btn" style="display:inline-flex;align-items:center;text-decoration:none" href="/consulta/' . $appointmentId . '">Voltar</a></div>'
            . '</form></div></main></body></html>';
    }

    public function createFromAppointment(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext();
        $this->requireCsrfForm();
        try {
            $result = $this->service->scheduleSession((int)($params['appointmentId'] ?? 0), $professionalId, $clinicId);
            if ($result['created']) {
                $this->flashInvite((int)$result['session_id'], (string)$result['raw_patient_token']);
            }
            $this->redirect('/teleconsulta/' . (int)$result['session_id'] . '/atendimento');
        } catch (\Throwable $e) {
            $this->htmlError($e, '/agenda');
        }
    }

    public function professionalRoom(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext();
        try {
            $session = $this->service->getSessionForProfessional((int)($params['id'] ?? 0), $professionalId, $clinicId);
        } catch (\Throwable $e) {
            $this->htmlError($e, '/agenda');
            return;
        }

        $stmt = $this->db->prepare('
            SELECT id, version, title, created_at FROM clinical_records
            WHERE patient_id = :p AND clinic_id = :c ORDER BY version DESC LIMIT 5
        ');
        $stmt->execute(['p' => $session['patient_id'], 'c' => $clinicId]);
        $recentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $invite = $this->takeInviteFlash((int)$session['id']);
        $draft = $_SESSION['tele_draft'][(int)$session['id']] ?? null; // texto devolvido após erro (F09)
        unset($_SESSION['tele_draft'][(int)$session['id']]);
        $notice = $_SESSION['tele_notice'] ?? null;
        unset($_SESSION['tele_notice']);
        $lastDelivery = (new TeleconsultationNotifier($this->db, $this->service))->lastDelivery((int)$session['id'], $clinicId);
        $stmtEmail = $this->db->prepare('SELECT email FROM patients WHERE id = :id AND clinic_id = :c');
        $stmtEmail->execute(['id' => $session['patient_id'], 'c' => $clinicId]);
        $patientHasEmail = filter_var((string)$stmtEmail->fetchColumn(), FILTER_VALIDATE_EMAIL) !== false;
        $csrf = Csrf::generate();

        $this->securityHeaders();
        require __DIR__ . '/../../../views/teleconsultation/professional_room.php';
    }

    /** GET JSON — painel do profissional (sala de espera, prontidão da mídia). */
    public function professionalState(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext(true);
        $this->json(fn() => $this->service->professionalState((int)($params['id'] ?? 0), $professionalId, $clinicId));
    }

    /** POST JSON — credencial de mídia do profissional. */
    public function professionalMedia(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext(true);
        $this->requireCsrfHeader();
        $this->json(fn() => $this->service->issueProfessionalMediaCredential((int)($params['id'] ?? 0), $professionalId, $clinicId));
    }

    public function issueInvite(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext();
        $this->requireCsrfForm();
        $sessionId = (int)($params['id'] ?? 0);
        try {
            $result = $this->service->issueInvite($sessionId, $professionalId, $clinicId);
            $this->flashInvite($sessionId, (string)$result['raw_patient_token']);
            $_SESSION['tele_notice'] = 'Novo convite gerado. O link anterior deixou de funcionar.';
        } catch (\Throwable $e) {
            $_SESSION['tele_notice'] = $this->safeMessage($e);
        }
        $this->redirect("/teleconsulta/{$sessionId}/atendimento");
    }

    /** GET JSON — rascunho da evolução (NI2-249). */
    public function draftGet(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext(true);
        $this->json(fn() => (new TeleconsultationDraftService($this->db, $this->service))->get((int)($params['id'] ?? 0), $professionalId, $clinicId));
    }

    /** POST JSON — salvamento automático do rascunho com versão (NI2-249). */
    public function draftSave(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext(true);
        $this->requireCsrfHeader();
        $input = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
        $this->json(function () use ($params, $professionalId, $clinicId, $input) {
            $result = (new TeleconsultationDraftService($this->db, $this->service))->save(
                (int)($params['id'] ?? 0), $professionalId, $clinicId, (int)($input['version'] ?? 0), $input
            );
            if ($result['status'] === 'conflict') {
                http_response_code(409);
            }
            return $result;
        });
    }

    /** Envia o convite por e-mail ao paciente (NI2-239/250). */
    public function emailInvite(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext();
        $this->requireCsrfForm();
        $sessionId = (int)($params['id'] ?? 0);
        try {
            $result = (new TeleconsultationNotifier($this->db, $this->service))->sendInvite($sessionId, $professionalId, $clinicId);
            $_SESSION['tele_notice'] = match ($result['status']) {
                'sent' => 'Convite enviado por e-mail ao paciente.',
                default => 'Convite não enviado: ' . ($result['reason'] ?? 'tente novamente.'),
            };
        } catch (\Throwable $e) {
            $_SESSION['tele_notice'] = $this->safeMessage($e);
        }
        $this->redirect("/teleconsulta/{$sessionId}/atendimento");
    }

    public function admit(array $params = []): void
    {
        $this->professionalAction($params, function (int $sessionId, int $professionalId): void {
            $this->service->admitPatient($sessionId, $professionalId);
        });
    }

    public function reject(array $params = []): void
    {
        $this->professionalAction($params, function (int $sessionId, int $professionalId): void {
            $this->service->rejectPatient($sessionId, $professionalId, (string)($_POST['reason'] ?? 'other'));
        });
    }

    public function recordEvolution(array $params = []): void
    {
        $this->professionalAction($params, function (int $sessionId, int $professionalId): void {
            // Se a gravação falhar, o texto volta ao formulário em vez de se perder.
            $_SESSION['tele_draft'][$sessionId] = [
                'title' => (string)($_POST['title'] ?? ''),
                'notes' => (string)($_POST['clinical_notes'] ?? ''),
                'goals' => (string)($_POST['goals'] ?? ''),
            ];
            $this->service->recordClinicalEvolution(
                $sessionId,
                $professionalId,
                trim((string)($_POST['title'] ?? 'Evolução — teleconsulta')) ?: 'Evolução — teleconsulta',
                [
                    'notes' => trim((string)($_POST['clinical_notes'] ?? '')),
                    'goals' => trim((string)($_POST['goals'] ?? '')),
                    'source' => 'teleconsultation_room',
                ]
            );
            unset($_SESSION['tele_draft'][$sessionId]);
            $_SESSION['tele_notice'] = 'Evolução registrada no prontuário.';
        });
    }

    /** Encerra a mídia e leva à consulta, onde a conclusão clínica é feita explicitamente. */
    public function end(array $params = []): void
    {
        [, $clinicId, $professionalId] = $this->professionalContext();
        $this->requireCsrfForm();
        $sessionId = (int)($params['id'] ?? 0);
        try {
            $result = $this->service->endMedia($sessionId, $professionalId);
            $this->redirect('/consulta/' . (int)$result['appointment_id'] . '?teleconsulta=encerrada');
        } catch (\Throwable $e) {
            $_SESSION['tele_notice'] = $this->safeMessage($e);
            $this->redirect("/teleconsulta/{$sessionId}/atendimento");
        }
    }

    // ================================================================
    // Paciente (convite por token)
    // ================================================================

    /** Nome do cookie do convite do paciente (F05: o token sai da URL). */
    public const PATIENT_COOKIE = 'clyo_tele';
    private const PATIENT_COOKIE_PATH = '/teleconsulta/';

    /**
     * F05 (portado do v11): GET /teleconsulta/sala-espera/{token} e /teleconsulta/sala/{token}.
     * Valida o convite (ou o bilhete do app), grava-o num cookie HttpOnly/SameSite=Lax/Secure (em HTTPS),
     * restrito a /teleconsulta/, e redireciona (303) para a URL limpa — o token sai da barra de endereço,
     * do histórico e de qualquer Referer. Todo uso seguinte revalida o convite no servidor
     * (hash, geração, revogação, janela e estado).
     */
    public function patientLinkExchange(array $params = []): void
    {
        $token = (string)($params['token'] ?? '');
        $this->securityHeaders();
        try {
            $this->patientRateLimit($token);
            [$session, $cookieValue] = $this->service->redeemPatientLink($token);
        } catch (\Throwable $e) {
            $this->clearPatientCookie();
            $this->patientErrorPage($e);
            return;
        }
        $this->setPatientCookie($cookieValue);
        $target = in_array($session['status'], ['admitted', 'in_progress'], true) ? '/teleconsulta/sala' : '/teleconsulta/sala-espera';
        header('Location: ' . $target, true, 303);
        exit;
    }

    public function patientWaitingRoom(array $params = []): void
    {
        $token = $this->patientCookieToken();
        try {
            $this->patientRateLimit($token);
            $session = $this->service->getSessionByPatientToken($token);
        } catch (\Throwable $e) {
            $this->clearPatientCookie();
            $this->patientErrorPage($e);
            return;
        }
        $term = $this->service->getActiveConsentTerm(); // consent_terms 2026.10-r1 (migração 35)
        if ($term === null) {
            $this->patientErrorPage(new TeleconsultationStateException('Termo da teleconsulta não configurado.'));
            return;
        }
        $csrf = Csrf::generate();
        $this->securityHeaders();
        require __DIR__ . '/../../../views/teleconsultation/patient_waiting.php';
    }

    public function patientSubmitDeviceCheck(array $params = []): void
    {
        $this->patientJson($params, fn(string $t, array $in) => $this->service->submitDeviceCheck($t, $in));
    }

    public function patientAcceptConsent(array $params = []): void
    {
        $this->patientJson($params, fn(string $t, array $in) => $this->service->acceptConsent(
            $t,
            (int)($in['term_id'] ?? 0),
            $this->clientIp(),
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
        ));
    }

    public function patientEnterWaiting(array $params = []): void
    {
        $this->patientJson($params, fn(string $t) => $this->service->enterWaitingRoom($t));
    }

    public function patientStatus(array $params = []): void
    {
        $this->patientJson($params, fn(string $t) => $this->service->checkWaitingRoomStatus($t), false);
    }

    public function patientMedia(array $params = []): void
    {
        $this->patientJson($params, fn(string $t) => $this->service->issuePatientMediaCredential($t));
    }

    public function patientConsultationRoom(array $params = []): void
    {
        $token = $this->patientCookieToken();
        try {
            $this->patientRateLimit($token);
            $session = $this->service->getSessionByPatientToken($token);
        } catch (\Throwable $e) {
            $this->clearPatientCookie();
            $this->patientErrorPage($e);
            return;
        }
        if (!in_array($session['status'], ['admitted', 'in_progress'], true)) {
            $this->redirect('/teleconsulta/sala-espera');
        }
        $csrf = Csrf::generate();
        $this->securityHeaders();
        require __DIR__ . '/../../../views/teleconsultation/patient_room.php';
    }

    /** POST com token na URL (rotas antigas): não existem mais; contam no rate limit e devolvem 404. */
    public function patientLegacyTokenApi(array $params = []): void
    {
        $this->json(function () use ($params) {
            $this->patientRateLimit((string)($params['token'] ?? ''));
            throw new TeleconsultationLegacyRouteException();
        });
    }

    // ================================================================
    // Sinalização WebRTC (ambos os participantes)
    // ================================================================

    public function signalSend(array $params = []): void
    {
        $input = json_decode((string)file_get_contents('php://input'), true);
        $this->json(fn() => (new TeleconsultationSignalingService($this->db, $this->service))->send(
            (string)($params['room'] ?? ''),
            (string)($_SERVER['HTTP_X_TELECONSULTA_CREDENTIAL'] ?? ''),
            (string)($input['type'] ?? ''),
            $input['payload'] ?? null
        ));
    }

    public function signalPoll(array $params = []): void
    {
        $this->json(fn() => (new TeleconsultationSignalingService($this->db, $this->service))->poll(
            (string)($params['room'] ?? ''),
            (string)($_SERVER['HTTP_X_TELECONSULTA_CREDENTIAL'] ?? ''),
            (int)($_GET['after'] ?? 0)
        ));
    }

    // ================================================================
    // Infraestrutura
    // ================================================================

    /** @return array{0: int, 1: int, 2: int} [userId, clinicId, professionalId] */
    private function professionalContext(bool $json = false): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
        if ($userId === 0 || $clinicId === 0) {
            $json ? $this->jsonExit(401, 'Sessão expirada. Entre novamente.') : $this->redirect('/login');
        }
        TenantContext::setClinicId($clinicId);
        try {
            return [$userId, $clinicId, $this->service->resolveProfessionalIdForUser($userId, $clinicId)];
        } catch (\Throwable $e) {
            $json ? $this->jsonExit(403, 'Somente o nutricionista responsável acessa o Consultório Online.') : $this->htmlError($e, '/agenda');
            exit;
        }
    }

    private function professionalAction(array $params, callable $action): void
    {
        [, , $professionalId] = $this->professionalContext();
        $this->requireCsrfForm();
        $sessionId = (int)($params['id'] ?? 0);
        try {
            $action($sessionId, $professionalId);
        } catch (\Throwable $e) {
            $_SESSION['tele_notice'] = $this->safeMessage($e);
        }
        $this->redirect("/teleconsulta/{$sessionId}/atendimento");
    }

    private function patientJson(array $params, callable $action, bool $mutating = true): void
    {
        $token = $this->patientCookieToken(); // F05: o convite vem do cookie, nunca da URL
        $this->json(function () use ($token, $action, $mutating) {
            $this->patientRateLimit($token);
            if ($mutating) {
                $this->requireCsrfHeader();
            }
            $input = json_decode((string)file_get_contents('php://input'), true);
            return $action($token, is_array($input) ? $input : []);
        });
    }

    private function patientRateLimit(string $token): void
    {
        $limiter = new TeleconsultationRateLimiter($this->db);
        $limiter->hit('patient_ip', $this->clientIp(), 120, 60);
        $limiter->hit('patient_token', $token, 60, 60);
    }

    private function requireCsrfForm(): void
    {
        if (!Csrf::validate(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : null)) {
            http_response_code(419);
            echo 'Sessão do formulário expirada. Volte e tente novamente.';
            exit;
        }
    }

    private function requireCsrfHeader(): void
    {
        if (!Csrf::validate(isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : null)) {
            throw new TenantSecurityException('Sessão da página expirada. Recarregue a página.');
        }
    }

    private function json(callable $work): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            echo json_encode($work(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            http_response_code($this->statusFor($e));
            echo json_encode(['error' => $this->safeMessage($e)], JSON_UNESCAPED_UNICODE);
        }
    }

    private function jsonExit(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function statusFor(\Throwable $e): int
    {
        return match (true) {
            $e instanceof TeleconsultationLegacyRouteException => 404,
            $e instanceof TenantSecurityException => 403,
            $e instanceof TeleconsultationStateException => 409,
            $e instanceof TeleconsultationRateLimitException => 429,
            $e instanceof ExternalMediaProviderBlockedException => 503,
            $e instanceof \InvalidArgumentException => 422,
            default => 500,
        };
    }

    /** Mensagens de domínio são seguras para o usuário; o resto vira mensagem genérica. */
    private function safeMessage(\Throwable $e): string
    {
        if ($e instanceof ExternalMediaProviderBlockedException) {
            return 'Vídeo indisponível no momento: o serviço de mídia não está configurado ou não respondeu.';
        }
        if ($e instanceof TeleconsultationLegacyRouteException) {
            return 'Teleconsulta indisponível.';
        }
        if ($e instanceof TenantSecurityException || $e instanceof TeleconsultationStateException
            || $e instanceof TeleconsultationRateLimitException || $e instanceof \InvalidArgumentException) {
            return $e->getMessage();
        }
        error_log('[teleconsulta] ' . get_class($e) . ' em ' . $e->getFile() . ':' . $e->getLine());
        return 'Não foi possível concluir a ação. Tente novamente.';
    }

    private function htmlError(\Throwable $e, string $back): void
    {
        http_response_code($this->statusFor($e));
        $this->securityHeaders();
        echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Teleconsulta</title><body style="font-family:Inter,system-ui,sans-serif;max-width:560px;margin:64px auto;padding:0 16px;color:#1F2937">'
            . '<h1 style="font-size:22px;color:#0F2747">Não foi possível abrir a teleconsulta</h1><p>'
            . htmlspecialchars($this->safeMessage($e)) . '</p><p><a href="' . htmlspecialchars($back) . '">Voltar</a></p></body></html>';
        exit;
    }

    private function patientErrorPage(\Throwable $e): void
    {
        http_response_code($this->statusFor($e));
        $this->securityHeaders();
        $message = ($e instanceof TeleconsultationRateLimitException || $e instanceof \ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationTicketUsedException)
            ? $e->getMessage()
            : 'Este link de teleconsulta não está disponível. Ele pode ter expirado, sido substituído ou a consulta pode ter sido encerrada. Fale com seu nutricionista para receber um novo convite.';
        echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Teleconsulta</title><body style="font-family:Inter,system-ui,sans-serif;max-width:560px;margin:64px auto;padding:0 16px;color:#1F2937">'
            . '<h1 style="font-size:22px;color:#0F2747">Teleconsulta indisponível</h1><p>' . htmlspecialchars($message) . '</p></body></html>';
        exit;
    }

    private function flashInvite(int $sessionId, string $rawToken): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['tele_invite'][$sessionId] = $rawToken;
    }

    /** O link em claro é exibido uma única vez ao profissional. */
    private function takeInviteFlash(int $sessionId): ?string
    {
        $raw = $_SESSION['tele_invite'][$sessionId] ?? null;
        unset($_SESSION['tele_invite'][$sessionId]);
        if ($raw === null) {
            return null;
        }
        $base = rtrim((string)Env::get('APP_URL', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/teleconsulta/sala-espera/' . $raw;
    }

    private function patientCookieToken(): string
    {
        $raw = $_COOKIE[self::PATIENT_COOKIE] ?? '';
        return is_string($raw) ? $raw : '';
    }

    private function setPatientCookie(string $token): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::PATIENT_COOKIE, $token, [
            'expires' => 0, // cookie de sessão; validade real é a do convite (revalidada no servidor)
            'path' => self::PATIENT_COOKIE_PATH,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax', // Lax: o link chega por e-mail/app (navegação de outro site)
        ]);
        $_COOKIE[self::PATIENT_COOKIE] = $token;
    }

    private function clearPatientCookie(): void
    {
        unset($_COOKIE[self::PATIENT_COOKIE]);
        if (headers_sent()) {
            return;
        }
        setcookie(self::PATIENT_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => self::PATIENT_COOKIE_PATH,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || str_starts_with(strtolower((string)Env::get('APP_URL', '')), 'https://');
    }

    private function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer'); // o link de convite traz o token até a troca pelo cookie
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self)');
    }

    private function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }
}
