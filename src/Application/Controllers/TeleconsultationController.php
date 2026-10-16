<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\SecurityHeaders;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TelehealthRateLimiter;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Telehealth\TeleconsultationException;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;
use PDO;

/**
 * Consultório Online / Teleconsulta (NI2-221) — v11.
 *
 * Profissional: só o responsável pelo agendamento abre a sala e age (F01/F07). Criar a sessão é POST com CSRF (F07).
 * Paciente: o link /teleconsulta/sala-espera/{token} é trocado por um cookie de sessão e redireciona para a URL
 * limpa /teleconsulta/sala-espera (F14). Endpoints do paciente com rate limit e CSRF. Status sem URL de sala (F05).
 * Sem provedor de vídeo configurado, as telas dizem isso (F02): nada de "vídeo conectado" nem selos de conformidade.
 */
class TeleconsultationController
{
    private const PATIENT_KEY = '_tele_patient';
    private const LINK_FLASH_KEY = '_tele_link_once';

    private PDO $db;
    private TeleconsultationService $service;
    private TelehealthRateLimiter $limiter;

    public function __construct(?PDO $db = null, ?TeleconsultationService $service = null, ?TelehealthRateLimiter $limiter = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->service = $service ?? new TeleconsultationService($this->db);
        $this->limiter = $limiter ?? new TelehealthRateLimiter($this->db);
    }

    // ================================================================ utilidades

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** @return array{0:int,1:int}|null [userId, clinicId] */
    private function staff(): ?array
    {
        self::startSession();
        $u = (int)($_SESSION['user_id'] ?? 0);
        $c = (int)($_SESSION['clinic_id'] ?? 0);
        if ($u <= 0 || $c <= 0) {
            header('Location: /login');
            return null;
        }
        TenantContext::setClinicId($c);
        return [$u, $c];
    }

    private static function noStore(): void
    {
        header('Cache-Control: no-store, private', true);
        header('Pragma: no-cache', true);
    }

    /** Converte erro em status + mensagem pública. Nada interno vai para a resposta (F15/F20). */
    private static function mapError(\Throwable $e): array
    {
        if ($e instanceof TeleconsultationException) {
            return [$e->httpStatus, $e->getMessage()];
        }
        if ($e instanceof TenantSecurityException) {
            return [404, 'Teleconsulta não encontrada.'];
        }
        error_log('[teleconsulta] erro interno: ' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
        return [500, 'Não foi possível concluir a ação agora. Tente novamente em instantes.'];
    }

    private static function h(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    private static function head(string $title): void
    {
        ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<title><?= self::h($title) ?></title>
<link rel="stylesheet" href="/assets/css/teleconsulta.css">
</head>
<?php
    }

    private static function messagePage(int $status, string $title, string $message, ?string $backHref = null, string $extraHtml = ''): void
    {
        http_response_code($status);
        self::noStore();
        header('Content-Type: text/html; charset=utf-8');
        self::head($title . ' — ClyoNutri');
        ?>
<body class="tc-body tc-center">
  <main class="tc-card tc-narrow tc-stack" role="main">
    <h1><?= self::h($title) ?></h1>
    <p><?= self::h($message) ?></p>
    <?= $extraHtml ?>
    <?php if ($backHref): ?><p><a class="tc-btn" href="<?= self::h($backHref) ?>">Voltar</a></p><?php endif; ?>
  </main>
</body>
</html>
<?php
    }

    private static function json(int $status, array $data): void
    {
        http_response_code($status);
        self::noStore();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private static function postCsrfOk(): bool
    {
        $t = $_POST['_csrf'] ?? $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return is_string($t) && Csrf::validate($t);
    }

    // ================================================================ profissional

    /** GET /teleconsulta/sessao-agenda/{appointmentId}: só lê. Sessão existente → sala; senão botão (POST). */
    public function openFromAppointment(array $params = []): void
    {
        if (!($who = $this->staff())) { return; }
        [$userId, $clinicId] = $who;
        $appointmentId = (int)($params['appointmentId'] ?? 0);
        try {
            $p = $this->service->previewForAppointment($appointmentId, $userId, $clinicId);
        } catch (\Throwable $e) {
            [$st, $msg] = self::mapError($e);
            self::messagePage($st, 'Teleconsulta indisponível', $msg, '/agenda');
            return;
        }
        if ($p['session_id'] !== null) {
            header('Location: /teleconsulta/' . $p['session_id'] . '/atendimento', true, 303);
            return;
        }
        $csrf = self::h(Csrf::generate());
        $when = self::h(date('d/m/Y H:i', strtotime($p['appointment']['scheduled_at'])));
        self::messagePage(200, 'Abrir sala de teleconsulta', "Consulta online de {$when}. Ainda não existe sala para este agendamento.", '/agenda',
            '<form method="POST" action="/teleconsulta/sessao-agenda/' . $appointmentId . '"><input type="hidden" name="_csrf" value="' . $csrf . '">'
            . '<button class="tc-btn tc-btn-ok" type="submit">Criar sala de teleconsulta</button></form>');
    }

    /** POST /teleconsulta/sessao-agenda/{appointmentId}: cria (idempotente) e vai para a sala. */
    public function createFromAppointment(array $params = []): void
    {
        if (!($who = $this->staff())) { return; }
        [$userId, $clinicId] = $who;
        if (!self::postCsrfOk()) {
            self::messagePage(403, 'Ação bloqueada', 'Sessão expirada. Recarregue a página e tente de novo.', '/agenda');
            return;
        }
        try {
            $r = $this->service->createSessionForAppointment((int)($params['appointmentId'] ?? 0), $userId, $clinicId);
        } catch (\Throwable $e) {
            [$st, $msg] = self::mapError($e);
            self::messagePage($st, 'Teleconsulta indisponível', $msg, '/agenda');
            return;
        }
        header('Location: /teleconsulta/' . $r['session_id'] . '/atendimento', true, 303);
    }

    /** GET /teleconsulta/{id}/atendimento — só o responsável (F07). */
    public function professionalRoom(array $params = []): void
    {
        if (!($who = $this->staff())) { return; }
        [$userId, $clinicId] = $who;
        $sessionId = (int)($params['id'] ?? 0);
        try {
            $s = $this->service->getSessionForProfessional($sessionId, $userId, $clinicId);
        } catch (\Throwable $e) {
            [$st, $msg] = self::mapError($e);
            self::messagePage($st, 'Teleconsulta indisponível', $msg, '/agenda');
            return;
        }

        $st = $this->db->prepare("SELECT id, version, title, created_at FROM clinical_records WHERE patient_id = :p AND clinic_id = :c ORDER BY version DESC LIMIT 5");
        $st->execute(['p' => $s['patient_id'], 'c' => $clinicId]);
        $recent = $st->fetchAll(PDO::FETCH_ASSOC);

        $linkOnce = null;
        if (isset($_SESSION[self::LINK_FLASH_KEY]) && (int)($_SESSION[self::LINK_FLASH_KEY]['sid'] ?? 0) === $sessionId) {
            $linkOnce = $_SESSION[self::LINK_FLASH_KEY];
        }
        unset($_SESSION[self::LINK_FLASH_KEY]); // mostrado uma vez

        $csrf = self::h(Csrf::generate());
        $status = (string)$s['status'];
        $label = \ClyoNutri\Core\I18n\LabelMap::teleconsultationStatus($status);
        $terminal = in_array($status, TeleconsultationService::TERMINAL, true);
        $videoOk = $this->service->isVideoConfigured() && !empty($s['external_room_url']);
        if ($videoOk) {
            $videoOk = SecurityHeaders::allowTelehealthProviderOrigin() !== null;
        }
        $flash = isset($_GET['salvo']) ? 'Evolução gravada no prontuário.' : null;

        self::noStore();
        header('Content-Type: text/html; charset=utf-8');
        self::head('Teleconsulta — ' . $s['patient_name']);
        ?>
<body class="tc-body">
<header class="tc-header">
  <div class="tc-row"><span class="tc-brand">ClyoNutri · Teleconsulta</span></div>
  <div class="tc-row tc-small">
    <span>Paciente: <strong><?= self::h($s['patient_name']) ?></strong></span>
    <span>Estado: <span id="session-status-badge" class="tc-badge <?= $status === 'waiting_room' ? 'tc-badge-wait' : '' ?>"><?= self::h($label) ?></span></span>
    <?php if (in_array($status, ['admitted', 'in_progress'], true)): ?>
    <form method="POST" action="/teleconsulta/<?= (int)$s['id'] ?>/encerrar" data-confirm="Encerrar a chamada? Isso não conclui a consulta na agenda.">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <button type="submit" class="tc-btn tc-btn-danger">Encerrar chamada</button>
    </form>
    <?php endif; ?>
  </div>
</header>
<main class="tc-main" role="main">
  <section class="tc-stack" aria-label="Sala e admissão">
    <?php if ($flash): ?><div class="tc-alert tc-alert-ok" role="status"><?= self::h($flash) ?></div><?php endif; ?>
    <div class="tc-card tc-stack">
      <h2>Admissão do paciente</h2>
      <p class="tc-muted"><?= $status === 'waiting_room' ? 'O paciente está na sala de espera.' : 'Estado atual: ' . self::h($label) . '.' ?></p>
      <?php if (!$terminal): ?>
      <div class="tc-row">
        <?php if ($status === 'waiting_room'): ?>
        <form method="POST" action="/teleconsulta/<?= (int)$s['id'] ?>/admitir"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><button type="submit" class="tc-btn tc-btn-ok">Admitir paciente</button></form>
        <?php endif; ?>
        <?php if (in_array($status, ['scheduled', 'device_checked', 'waiting_room'], true)): ?>
        <form method="POST" action="/teleconsulta/<?= (int)$s['id'] ?>/rejeitar"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="reason" value="Recusado pelo profissional"><button type="submit" class="tc-btn">Recusar</button></form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="tc-card tc-stack">
      <h2>Link do paciente</h2>
      <?php if ($linkOnce): ?>
        <div class="tc-alert tc-alert-warn" role="status">
          <p><strong>Copie agora: este link aparece só uma vez.</strong> Envie ao paciente por um canal seu (o sistema ainda não envia convites). Gerar outro link invalida este.</p>
          <p class="tc-link" id="patient-link"><?= self::h($linkOnce['url']) ?></p>
          <p class="tc-small">Vale de <?= self::h(date('d/m/Y H:i', strtotime($linkOnce['window_start']))) ?> até <?= self::h(date('d/m/Y H:i', strtotime($linkOnce['expires_at']))) ?>.</p>
        </div>
      <?php endif; ?>
      <?php if (!$terminal): ?>
      <form method="POST" action="/teleconsulta/<?= (int)$s['id'] ?>/convite" data-confirm="Gerar um novo link? O link anterior deixa de funcionar.">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <button type="submit" class="tc-btn"><?= empty($s['token_issued_at']) ? 'Gerar link do paciente' : 'Gerar novo link (invalida o anterior)' ?></button>
      </form>
      <?php else: ?>
      <p class="tc-muted">Teleconsulta encerrada: não há link ativo.</p>
      <?php endif; ?>
    </div>

    <div class="tc-video" aria-label="Vídeo">
      <?php if ($videoOk && in_array($status, ['admitted', 'in_progress'], true)): ?>
        <iframe src="<?= self::h($s['external_room_url']) ?>" title="Sala de vídeo" allow="camera; microphone; fullscreen; display-capture"></iframe>
      <?php else: ?>
        <div class="tc-card tc-narrow tc-stack" role="status">
          <h3>Vídeo não configurado</h3>
          <p class="tc-muted">Esta instalação ainda não tem um provedor de vídeo ativo. A sala de espera, a admissão e a evolução funcionam, mas a chamada de vídeo não acontece por aqui.</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="tc-card tc-stack" aria-label="Evolução clínica">
    <h2>Evolução clínica</h2>
    <p class="tc-muted">As anotações ficam no prontuário com histórico preservado e não são enviadas ao paciente nem ao provedor de vídeo.<?= $status === 'completed' ? ' Chamada encerrada: o que for gravado agora entra como adendo.' : '' ?></p>
    <?php if (in_array($status, ['admitted', 'in_progress', 'completed'], true)): ?>
    <form method="POST" action="/teleconsulta/<?= (int)$s['id'] ?>/evoluir" class="tc-stack">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <div><label class="tc-label" for="tc-title">Título</label><input id="tc-title" class="tc-input" type="text" name="title" required value="Evolução teleconsulta - <?= self::h(date('d/m/Y H:i')) ?>"></div>
      <div><label class="tc-label" for="tc-notes">Conduta e anotações</label><textarea id="tc-notes" class="tc-input" name="clinical_notes" required></textarea></div>
      <div><label class="tc-label" for="tc-goals">Metas para a próxima sessão</label><input id="tc-goals" class="tc-input" type="text" name="goals"></div>
      <button type="submit" class="tc-btn tc-btn-ok tc-btn-block">Gravar evolução no prontuário</button>
    </form>
    <?php else: ?>
      <p class="tc-muted">A evolução fica disponível depois de admitir o paciente.</p>
    <?php endif; ?>
    <h3>Últimos registros do paciente</h3>
    <ul class="tc-list">
      <?php foreach ($recent as $r): ?>
        <li><?= self::h($r['title']) ?> <span class="tc-muted">· v<?= (int)$r['version'] ?> · <?= self::h(date('d/m/y', strtotime((string)$r['created_at']))) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$recent): ?><li class="tc-muted">Nenhum registro.</li><?php endif; ?>
    </ul>
  </section>
</main>
</body>
</html>
<?php
    }

    /** Executa uma ação POST do profissional com CSRF, mapeamento de erro e redirect. */
    private function staffAction(array $params, callable $fn, ?callable $onErrorHtml = null): void
    {
        if (!($who = $this->staff())) { return; }
        [$userId, $clinicId] = $who;
        $sessionId = (int)($params['id'] ?? 0);
        if (!self::postCsrfOk()) {
            self::messagePage(403, 'Ação bloqueada', 'Sessão expirada. Recarregue a página e tente de novo.', '/agenda');
            return;
        }
        try {
            $redirect = $fn($sessionId, $userId, $clinicId);
        } catch (\Throwable $e) {
            [$st, $msg] = self::mapError($e);
            $back = $st === 404 || $st === 403 ? '/agenda' : "/teleconsulta/{$sessionId}/atendimento";
            self::messagePage($st, 'Não foi possível concluir', $msg, $back, $onErrorHtml ? $onErrorHtml() : '');
            return;
        }
        header('Location: ' . $redirect, true, 303);
    }

    public function admit(array $params = []): void
    {
        $this->staffAction($params, function (int $sid, int $uid, int $cid) {
            $this->service->admitPatient($sid, $uid, $cid);
            return "/teleconsulta/{$sid}/atendimento";
        });
    }

    public function reject(array $params = []): void
    {
        $this->staffAction($params, function (int $sid, int $uid, int $cid) {
            $this->service->rejectPatient($sid, $uid, trim((string)($_POST['reason'] ?? 'Recusado pelo profissional')), $cid);
            return "/teleconsulta/{$sid}/atendimento";
        });
    }

    public function recordEvolution(array $params = []): void
    {
        $title = trim((string)($_POST['title'] ?? 'Evolução teleconsulta'));
        $notes = trim((string)($_POST['clinical_notes'] ?? ''));
        $goals = trim((string)($_POST['goals'] ?? ''));
        $this->staffAction($params, function (int $sid, int $uid, int $cid) use ($title, $notes, $goals) {
            if ($notes === '') {
                throw TeleconsultationException::conflict('Escreva a evolução antes de gravar.');
            }
            $this->service->recordClinicalEvolution($sid, $uid, $title, ['notes' => $notes, 'goals' => $goals, 'source' => 'teleconsultation_room'], $cid);
            return "/teleconsulta/{$sid}/atendimento?salvo=1";
        }, function () use ($notes) {
            // F09 (parcial): o texto não se perde — devolvido para copiar.
            return $notes === '' ? '' : '<label class="tc-label" for="tc-keep">Seu texto (copie antes de sair)</label><textarea id="tc-keep" class="tc-input" readonly>' . self::h($notes) . '</textarea>';
        });
    }

    public function end(array $params = []): void
    {
        $this->staffAction($params, function (int $sid, int $uid, int $cid) {
            $this->service->endTeleconsultation($sid, $uid, $cid);
            return "/teleconsulta/{$sid}/atendimento";
        });
    }

    /** POST /teleconsulta/{id}/convite: gera/regera o link do paciente, mostrado uma única vez. */
    public function issueLink(array $params = []): void
    {
        $this->staffAction($params, function (int $sid, int $uid, int $cid) {
            $r = $this->service->issuePatientLink($sid, $uid, $cid);
            $base = rtrim((string)(\ClyoNutri\Core\Security\Env::get('APP_URL', '') ?? ''), '/');
            $_SESSION[self::LINK_FLASH_KEY] = [
                'sid' => $sid,
                'url' => $base . '/teleconsulta/sala-espera/' . $r['token'],
                'expires_at' => $r['expires_at'],
                'window_start' => $r['window_start'],
            ];
            return "/teleconsulta/{$sid}/atendimento";
        });
    }

    // ================================================================ paciente

    /**
     * GET /teleconsulta/sala-espera/{token} e /teleconsulta/sala/{token}: troca o link por cookie e
     * redireciona para a URL limpa (o token sai da barra de endereço e do histórico). Rate limit por IP.
     */
    public function patientLinkExchange(array $params = []): void
    {
        self::startSession();
        $ip = TelehealthRateLimiter::clientIp();
        header('Referrer-Policy: no-referrer', true);
        if ($this->limiter->isLocked('tele_bad', $ip) || !$this->limiter->hit('tele_link', $ip)) {
            header('Retry-After: 900');
            self::messagePage(429, 'Muitas tentativas', 'Aguarde alguns minutos e abra o link de novo.');
            return;
        }
        try {
            $s = $this->service->getSessionByPatientToken((string)($params['token'] ?? ''));
        } catch (\Throwable $e) {
            $this->limiter->hit('tele_bad', $ip);
            [$st, $msg] = self::mapError($e);
            self::messagePage($st === 500 ? 500 : 404, 'Link indisponível', $st === 500 ? $msg : 'Este link de teleconsulta é inválido ou expirou. Peça um novo link à clínica.');
            return;
        }
        session_regenerate_id(true);
        $_SESSION[self::PATIENT_KEY] = ['sid' => (int)$s['id'], 'gen' => (int)$s['token_generation'], 'h' => (string)$s['patient_token_hash']];
        header('Location: /teleconsulta/sala-espera', true, 303);
    }

    /** Sessão do paciente a partir do cookie; inválida/revogada/expirada → null (e o cookie é limpo). */
    private function patientSession(): ?array
    {
        self::startSession();
        $p = $_SESSION[self::PATIENT_KEY] ?? null;
        if (!is_array($p)) {
            return null;
        }
        try {
            return $this->service->getSessionForPatientCookie((int)$p['sid'], (int)$p['gen'], (string)$p['h']);
        } catch (TeleconsultationException $e) {
            unset($_SESSION[self::PATIENT_KEY]);
            return null;
        }
    }

    private function patientApi(string $scope, callable $fn, bool $mutating = true): void
    {
        $ip = TelehealthRateLimiter::clientIp();
        $s = $this->patientSession();
        if ($s === null) {
            if (!$this->limiter->hit('tele_bad', $ip)) {
                header('Retry-After: 900');
                self::json(429, ['error' => 'Muitas tentativas. Aguarde alguns minutos.']);
                return;
            }
            self::json(404, ['error' => 'Teleconsulta indisponível. O link pode ter expirado ou a consulta foi encerrada.']);
            return;
        }
        if (!$this->limiter->hit($scope, $s['id'] . '|' . $ip)) {
            header('Retry-After: 60');
            self::json(429, ['error' => 'Muitas tentativas. Aguarde um pouco.']);
            return;
        }
        if ($mutating && !self::postCsrfOk()) {
            self::json(403, ['error' => 'Sessão expirada. Recarregue a página.']);
            return;
        }
        try {
            self::json(200, $fn($s));
        } catch (\Throwable $e) {
            [$st, $msg] = self::mapError($e);
            self::json($st, ['error' => $msg]);
        }
    }

    /** POST legados com token na URL: não existem mais (contam como tentativa inválida). */
    public function patientLegacyTokenApi(array $params = []): void
    {
        if (!$this->limiter->hit('tele_bad', TelehealthRateLimiter::clientIp())) {
            header('Retry-After: 900');
            self::json(429, ['error' => 'Muitas tentativas. Aguarde alguns minutos.']);
            return;
        }
        self::json(404, ['error' => 'Teleconsulta indisponível.']);
    }

    public function patientSubmitDeviceCheck(array $params = []): void
    {
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $this->patientApi('tele_post', fn(array $s) => $this->service->submitDeviceCheck($s, $in));
    }

    public function patientAcceptConsent(array $params = []): void
    {
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $this->patientApi('tele_consent', fn(array $s) => $this->service->recordConsent(
            $s,
            (int)($in['term_id'] ?? 0),
            ($in['decision'] ?? '') === 'accept',
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ));
    }

    public function patientEnterWaiting(array $params = []): void
    {
        $this->patientApi('tele_post', fn(array $s) => $this->service->enterWaitingRoom($s));
    }

    public function patientStatus(array $params = []): void
    {
        $this->patientApi('tele_poll', fn(array $s) => $this->service->patientStatus($s), false);
    }

    private static function patientGone(): void
    {
        self::messagePage(404, 'Teleconsulta indisponível', 'O link expirou, foi substituído ou a consulta foi encerrada ou cancelada. Se precisar, peça um novo link à clínica.');
    }

    /** GET /teleconsulta/sala-espera — pré-consulta + espera (URL limpa, sessão pelo cookie). */
    public function patientWaitingRoom(array $params = []): void
    {
        $s = $this->patientSession();
        if ($s === null) {
            $this->limiter->hit('tele_bad', TelehealthRateLimiter::clientIp());
            self::patientGone();
            return;
        }
        if (!empty($s['too_early'])) {
            self::messagePage(200, 'A sala ainda não abriu', 'A sala de espera abre em ' . date('d/m/Y \à\s H:i', strtotime($s['window_start'])) . '. Volte neste horário pelo mesmo link.');
            return;
        }
        if (in_array($s['status'], ['admitted', 'in_progress'], true)) {
            header('Location: /teleconsulta/sala', true, 303);
            return;
        }
        $term = $this->service->getActiveConsentTerm();
        $csrf = Csrf::generate();
        self::noStore();
        header('Content-Type: text/html; charset=utf-8');
        self::head('Sala de espera — ClyoNutri');
        $inWaiting = $s['status'] === 'waiting_room';
        ?>
<body class="tc-body tc-center">
<main class="tc-card tc-narrow tc-stack" role="main">
  <div>
    <h1>Teleconsulta com <?= self::h($s['professional_name']) ?></h1>
    <p class="tc-muted"><?= self::h($s['clinic_name']) ?> · <?= self::h(date('d/m/Y H:i', strtotime($s['scheduled_at']))) ?></p>
  </div>

  <section class="tc-card tc-stack" aria-labelledby="st1">
    <h2 id="st1">1. Teste de câmera e microfone</h2>
    <p class="tc-muted">O navegador vai pedir permissão. Nada é gravado.</p>
    <div class="tc-video" style="min-height:200px"><video id="video-preview" autoplay playsinline muted class="tc-hidden"></video><span id="video-placeholder" class="tc-muted">Prévia da câmera</span></div>
    <div class="tc-row"><button type="button" id="btn-test-media" class="tc-btn">Testar câmera e microfone</button><span id="media-status" class="tc-muted" role="status" aria-live="polite"><?= (int)$s['device_check_passed'] === 1 ? 'Teste já feito.' : 'Aguardando teste' ?></span></div>
  </section>

  <section class="tc-card tc-stack" aria-labelledby="st2">
    <h2 id="st2">2. Termo de consentimento</h2>
    <?php if ($term): ?>
      <p class="tc-small"><strong><?= self::h($term['title']) ?></strong> · versão <?= self::h($term['version']) ?></p>
      <div class="tc-term" tabindex="0"><?= nl2br(self::h($term['term_text'])) ?></div>
      <div class="tc-row">
        <button type="button" id="btn-consent-accept" class="tc-btn tc-btn-ok">Li e aceito</button>
        <button type="button" id="btn-consent-decline" class="tc-btn">Não aceito</button>
        <span id="consent-status" class="tc-muted" role="status" aria-live="polite"><?= !empty($s['consent_accepted_at']) ? 'Termo aceito.' : '' ?></span>
      </div>
    <?php else: ?>
      <div class="tc-alert tc-alert-err" role="alert">O termo de consentimento não está configurado. Avise a clínica.</div>
    <?php endif; ?>
  </section>

  <section class="tc-stack" aria-labelledby="st3">
    <h2 id="st3" class="tc-small">3. Sala de espera</h2>
    <button type="button" id="btn-enter-waiting" class="tc-btn tc-btn-ok tc-btn-block<?= $inWaiting ? ' tc-hidden' : '' ?>" disabled>Entrar na sala de espera</button>
    <div id="waiting-status" class="tc-alert tc-alert-warn<?= $inWaiting ? '' : ' tc-hidden' ?>" role="status" aria-live="polite">Você está na sala de espera. Aguarde nesta página: o(a) profissional vai admitir você.</div>
    <div id="error-box" class="tc-alert tc-alert-err tc-hidden" role="alert"></div>
  </section>
</main>
<script nonce="<?= SecurityHeaders::nonce() ?>">
(function () {
  const csrf = <?= json_encode($csrf) ?>;
  const termId = <?= json_encode($term ? (int)$term['id'] : 0) ?>;
  let deviceOk = <?= (int)$s['device_check_passed'] === 1 ? 'true' : 'false' ?>;
  let consentOk = <?= !empty($s['consent_accepted_at']) ? 'true' : 'false' ?>;
  const $ = (id) => document.getElementById(id);
  function showError(msg) { const b = $('error-box'); b.textContent = msg; b.classList.remove('tc-hidden'); }
  async function post(path, body) {
    const r = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(body || {}) });
    let data = {}; try { data = await r.json(); } catch (e) {}
    if (!r.ok) { throw new Error(data.error || 'Não foi possível concluir. Tente de novo.'); }
    return data;
  }
  function refresh() { $('btn-enter-waiting').disabled = !(deviceOk && consentOk); }
  $('btn-test-media').addEventListener('click', async function () {
    const st = $('media-status');
    st.textContent = 'Pedindo acesso à câmera e ao microfone...';
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
      $('video-preview').srcObject = stream; $('video-preview').classList.remove('tc-hidden'); $('video-placeholder').classList.add('tc-hidden');
      await post('/teleconsulta/sala-espera/dispositivo', { camera_ok: true, microphone_ok: true });
      st.textContent = 'Câmera e microfone funcionando.'; deviceOk = true; refresh();
    } catch (e) {
      st.textContent = 'Não foi possível usar a câmera ou o microfone. Verifique a permissão do navegador e tente de novo.';
    }
  });
  async function consent(decision) {
    try {
      await post('/teleconsulta/sala-espera/consentir', { term_id: termId, decision: decision });
      consentOk = decision === 'accept';
      $('consent-status').textContent = consentOk ? 'Termo aceito.' : 'Você não aceitou o termo. Sem o aceite, a teleconsulta não acontece; fale com a clínica.';
      refresh();
    } catch (e) { showError(e.message); }
  }
  if ($('btn-consent-accept')) {
    $('btn-consent-accept').addEventListener('click', () => consent('accept'));
    $('btn-consent-decline').addEventListener('click', () => consent('decline'));
  }
  $('btn-enter-waiting').addEventListener('click', async function () {
    try {
      const d = await post('/teleconsulta/sala-espera/entrar', {});
      if (d.status === 'waiting_room') { $('btn-enter-waiting').classList.add('tc-hidden'); $('waiting-status').classList.remove('tc-hidden'); poll(5000); }
    } catch (e) { showError(e.message); }
  });
  function poll(delay) {
    setTimeout(async function () {
      let next = 5000;
      try {
        const r = await fetch('/teleconsulta/sala-espera/status', { credentials: 'same-origin' });
        if (r.status === 404) { window.location.reload(); return; }
        if (r.status === 429) { next = 30000; }
        else {
          const d = await r.json();
          if (d.is_admitted) { window.location.href = '/teleconsulta/sala'; return; }
          if (d.status === 'rejected') { showError('A admissão não foi aceita pelo profissional.'); return; }
        }
      } catch (e) { next = Math.min(30000, delay * 2); $('waiting-status').textContent = 'Conexão instável. Tentando de novo...'; }
      poll(next);
    }, delay);
  }
  refresh();
  if (<?= $inWaiting ? 'true' : 'false' ?>) { poll(5000); }
})();
</script>
</body>
</html>
<?php
    }

    /** GET /teleconsulta/sala — paciente admitido. Sem provedor: estado honesto (F02). */
    public function patientConsultationRoom(array $params = []): void
    {
        $s = $this->patientSession();
        if ($s === null) {
            $this->limiter->hit('tele_bad', TelehealthRateLimiter::clientIp());
            self::patientGone();
            return;
        }
        if (!in_array($s['status'], ['admitted', 'in_progress'], true)) {
            header('Location: /teleconsulta/sala-espera', true, 303);
            return;
        }
        $videoOk = $this->service->isVideoConfigured() && !empty($s['external_room_url'])
            && SecurityHeaders::allowTelehealthProviderOrigin() !== null;
        self::noStore();
        header('Content-Type: text/html; charset=utf-8');
        self::head('Teleconsulta — ClyoNutri');
        ?>
<body class="tc-body">
<header class="tc-header"><span class="tc-brand">ClyoNutri</span><span class="tc-small">Consulta com <strong><?= self::h($s['professional_name']) ?></strong></span></header>
<main class="tc-main" role="main" style="grid-template-columns:1fr">
  <div class="tc-video">
    <?php if ($videoOk): ?>
      <iframe src="<?= self::h($s['external_room_url']) ?>" title="Sala de vídeo" allow="camera; microphone; fullscreen; display-capture"></iframe>
    <?php else: ?>
      <div class="tc-card tc-narrow tc-stack" role="status">
        <h1>Você foi admitido(a)</h1>
        <p>O vídeo não está configurado nesta instalação, então a chamada não acontece por esta página. Aguarde o contato do(a) profissional pelo canal combinado com a clínica.</p>
      </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
<?php
    }
}
