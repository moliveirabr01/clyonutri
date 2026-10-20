<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Patient\PatientRepository;
use ClyoNutri\Domain\PreConsultation\PreConsultationException;
use ClyoNutri\Domain\PreConsultation\PreConsultationMail;
use ClyoNutri\Domain\PreConsultation\PreConsultationService;
use ClyoNutri\Domain\PreConsultation\QuestionBank;
use ClyoNutri\Domain\Telehealth\TeleconsultationRateLimiter;
use PDO;

/**
 * Pré-consulta (NI2-437).
 * Nutricionista: /pacientes/{id}/pre-consulta[/...]. Paciente (link): /pre-consulta/{token} → /pre-consulta.
 */
class PreConsultationController
{
    private PDO $db;

    public function __construct(?PDO $db = null, private ?Mailer $mailer = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    // ------------------------------------------------------------------ nutricionista

    public function index(array $params): void
    {
        $patient = $this->patientOr404((int)($params['id'] ?? 0));
        if (!$patient) {
            return;
        }
        $service = new PreConsultationService($this->db);
        $list = $service->listForPatient((int)$patient['id']);
        $st = $this->db->prepare("SELECT id, scheduled_at FROM appointments WHERE clinic_id = :c AND patient_id = :p AND scheduled_at >= :now
            AND status NOT IN ('cancelled', 'canceled', 'no_show', 'completed') ORDER BY scheduled_at LIMIT 10");
        $st->execute(['c' => TenantContext::getClinicId(), 'p' => (int)$patient['id'], 'now' => date('Y-m-d H:i:s')]);
        $appointments = $st->fetchAll(PDO::FETCH_ASSOC);
        $this->renderShell('Pré-consulta — ' . $patient['name'], 'professional_index', [
            'patient' => $patient, 'list' => $list, 'appointments' => $appointments, 'canEdit' => $this->prescriberId() !== null,
        ]);
    }

    public function create(array $params): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $prof = $this->requirePrescriber();
        if ($prof === null || !$this->patientOr404($patientId)) {
            return;
        }
        try {
            $q = (new PreConsultationService($this->db))->createProposal(
                $patientId, $prof,
                is_array($_POST['objectives'] ?? null) ? $_POST['objectives'] : [],
                is_numeric($_POST['appointment_id'] ?? null) && (int)$_POST['appointment_id'] > 0 ? (int)$_POST['appointment_id'] : null,
                ($_POST['mode'] ?? '') === 'blank',
                TenantContext::getUserId()
            );
            $this->redirect("/pacientes/{$patientId}/pre-consulta/{$q['id']}");
        } catch (PreConsultationException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect("/pacientes/{$patientId}/pre-consulta");
        }
    }

    public function show(array $params): void
    {
        $patient = $this->patientOr404((int)($params['id'] ?? 0));
        if (!$patient) {
            return;
        }
        $service = new PreConsultationService($this->db);
        try {
            $q = $service->get((int)($params['qid'] ?? 0));
        } catch (PreConsultationException) {
            http_response_code(404);
            echo 'Pré-consulta não encontrada.';
            return;
        }
        if ((int)$q['patient_id'] !== (int)$patient['id']) {
            http_response_code(404);
            echo 'Pré-consulta não encontrada.';
            return;
        }
        $link = $_SESSION['precons_link'][$q['id']] ?? null;
        unset($_SESSION['precons_link'][$q['id']]);
        // Só oferece o WhatsApp com link absoluto: um link relativo chegaria quebrado ao paciente.
        $whatsappUrl = $link !== null && self::isAbsoluteUrl((string)$link)
            ? self::whatsappUrl($patient, (string)$link, (string)($q['token_expires_at'] ?? '')) : null;
        $this->renderShell('Pré-consulta — ' . $patient['name'], 'professional_show', [
            'patient' => $patient, 'q' => $q, 'link' => $link, 'canEdit' => $this->prescriberId() !== null,
            'snapshots' => $service->snapshots((int)$q['id']),
            'whatsappUrl' => $whatsappUrl,
            'whatsappHasPhone' => self::whatsappPhone((string)($patient['phone'] ?? '')) !== null,
        ]);
    }

    public function updateQuestions(array $params): void
    {
        $this->professionalAction($params, function (PreConsultationService $s, int $qid) {
            $ids = is_array($_POST['questions'] ?? null) ? $_POST['questions'] : [];
            $req = is_array($_POST['required'] ?? null) ? $_POST['required'] : [];
            $s->updateQuestions($qid, $ids, $req);
            return 'Questionário atualizado.';
        });
    }

    public function send(array $params): void
    {
        $this->professionalAction($params, function (PreConsultationService $s, int $qid, int $patientId) {
            $res = $s->send($qid, is_numeric($_POST['ttl_days'] ?? null) ? (int)$_POST['ttl_days'] : null);
            $base = rtrim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')), '/');
            $url = ($base !== '' ? $base : '') . '/pre-consulta/' . $res['token'];
            $_SESSION['precons_link'][$qid] = $url;
            $channel = in_array($_POST['channel'] ?? '', ['email', 'whatsapp', 'link'], true) ? $_POST['channel'] : 'email';
            if ($channel === 'whatsapp') {
                $logger = new AuditLogger($this->db);
                if (!self::isAbsoluteUrl($url)) {
                    // Sem APP_URL o link sairia relativo ("/pre-consulta/…") e o paciente não conseguiria abrir.
                    $logger->log('PRECONSULTATION_WHATSAPP_SKIPPED', 'preconsultation_questionnaires', (string)$qid, ['reason' => 'app_url_missing']);
                    $mail = 'APP_URL não configurado: a mensagem do WhatsApp não foi preparada porque o link sairia incompleto. Configure APP_URL no .env.';
                } else {
                    $logger->log('PRECONSULTATION_WHATSAPP_PREPARED', 'preconsultation_questionnaires', (string)$qid, ['channel' => 'whatsapp']);
                    $mail = 'Clique em "Enviar pelo WhatsApp" para abrir a conversa com a mensagem pronta.';
                }
            } else {
                $mail = $this->sendMail($patientId, $res, $url, $channel === 'email');
            }
            return 'Link gerado. Válido até ' . date('d/m/Y H:i', strtotime($res['expires_at'])) . '. ' . $mail;
        });
    }

    public function revoke(array $params): void
    {
        $this->professionalAction($params, function (PreConsultationService $s, int $qid) {
            $s->revoke($qid);
            return 'Link revogado. O paciente não consegue mais abrir este questionário.';
        });
    }

    public function review(array $params): void
    {
        $this->professionalAction($params, function (PreConsultationService $s, int $qid) {
            $s->markReviewed($qid, TenantContext::getUserId(), is_string($_POST['review_notes'] ?? null) ? $_POST['review_notes'] : null);
            return 'Revisão registrada.';
        });
    }

    public function import(array $params): void
    {
        $this->professionalAction($params, function (PreConsultationService $s, int $qid, int $patientId, int $prof) {
            $res = $s->importToAnamnesis($qid, $prof);
            return 'Respostas levadas para um rascunho da anamnese (' . count($res['fields']) . ' seções). Revise e finalize na anamnese.';
        });
    }

    // ------------------------------------------------------------------ paciente (link)

    public function patientLink(array $params): void
    {
        $this->patientHeaders();
        try {
            $this->rateLimit('precons_ip', (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 30, 600);
            $row = (new PreConsultationService($this->db))->redeem((string)($params['token'] ?? ''));
        } catch (\Throwable $e) {
            unset($_SESSION['precons']);
            $this->patientMessage($e);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION['precons'] = ['id' => (int)$row['id'], 'h' => (string)$row['token_hash'], 'verified' => false];
        header('Location: /pre-consulta', true, 303);
        exit;
    }

    public function patientPage(array $params = []): void
    {
        $this->patientHeaders();
        $service = new PreConsultationService($this->db);
        try {
            $row = $this->patientRow($service, true);
        } catch (\Throwable $e) {
            $this->patientMessage($e);
            return;
        }
        $ctx = $service->patientContext($row);
        if (in_array($row['status'], ['submitted', 'reviewed'], true)) {
            $this->renderPatient('patient_done', ['ctx' => $ctx]);
            return;
        }
        if (empty($_SESSION['precons']['verified']) && $service->needsBirthDate($row)) {
            $this->renderPatient('patient_verify', ['ctx' => $ctx, 'error' => $this->takeFlash()['message'] ?? null, 'csrf' => Csrf::token()]);
            return;
        }
        $flash = $this->takeFlash();
        $this->renderPatient('patient_form', [
            'ctx' => $ctx, 'csrf' => Csrf::token(),
            'answers' => $flash['answers'] ?? $ctx['answers'],
            'errors' => $flash['errors'] ?? [],
            'notice' => $flash['message'] ?? null,
        ]);
    }

    public function patientVerify(array $params = []): void
    {
        $this->patientHeaders();
        $service = new PreConsultationService($this->db);
        try {
            $row = $this->patientRow($service, false);
            $this->rateLimit('precons_verify_ip', (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 20, 600);
            $ok = $service->verifyBirthDate($row, (string)($_POST['birth_date'] ?? ''));
        } catch (\Throwable $e) {
            $this->patientMessage($e);
            return;
        }
        if ($ok) {
            $_SESSION['precons']['verified'] = true;
        } else {
            $this->flash('error', 'A data não confere com o cadastro da clínica. Confira e tente de novo.');
        }
        header('Location: /pre-consulta', true, 303);
        exit;
    }

    public function patientSave(array $params = []): void
    {
        $this->patientHeaders();
        $service = new PreConsultationService($this->db);
        try {
            $row = $this->patientRow($service, false);
            if (empty($_SESSION['precons']['verified']) && $service->needsBirthDate($row)) {
                header('Location: /pre-consulta', true, 303);
                exit;
            }
            $answers = is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [];
            if (($_POST['action'] ?? '') === 'submit') {
                $res = $service->submit($row, $answers, !empty($_POST['consent']), (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                if ($res['errors']) {
                    $_SESSION['precons_flash'] = ['type' => 'error', 'message' => 'Revise as perguntas indicadas. O que você já respondeu foi salvo.',
                        'errors' => $res['errors'], 'answers' => $answers];
                }
            } else {
                $service->saveDraft($row, $answers);
                $this->flash('success', 'Respostas salvas. Você pode fechar e continuar depois pelo mesmo link.');
            }
        } catch (\Throwable $e) {
            $this->patientMessage($e);
            return;
        }
        header('Location: /pre-consulta', true, 303);
        exit;
    }

    // ------------------------------------------------------------------ infra

    private function patientRow(PreConsultationService $service, bool $allowAnswered): array
    {
        $s = $_SESSION['precons'] ?? null;
        if (!is_array($s) || empty($s['id']) || empty($s['h'])) {
            throw new PreConsultationException('Abra o questionário pelo link que a clínica enviou.', 401);
        }
        return $service->forPatient((int)$s['id'], (string)$s['h'], $allowAnswered);
    }

    private function professionalAction(array $params, callable $fn): void
    {
        $patientId = (int)($params['id'] ?? 0);
        $qid = (int)($params['qid'] ?? 0);
        $prof = $this->requirePrescriber();
        if ($prof === null || !$this->patientOr404($patientId)) {
            return;
        }
        $service = new PreConsultationService($this->db);
        try {
            $q = $service->get($qid);
            if ((int)$q['patient_id'] !== $patientId) {
                throw new PreConsultationException('Pré-consulta não encontrada.', 404);
            }
            $this->flash('success', (string)$fn($service, $qid, $patientId, $prof));
        } catch (PreConsultationException | \InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }
        $this->redirect("/pacientes/{$patientId}/pre-consulta/{$qid}");
    }

    private function sendMail(int $patientId, array $res, string $url, bool $wantEmail): string
    {
        if (!$wantEmail) {
            return 'Copie o link abaixo e envie ao paciente.';
        }
        $st = $this->db->prepare('SELECT p.name, p.email, c.name AS clinic_name, u.name AS professional_name FROM patients p
            JOIN clinics c ON c.id = p.clinic_id JOIN professionals pr ON pr.id = :pr LEFT JOIN users u ON u.id = pr.user_id
            WHERE p.id = :p AND p.clinic_id = :c');
        $st->execute(['p' => $patientId, 'c' => TenantContext::getClinicId(), 'pr' => (int)$res['professional_id']]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $logger = new AuditLogger($this->db);
        if (empty($p['email']) || !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
            $logger->log('PRECONSULTATION_EMAIL_SKIPPED', 'preconsultation_questionnaires', (string)$res['id'], ['reason' => 'patient_without_email']);
            return 'O paciente não tem e-mail cadastrado: copie o link abaixo e envie por outro canal.';
        }
        if (!str_starts_with($url, 'http')) {
            $logger->log('PRECONSULTATION_EMAIL_FAILED', 'preconsultation_questionnaires', (string)$res['id'], ['reason' => 'app_url_missing']);
            return 'APP_URL não configurado: o e-mail não foi enviado. Copie o link abaixo.';
        }
        try {
            ($this->mailer ?? \ClyoNutri\Core\Mail\MailerFactory::fromEnv())->send(PreConsultationMail::build(
                (string)$p['email'], (string)$p['name'], (string)($p['professional_name'] ?? ''), (string)($p['clinic_name'] ?? ''),
                date('d/m/Y \à\s H:i', strtotime($res['expires_at'])), $url
            ));
            $logger->log('PRECONSULTATION_EMAIL_SENT', 'preconsultation_questionnaires', (string)$res['id'], ['channel' => 'email']);
            return 'E-mail enviado ao paciente.';
        } catch (\Throwable $e) {
            $logger->log('PRECONSULTATION_EMAIL_FAILED', 'preconsultation_questionnaires', (string)$res['id'], ['reason' => 'mail_error']);
            return 'O e-mail não pôde ser enviado: copie o link abaixo e envie por outro canal.';
        }
    }

    /**
     * Telefone no formato do WhatsApp (só dígitos, com DDI). Retorna null se não der para usar.
     * - Com DDI explícito ("+" ou "00" no início): usa o número como foi informado. Nunca acrescenta
     *   o 55, para um número estrangeiro não virar o de um desconhecido no Brasil.
     * - Sem DDI: só aceita número brasileiro com DDD (10 ou 11 dígitos, com ou sem o 0 de longa
     *   distância) ou já começando com 55.
     */
    public static function whatsappPhone(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $international = str_starts_with($raw, '+') || str_starts_with(preg_replace('/[\s().-]/', '', $raw) ?? '', '00');
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if ($international) {
            $d = ltrim($d, '0'); // "00" de discagem internacional
            return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
        }
        $d = ltrim($d, '0'); // "0" de longa distância (ex.: 011 9…)
        if (strlen($d) === 10 || strlen($d) === 11) {
            return '55' . $d;
        }
        if (str_starts_with($d, '55') && (strlen($d) === 12 || strlen($d) === 13)) {
            return $d;
        }
        return null;
    }

    public static function isAbsoluteUrl(string $url): bool
    {
        return (bool)preg_match('#^https?://[^/\s]+/#i', $url);
    }

    /** Link wa.me com a mensagem pronta. Sem telefone válido, o WhatsApp pede para escolher o contato. */
    public static function whatsappUrl(array $patient, string $link, string $expiresAt): string
    {
        $first = trim(explode(' ', trim((string)($patient['name'] ?? '')))[0] ?? '');
        $who = trim((string)($_SESSION['user_name'] ?? ''));
        $clinic = trim((string)($_SESSION['clinic_name'] ?? ''));
        $from = $who !== '' ? $who . ($clinic !== '' ? " ({$clinic})" : '') : $clinic;
        $until = $expiresAt !== '' && strtotime($expiresAt) ? date('d/m/Y \à\s H:i', strtotime($expiresAt)) : null;

        $msg = 'Olá' . ($first !== '' ? ", {$first}" : '') . '!' . ($from !== '' ? " Aqui é {$from}." : '') . "\n\n"
            . "Antes da nossa consulta, peço que responda o questionário de pré-consulta pelo link abaixo. "
            . "Leva poucos minutos e você pode salvar e continuar depois.\n\n"
            . $link . "\n\n"
            . 'Para abrir, confirme a sua data de nascimento.' . ($until ? " O link vale até {$until}." : '');

        $phone = self::whatsappPhone((string)($patient['phone'] ?? ''));
        return 'https://wa.me/' . ($phone ?? '') . '?text=' . rawurlencode($msg);
    }

    private function patientOr404(int $patientId): ?array
    {
        $patient = (new PatientRepository())->findById($patientId);
        if (!$patient) {
            http_response_code(404);
            echo 'Paciente não encontrado.';
            return null;
        }
        return $patient;
    }

    /** Nutricionista ativo, membro ativo da clínica, com CRN (mesma regra da prescrição). */
    private function prescriberId(): ?int
    {
        $st = $this->db->prepare("SELECT pr.id FROM clinic_members cm JOIN users u ON u.id = cm.user_id
            JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active' AND cm.role IN ('owner', 'admin', 'nutritionist')
              AND u.status = 'active' AND u.role = 'nutritionist' AND pr.crn IS NOT NULL AND TRIM(pr.crn) <> ''
            ORDER BY pr.id LIMIT 1");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function requirePrescriber(): ?int
    {
        $id = $this->prescriberId();
        if ($id === null) {
            http_response_code(403);
            echo 'Apenas nutricionistas com CRN cadastrado na clínica criam e enviam pré-consultas.';
        }
        return $id;
    }

    private function rateLimit(string $bucket, string $subject, int $max, int $window): void
    {
        try {
            (new TeleconsultationRateLimiter($this->db))->hit($bucket, $subject, $max, $window);
        } catch (\ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationRateLimitException $e) {
            throw new PreConsultationException('Muitas tentativas. Aguarde alguns minutos e tente de novo.', 429);
        }
    }

    private function patientHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
    }

    private function patientMessage(\Throwable $e): void
    {
        $status = $e instanceof PreConsultationException ? $e->httpStatus() : 500;
        if ($status >= 500) {
            error_log('[pre-consulta] ' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
        }
        http_response_code($status);
        $this->renderPatient('patient_message', [
            'title' => $status === 409 ? 'Questionário enviado' : 'Não foi possível abrir o questionário',
            'message' => $e instanceof PreConsultationException ? $e->getMessage() : 'Ocorreu um erro. Tente novamente em instantes.',
        ]);
    }

    private function renderPatient(string $view, array $vars): void
    {
        extract($vars, EXTR_SKIP);
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $sections = QuestionBank::SECTIONS;
        require __DIR__ . '/../../../views/preconsultation/' . $view . '.php';
    }

    private function renderShell(string $title, string $view, array $vars): void
    {
        extract($vars, EXTR_SKIP);
        $flash = $this->takeFlash();
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Nutricionista';
        $csrfToken = Csrf::token();
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ob_start();
        require __DIR__ . '/../../../views/preconsultation/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['precons_flash'] = ['type' => $type, 'message' => $message];
    }

    private function takeFlash(): array
    {
        $f = $_SESSION['precons_flash'] ?? [];
        unset($_SESSION['precons_flash']);
        return is_array($f) ? $f : [];
    }

    private function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }
}
