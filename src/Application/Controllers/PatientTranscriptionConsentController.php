<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentService;
use ClyoNutri\Domain\Clinical\RealtimeTranscription\TranscriptionConsentTerms;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException;
use ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter;
use DomainException;
use PDO;
use Throwable;

/**
 * Lado do PACIENTE no aceite da transcrição da consulta (NI2-830 / SEC-001).
 * Rotas públicas (sem sessão de profissional): link de uso único (/consentimento-transcricao/{token}) e portal do
 * paciente autenticado (/paciente/consentimento). Aqui nasce o único aceite que libera a captação.
 * CSRF dos POST: validado pelo Router (rotas fora de /api/). A página nunca mostra dado clínico.
 */
final class PatientTranscriptionConsentController
{
    private PDO $db;
    private TranscriptionConsentService $service;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->service = new TranscriptionConsentService($this->db);
    }

    // ---------------------------------------------------------------- link de uso único

    /** GET /consentimento-transcricao/{token} */
    public function showLink(array $params = []): void
    {
        $this->headers();
        $token = (string)($params['token'] ?? '');
        if (!$this->guard('view')) return;
        if ($this->professionalSessionPresent()) {
            $this->render(403, ['mode' => 'link', 'screen' => 'professional_session']);
            return;
        }
        $view = $this->service->previewByToken($token);
        if ($view === null || $view['state'] !== 'pending') {
            $this->render($view === null ? 404 : 410, ['mode' => 'link', 'screen' => 'unavailable']);
            return;
        }
        $this->render(200, ['mode' => 'link', 'screen' => 'ask', 'view' => $view, 'token' => $token]);
    }

    /** POST /consentimento-transcricao/{token} (decision=accept|reject) */
    public function decideLink(array $params = []): void
    {
        $this->headers();
        $token = (string)($params['token'] ?? '');
        if (!$this->guard('decide')) return;
        if ($this->professionalSessionPresent()) {
            $this->render(403, ['mode' => 'link', 'screen' => 'professional_session']);
            return;
        }
        $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
        $sha = is_string($_POST['terms_sha256'] ?? null) ? $_POST['terms_sha256'] : '';
        try {
            $res = $this->service->decideByToken($token, $decision, $sha, $this->ip(), $this->ua());
        } catch (DomainException $e) {
            $this->render(410, ['mode' => 'link', 'screen' => 'unavailable', 'message' => $e->getMessage()]);
            return;
        } catch (Throwable $e) {
            error_log('transcription consent decide failed: ' . get_class($e));
            $this->render(503, ['mode' => 'link', 'screen' => 'unavailable', 'message' => 'Não foi possível registrar agora. Tente de novo em instantes.']);
            return;
        }
        $this->render(200, ['mode' => 'link', 'screen' => $res['state'] === 'accepted' ? 'accepted' : 'rejected', 'revoke_token' => $res['revoke_token'], 'consent' => $res['consent']]);
    }

    /** POST /consentimento-transcricao-revogar (revoke_token) */
    public function revokeLink(array $params = []): void
    {
        $this->headers();
        if (!$this->guard('revoke')) return;
        $code = is_string($_POST['revoke_token'] ?? null) ? $_POST['revoke_token'] : '';
        try {
            $this->service->revokeByPatientToken($code, $this->ip(), $this->ua());
        } catch (DomainException $e) {
            $this->render(410, ['mode' => 'link', 'screen' => 'unavailable', 'message' => $e->getMessage()]);
            return;
        } catch (Throwable $e) {
            error_log('transcription consent revoke failed: ' . get_class($e));
            $this->render(503, ['mode' => 'link', 'screen' => 'unavailable', 'message' => 'Não foi possível revogar agora. Avise o profissional para encerrar a captação.']);
            return;
        }
        $this->render(200, ['mode' => 'link', 'screen' => 'revoked']);
    }

    // ---------------------------------------------------------------- portal do paciente

    /** GET /paciente/consentimento */
    public function portalPage(array $params = []): void
    {
        $this->headers(true);
        $session = PatientAppController::portalSession($this->db);
        if ($session === null) {
            header('Location: /paciente/app?sessao=1', true, 303);
            return;
        }
        $items = $this->service->listForPatient((int)$session['patient_id'], (int)$session['clinic_id']);
        $this->render(200, ['mode' => 'portal', 'screen' => 'portal', 'items' => $items]);
    }

    /** POST /paciente/consentimento/{id}/decidir */
    public function portalDecide(array $params = []): void
    {
        $this->headers(true);
        $session = PatientAppController::portalSession($this->db);
        if ($session === null) {
            header('Location: /paciente/app?sessao=1', true, 303);
            return;
        }
        if (!$this->guard('portal')) return;
        $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
        $sha = is_string($_POST['terms_sha256'] ?? null) ? $_POST['terms_sha256'] : '';
        $message = null;
        try {
            $res = $this->service->decideByPortal((int)($params['id'] ?? 0), (int)$session['patient_id'], (int)$session['clinic_id'], $decision, $sha, $this->ip(), $this->ua());
            $message = $res['state'] === 'accepted' ? 'Aceite registrado. A transcrição só começa quando o profissional iniciar.' : 'Recusa registrada. A consulta segue normalmente, sem transcrição.';
        } catch (DomainException $e) {
            $message = $e->getMessage();
        } catch (Throwable $e) {
            error_log('transcription consent portal decide failed: ' . get_class($e));
            $message = 'Não foi possível registrar agora. Tente de novo.';
        }
        $items = $this->service->listForPatient((int)$session['patient_id'], (int)$session['clinic_id']);
        $this->render(200, ['mode' => 'portal', 'screen' => 'portal', 'items' => $items, 'message' => $message]);
    }

    /** POST /paciente/consentimento/{id}/revogar */
    public function portalRevoke(array $params = []): void
    {
        $this->headers(true);
        $session = PatientAppController::portalSession($this->db);
        if ($session === null) {
            header('Location: /paciente/app?sessao=1', true, 303);
            return;
        }
        if (!$this->guard('portal')) return;
        $message = null;
        try {
            $this->service->revokeByPatientPortal((int)($params['id'] ?? 0), (int)$session['patient_id'], (int)$session['clinic_id'], $this->ip(), $this->ua());
            $message = 'Aceite revogado. A captação para na hora e nenhum novo trecho é gravado.';
        } catch (DomainException $e) {
            $message = $e->getMessage();
        } catch (Throwable $e) {
            error_log('transcription consent portal revoke failed: ' . get_class($e));
            $message = 'Não foi possível revogar agora. Avise o profissional.';
        }
        $items = $this->service->listForPatient((int)$session['patient_id'], (int)$session['clinic_id']);
        $this->render(200, ['mode' => 'portal', 'screen' => 'portal', 'items' => $items, 'message' => $message]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Link com token na URL: no-referrer. Portal (sem token na URL): same-origin, porque com no-referrer o navegador
     * manda "Origin: null" no POST do formulário e a checagem de origem do portal bloquearia o próprio paciente.
     */
    private function headers(bool $portal = false): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
            header('Referrer-Policy: ' . ($portal ? 'same-origin' : 'no-referrer'));
            header('X-Robots-Tag: noindex, nofollow');
        }
    }

    /** Limite por IP contra adivinhação de token e abuso. Falha fechada (429). */
    private function guard(string $action): bool
    {
        $max = $action === 'view' ? 60 : 20;
        try {
            (new TeleconsultationRateLimiter($this->db))->hit('ai_consent_' . $action, $this->ip(), $max, 600);
            return true;
        } catch (TeleconsultationRateLimitException $e) {
            $this->render(429, ['mode' => 'link', 'screen' => 'unavailable', 'message' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.']);
            return false;
        } catch (Throwable $e) {
            error_log('transcription consent rate limiter unavailable: ' . get_class($e));
            $this->render(503, ['mode' => 'link', 'screen' => 'unavailable', 'message' => 'Não foi possível continuar agora. Tente de novo em instantes.']);
            return false;
        }
    }

    /**
     * Aceite não vale vindo de um navegador com sessão de profissional aberta: é o profissional que estaria clicando.
     * O paciente usa o próprio aparelho ou o portal com a própria senha.
     */
    private function professionalSessionPresent(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['user_id']);
    }

    private function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function ua(): string
    {
        return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    private function render(int $status, array $ctx): void
    {
        http_response_code($status);
        $csrf = Csrf::token();
        $terms = TranscriptionConsentTerms::sections();
        $termsVersion = TranscriptionConsentTerms::VERSION;
        $termsSha = TranscriptionConsentTerms::sha256();
        extract($ctx, EXTR_SKIP);
        require __DIR__ . '/../../../views/patient/transcription_consent.php';
    }
}
