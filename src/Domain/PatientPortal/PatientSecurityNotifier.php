<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PatientPortal;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\SecurityNoticeEmail;
use ClyoNutri\Core\Security\Env;
use PDO;

/**
 * NI2-481 — Avisos de segurança ao paciente (senha alterada, e-mail alterado).
 *
 * São alertas de segurança essenciais, não comunicações opcionais: sempre tentamos enviar,
 * sem depender de preferências de notificação. Neutros, sem dado clínico, senha, OTP ou token.
 * Estados: sent / logged (driver log) / skipped (sem e-mail ou driver null) / failed.
 */
final class PatientSecurityNotifier
{
    public function __construct(private PDO $db, private ?Mailer $mailer = null)
    {
    }

    public static function make(?PDO $db = null): self
    {
        return new self($db ?? Connection::getInstance());
    }

    /** Aviso de que a senha do app foi alterada. */
    public function passwordChanged(int $clinicId, int $patientId, ?string $ip = null, ?string $ua = null, ?string $overrideEmail = null): array
    {
        $lines = [
            'A senha de acesso ao app do Clyo Nutri foi alterada agora.',
            'Se foi você, não precisa fazer nada.',
            'Se não foi você, crie uma nova senha o quanto antes na página de recuperação e avise o seu consultório.',
        ];
        return $this->notify(
            $clinicId,
            $patientId,
            'PATIENT_PASSWORD_CHANGED_NOTICE',
            'Sua senha foi alterada',
            'Sua senha foi alterada',
            $lines,
            'Criar nova senha',
            '/paciente/recuperar-senha',
            $ip,
            $ua,
            $overrideEmail
        );
    }

    /**
     * @param list<string> $lines
     * @return array{status:'sent'|'logged'|'skipped'|'failed', reason?:string}
     */
    private function notify(int $clinicId, int $patientId, string $auditPrefix, string $subject, string $headline, array $lines, ?string $actionLabel, ?string $actionPath, ?string $ip, ?string $ua, ?string $overrideEmail): array
    {
        $info = $this->patient($clinicId, $patientId);
        $email = $overrideEmail ?? ($info['email'] ?? null);
        $email = filter_var((string)$email, FILTER_VALIDATE_EMAIL) ? (string)$email : null;
        if ($info === null || $email === null) {
            $this->audit($clinicId, $patientId, $auditPrefix . '_SKIPPED', $ip, $ua, 'patient_without_email');
            return ['status' => 'skipped', 'reason' => 'Paciente sem e-mail cadastrado.'];
        }

        $delivers = true;
        if ($this->mailer === null) {
            $describe = MailerFactory::describe();
            if (in_array($describe['driver'], ['null', 'none', null], true)) {
                $this->audit($clinicId, $patientId, $auditPrefix . '_SKIPPED', $ip, $ua, 'mail_disabled');
                return ['status' => 'skipped', 'reason' => 'Envio de e-mail desativado no servidor.'];
            }
            $delivers = (bool)$describe['delivers'];
        }

        $actionUrl = null;
        if ($actionLabel !== null && $actionPath !== null) {
            $base = $this->canonicalBase();
            $actionUrl = $base !== null ? $base . $actionPath : null;
            if ($actionUrl === null) {
                // Sem APP_URL, envia o aviso sem botão (o texto ainda orienta).
                $actionLabel = null;
            }
        }

        try {
            $message = SecurityNoticeEmail::build($email, (string)$info['name'], $subject, $headline, $lines, $actionLabel, $actionUrl, self::supportReplyTo());
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            $this->audit($clinicId, $patientId, $auditPrefix . '_FAILED', $ip, $ua, str_contains($e->getMessage(), 'não configurado') ? 'mail_not_configured' : 'smtp_error');
            return ['status' => 'failed', 'reason' => 'Falha ao enviar o aviso de segurança.'];
        }

        if ($delivers) {
            $this->audit($clinicId, $patientId, $auditPrefix . '_SENT', $ip, $ua, null);
            return ['status' => 'sent'];
        }
        $this->audit($clinicId, $patientId, $auditPrefix . '_LOGGED', $ip, $ua, 'log_driver');
        return ['status' => 'logged'];
    }

    /** @return array{name:string,email:?string}|null */
    private function patient(int $clinicId, int $patientId): ?array
    {
        $st = $this->db->prepare("SELECT name, email FROM patients WHERE id = ? AND clinic_id = ? AND status = 'active'");
        $st->execute([$patientId, $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function canonicalBase(): ?string
    {
        $base = rtrim(trim((string)(Env::get('APP_URL') ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            return null;
        }
        if (\ClyoNutri\Core\Http\Transport::isProduction() || \ClyoNutri\Core\Http\Transport::forceHttpsEnabled()) {
            $base = (string)preg_replace('#^http://#i', 'https://', $base);
        }
        return $base;
    }

    private static function supportReplyTo(): ?string
    {
        $support = trim((string)(Env::get('APP_SUPPORT_EMAIL') ?? ''));
        return filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : null;
    }

    private function audit(int $clinicId, int $patientId, string $action, ?string $ip, ?string $ua, ?string $reason): void
    {
        try {
            $details = ['channel' => 'email'];
            if ($reason !== null) {
                $details['reason'] = $reason;
            }
            $st = $this->db->prepare(
                "INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                 VALUES (:c, NULL, :a, 'patients', :r, :ip, :ua, :d, :n)"
            );
            $st->execute([
                'c' => $clinicId,
                'a' => $action,
                'r' => (string)$patientId,
                'ip' => $ip,
                'ua' => $ua !== null ? substr($ua, 0, 255) : null,
                'd' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'n' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][patient-security] auditoria indisponível: ' . $e->getMessage());
        }
    }
}
