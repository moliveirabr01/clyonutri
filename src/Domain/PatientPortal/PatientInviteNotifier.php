<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PatientPortal;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\PatientInviteEmail;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Http\Transport;
use PDO;

/**
 * Envio do e-mail de convite de primeiro acesso do paciente (NI2-481).
 *
 * Separado de PatientOnboardingService::create() de propósito: o token em texto puro
 * nunca é persistido (só o hash fica no banco) e o envio acontece SEMPRE depois do
 * commit do convite. Uma falha de e-mail não desfaz o convite já gravado — apenas vira
 * estado observável (sent/logged/failed/skipped) na auditoria.
 *
 * Estados (log/null NÃO contam como envio — NI2-481):
 *   - sent    : um transporte que entrega (SMTP/mail) aceitou a mensagem (não prova entrega na caixa).
 *   - logged  : driver 'log' — a mensagem foi só registrada em arquivo; não houve envio real.
 *   - skipped : paciente sem e-mail válido, ou envio desativado (driver 'null'). A operação segue válida.
 *   - failed  : APP_URL ausente, token inválido, ou o SMTP recusou/erro de configuração.
 *
 * Auditoria sem PII: grava patient_id, clinic_id e o motivo. Nunca grava o token nem o e-mail.
 * O link é montado a partir de APP_URL (origem canônica), forçando https em produção;
 * nunca usa o Host da requisição (evita host header injection no link do e-mail).
 */
final class PatientInviteNotifier
{
    public function __construct(
        private PDO $db,
        private ?Mailer $mailer = null,
        private ?string $appUrl = null
    ) {
    }

    public static function make(?PDO $db = null): self
    {
        return new self($db ?? Connection::getInstance());
    }

    /**
     * @param string $plainToken token em texto puro devolvido por PatientOnboardingService::create()
     * @param string $expiresAtUtc expiração do convite (UTC, 'Y-m-d H:i:s'), para calcular a validade em horas
     * @return array{status: 'sent'|'logged'|'skipped'|'failed', reason?: string}
     */
    public function sendInvite(int $clinicId, int $patientId, string $plainToken, string $expiresAtUtc, ?int $actorUserId = null): array
    {
        $info = $this->patientAndClinic($clinicId, $patientId);
        if ($info === null) {
            // Paciente não encontrado/elegível neste tenant: não é erro de envio, é ausência de alvo.
            $this->audit('PATIENT_APP_INVITE_EMAIL_SKIPPED', $clinicId, $patientId, $actorUserId, 'recipient_not_found');
            return ['status' => 'skipped', 'reason' => 'Paciente não encontrado no consultório.'];
        }

        $email = filter_var((string)$info['email'], FILTER_VALIDATE_EMAIL) ? (string)$info['email'] : null;
        if ($email === null) {
            $this->audit('PATIENT_APP_INVITE_EMAIL_SKIPPED', $clinicId, $patientId, $actorUserId, 'patient_without_email');
            return ['status' => 'skipped', 'reason' => 'Paciente sem e-mail cadastrado. Envie o link manualmente.'];
        }

        $base = $this->canonicalBase();
        if ($base === null) {
            $this->audit('PATIENT_APP_INVITE_EMAIL_FAILED', $clinicId, $patientId, $actorUserId, 'app_url_missing');
            return ['status' => 'failed', 'reason' => 'APP_URL não configurado: o e-mail precisa de um link absoluto.'];
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $plainToken)) {
            $this->audit('PATIENT_APP_INVITE_EMAIL_FAILED', $clinicId, $patientId, $actorUserId, 'invalid_token');
            return ['status' => 'failed', 'reason' => 'Convite inválido para envio.'];
        }

        // Com o transporte do ambiente (sem mailer injetado), 'null' significa envio desativado:
        // não finge que enviou — registra skipped, como faz o NotificationService.
        $delivers = true;
        if ($this->mailer === null) {
            $describe = MailerFactory::describe();
            if (in_array($describe['driver'], ['null', 'none', null], true)) {
                $this->audit('PATIENT_APP_INVITE_EMAIL_SKIPPED', $clinicId, $patientId, $actorUserId, 'mail_disabled');
                return ['status' => 'skipped', 'reason' => 'Envio de e-mail desativado no servidor.'];
            }
            $delivers = (bool)$describe['delivers']; // false no driver 'log'
        }

        $inviteUrl = $base . '/paciente/app?code=' . rawurlencode($plainToken);
        $validityHours = $this->validityHours($expiresAtUtc);

        try {
            $message = PatientInviteEmail::build(
                $email,
                (string)$info['name'],
                (string)$info['clinic_name'],
                $inviteUrl,
                $validityHours,
                self::supportReplyTo()
            );
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            $reason = str_contains($e->getMessage(), 'não configurado') || str_contains($e->getMessage(), 'não instalado')
                ? 'mail_not_configured' : 'smtp_error';
            $this->audit('PATIENT_APP_INVITE_EMAIL_FAILED', $clinicId, $patientId, $actorUserId, $reason);
            return ['status' => 'failed', 'reason' => $reason === 'mail_not_configured'
                ? 'O envio de e-mail não está configurado no servidor.'
                : 'O servidor de e-mail recusou o envio. Tente novamente.'];
        }

        // 'log' entrega em arquivo, não na caixa do paciente: é 'logged', não 'sent'.
        if ($delivers) {
            $this->audit('PATIENT_APP_INVITE_EMAIL_SENT', $clinicId, $patientId, $actorUserId, null, $validityHours);
            return ['status' => 'sent'];
        }
        $this->audit('PATIENT_APP_INVITE_EMAIL_LOGGED', $clinicId, $patientId, $actorUserId, 'log_driver', $validityHours);
        return ['status' => 'logged', 'reason' => 'Registrado no log (envio real exige SMTP configurado).'];
    }

    /** @return array{name:string,email:?string,clinic_name:string}|null */
    private function patientAndClinic(int $clinicId, int $patientId): ?array
    {
        $st = $this->db->prepare(
            "SELECT p.name, p.email, c.name AS clinic_name
             FROM patients p JOIN clinics c ON c.id = p.clinic_id
             WHERE p.id = ? AND p.clinic_id = ? AND p.status = 'active' AND c.status = 'active'"
        );
        $st->execute([$patientId, $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function canonicalBase(): ?string
    {
        $base = rtrim(trim((string)($this->appUrl ?? Env::get('APP_URL') ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            return null;
        }
        if (Transport::isProduction() || Transport::forceHttpsEnabled()) {
            $base = (string)preg_replace('#^http://#i', 'https://', $base);
        }
        return $base;
    }

    private function validityHours(string $expiresAtUtc): int
    {
        $clean = preg_replace('/\s*UTC\s*$/i', '', trim($expiresAtUtc)) ?? $expiresAtUtc;
        $exp = strtotime($clean . ' UTC');
        if ($exp === false) {
            return 48;
        }
        $hours = (int)ceil(($exp - time()) / 3600);
        return max(1, min(168, $hours));
    }

    private static function supportReplyTo(): ?string
    {
        $support = trim((string)(Env::get('APP_SUPPORT_EMAIL') ?? ''));
        return filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : null;
    }

    private function audit(string $action, int $clinicId, int $patientId, ?int $actorUserId, ?string $reason, ?int $validityHours = null): void
    {
        try {
            $details = [];
            if ($reason !== null) {
                $details['reason'] = $reason;
            }
            if ($validityHours !== null) {
                $details['validity_hours'] = $validityHours;
            }
            $details['channel'] = 'email';
            $st = $this->db->prepare(
                "INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                 VALUES (:clinic_id, :user_id, :action, 'patient_app_invitations', :res_id, :ip, :ua, :details, :created_at)"
            );
            $st->execute([
                'clinic_id' => $clinicId,
                'user_id' => $actorUserId,
                'action' => $action,
                'res_id' => (string)$patientId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Auditoria indisponível não pode quebrar a resposta ao profissional.
            error_log('[ClyoNutri][patient-invite] auditoria indisponível: ' . $e->getMessage());
        }
    }
}
