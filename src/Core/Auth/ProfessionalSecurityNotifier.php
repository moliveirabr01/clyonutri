<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\SecurityNoticeEmail;
use ClyoNutri\Core\Security\Env;
use PDO;

/**
 * NI2-481 — Avisos de segurança ao PROFISSIONAL (senha alterada).
 *
 * Alerta de segurança essencial (não é comunicação opcional): sempre tentamos enviar.
 * Neutro, sem dado clínico, senha, OTP ou token. Link canônico de APP_URL.
 * Estados: sent / logged (driver log) / skipped (sem e-mail ou driver null) / failed.
 */
final class ProfessionalSecurityNotifier
{
    public function __construct(private PDO $db, private ?Mailer $mailer = null)
    {
    }

    public static function make(?PDO $db = null): self
    {
        return new self($db ?? Connection::getInstance());
    }

    /** @return array{status:'sent'|'logged'|'skipped'|'failed', reason?:string} */
    public function passwordChanged(int $userId, ?string $ip = null, ?string $ua = null): array
    {
        return $this->notify($userId, 'PROFESSIONAL_PASSWORD_CHANGED_NOTICE', 'Sua senha foi alterada', 'Sua senha foi alterada', [
            'A senha de acesso à sua conta no Clyo Nutri foi alterada agora.',
            'Se foi você, não precisa fazer nada.',
            'Se não foi você, crie uma nova senha o quanto antes e avise o suporte.',
        ], 'Criar nova senha', '/recuperar-senha', $ip, $ua);
    }

    /** Conta suspensa por um administrador da plataforma. */
    public function accountSuspended(int $userId, ?string $ip = null, ?string $ua = null): array
    {
        return $this->notify($userId, 'PROFESSIONAL_ACCOUNT_SUSPENDED_NOTICE', 'Seu acesso foi suspenso', 'Seu acesso foi suspenso', [
            'O acesso da sua conta no Clyo Nutri foi suspenso pela administração.',
            'Seus dados continuam guardados. Enquanto a suspensão estiver ativa, você não consegue entrar.',
            'Se tiver dúvidas, fale com o suporte.',
        ], null, null, $ip, $ua);
    }

    /** v33: conta bloqueada por segurança por um administrador (sem link: só o superadmin libera). */
    public function accountBlocked(int $userId, ?string $ip = null, ?string $ua = null): array
    {
        return $this->notify($userId, 'PROFESSIONAL_ACCOUNT_BLOCKED_NOTICE', 'Seu acesso foi bloqueado por segurança', 'Seu acesso foi bloqueado por segurança', [
            'Por segurança, o acesso da sua conta no Clyo Nutri foi bloqueado pela administração e todas as sessões foram encerradas.',
            'Seus dados continuam guardados. Para voltar a entrar, fale com o suporte da Clyo Nutri; você vai precisar criar uma nova senha.',
        ], null, null, $ip, $ua);
    }

    /** Conta reativada por um administrador. */
    public function accountReactivated(int $userId, ?string $ip = null, ?string $ua = null): array
    {
        return $this->notify($userId, 'PROFESSIONAL_ACCOUNT_REACTIVATED_NOTICE', 'Seu acesso foi reativado', 'Seu acesso foi reativado', [
            'O acesso da sua conta no Clyo Nutri foi reativado pela administração.',
            'Você já pode entrar normalmente.',
        ], 'Entrar', '/login', $ip, $ua);
    }

    /** Todas as sessões ativas foram encerradas por um administrador. */
    public function sessionsRevoked(int $userId, ?string $ip = null, ?string $ua = null): array
    {
        return $this->notify($userId, 'PROFESSIONAL_SESSIONS_REVOKED_NOTICE', 'Suas sessões foram encerradas', 'Suas sessões foram encerradas', [
            'Por segurança, todas as sessões ativas da sua conta no Clyo Nutri foram encerradas.',
            'Se foi você ou o suporte, basta entrar de novo.',
            'Se não reconhece esta ação, crie uma nova senha e avise o suporte.',
        ], 'Entrar de novo', '/login', $ip, $ua);
    }

    /**
     * NI2-481 P1d: o e-mail de acesso foi trocado. Alerta o endereço ANTIGO (pode reagir se não foi ele)
     * e confirma no endereço NOVO. Endereços explícitos (o registro do usuário já tem o novo).
     * @return array{old:array{status:string,reason?:string}, new:array{status:string,reason?:string}}
     */
    public function emailChanged(int $userId, string $oldEmail, string $newEmail, ?string $ip = null, ?string $ua = null): array
    {
        $name = (string)($this->user($userId)['name'] ?? '');
        $maskedNew = self::maskEmail($newEmail);
        $maskedOld = self::maskEmail($oldEmail);
        $old = $this->sendTo($userId, $oldEmail, $name, 'PROFESSIONAL_EMAIL_CHANGED_OLD', 'O e-mail de acesso foi alterado', 'O e-mail de acesso foi alterado', [
            'O e-mail de login da sua conta no Clyo Nutri foi alterado para ' . $maskedNew . '.',
            'Se foi você, pode ignorar este aviso.',
            'Se não foi você, fale com o suporte imediatamente: quem tiver o novo e-mail pode acessar a conta.',
        ], null, null, $ip, $ua);
        $new = $this->sendTo($userId, $newEmail, $name, 'PROFESSIONAL_EMAIL_CHANGED_NEW', 'Este agora é o seu e-mail de acesso', 'Este agora é o seu e-mail de acesso', [
            'Este endereço passou a ser o e-mail de login da sua conta no Clyo Nutri (o anterior era ' . $maskedOld . ').',
            'Se você não fez essa mudança, fale com o suporte imediatamente.',
        ], 'Entrar', '/login', $ip, $ua);
        return ['old' => $old, 'new' => $new];
    }

    private static function maskEmail(string $email): string
    {
        $email = trim($email);
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $keep = max(1, min(2, strlen($local) - 1));
        return substr($local, 0, $keep) . str_repeat('*', max(1, strlen($local) - $keep)) . $domain;
    }

    /** Envio para um endereço explícito (usado quando o alvo não é o e-mail atual do registro). */
    private function sendTo(int $userId, string $email, string $name, string $auditPrefix, string $subject, string $headline, array $lines, ?string $actionLabel, ?string $actionPath, ?string $ip, ?string $ua): array
    {
        $clinicId = !empty($this->user($userId)['clinic_id']) ? (int)$this->user($userId)['clinic_id'] : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->audit($clinicId, $userId, $auditPrefix . '_SKIPPED', $ip, $ua, 'invalid_email');
            return ['status' => 'skipped', 'reason' => 'E-mail inválido.'];
        }
        $delivers = true;
        if ($this->mailer === null) {
            $describe = MailerFactory::describe();
            if (in_array($describe['driver'], ['null', 'none', null], true)) {
                $this->audit($clinicId, $userId, $auditPrefix . '_SKIPPED', $ip, $ua, 'mail_disabled');
                return ['status' => 'skipped', 'reason' => 'Envio desativado.'];
            }
            $delivers = (bool)$describe['delivers'];
        }
        $base = $this->canonicalBase();
        $actionUrl = ($actionLabel !== null && $actionPath !== null && $base !== null) ? $base . $actionPath : null;
        if ($actionUrl === null) {
            $actionLabel = null;
        }
        try {
            $message = SecurityNoticeEmail::build($email, $name, $subject, $headline, $lines, $actionLabel, $actionUrl, self::supportReplyTo());
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            $this->audit($clinicId, $userId, $auditPrefix . '_FAILED', $ip, $ua, str_contains($e->getMessage(), 'não configurado') ? 'mail_not_configured' : 'smtp_error');
            return ['status' => 'failed', 'reason' => 'Falha no envio.'];
        }
        $this->audit($clinicId, $userId, $auditPrefix . ($delivers ? '_SENT' : '_LOGGED'), $ip, $ua, $delivers ? null : 'log_driver');
        return ['status' => $delivers ? 'sent' : 'logged'];
    }

    /**
     * @param list<string> $lines
     * @return array{status:'sent'|'logged'|'skipped'|'failed', reason?:string}
     */
    private function notify(int $userId, string $auditPrefix, string $subject, string $headline, array $lines, ?string $actionLabel, ?string $actionPath, ?string $ip, ?string $ua): array
    {
        $user = $this->user($userId);
        $email = $user !== null && filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) ? (string)$user['email'] : null;
        $clinicId = $user !== null && !empty($user['clinic_id']) ? (int)$user['clinic_id'] : null;
        if ($user === null || $email === null) {
            $this->audit($clinicId, $userId, $auditPrefix . '_SKIPPED', $ip, $ua, 'user_without_email');
            return ['status' => 'skipped', 'reason' => 'Usuário sem e-mail válido.'];
        }

        $delivers = true;
        if ($this->mailer === null) {
            $describe = MailerFactory::describe();
            if (in_array($describe['driver'], ['null', 'none', null], true)) {
                $this->audit($clinicId, $userId, $auditPrefix . '_SKIPPED', $ip, $ua, 'mail_disabled');
                return ['status' => 'skipped', 'reason' => 'Envio de e-mail desativado no servidor.'];
            }
            $delivers = (bool)$describe['delivers'];
        }

        $base = $this->canonicalBase();
        $actionUrl = ($actionLabel !== null && $actionPath !== null && $base !== null) ? $base . $actionPath : null;
        if ($actionUrl === null) {
            $actionLabel = null;
        }

        try {
            $message = SecurityNoticeEmail::build((string)$email, (string)$user['name'], $subject, $headline, $lines, $actionLabel, $actionUrl, self::supportReplyTo());
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            $this->audit($clinicId, $userId, $auditPrefix . '_FAILED', $ip, $ua, str_contains($e->getMessage(), 'não configurado') ? 'mail_not_configured' : 'smtp_error');
            return ['status' => 'failed', 'reason' => 'Falha ao enviar o aviso de segurança.'];
        }

        if ($delivers) {
            $this->audit($clinicId, $userId, $auditPrefix . '_SENT', $ip, $ua, null);
            return ['status' => 'sent'];
        }
        $this->audit($clinicId, $userId, $auditPrefix . '_LOGGED', $ip, $ua, 'log_driver');
        return ['status' => 'logged'];
    }

    /** @return array{name:string,email:?string,clinic_id:?int}|null Resolve independentemente do status (p.ex. conta recém-suspensa). */
    private function user(int $userId): ?array
    {
        $st = $this->db->prepare(
            "SELECT u.name, u.email, cm.clinic_id
             FROM users u LEFT JOIN clinic_members cm ON cm.user_id = u.id AND cm.status = 'active'
             WHERE u.id = ? LIMIT 1"
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function canonicalBase(): ?string
    {
        $base = rtrim(trim((string)(Env::get('APP_URL') ?? '')), '/');
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            return null;
        }
        if (Transport::isProduction() || Transport::forceHttpsEnabled()) {
            $base = (string)preg_replace('#^http://#i', 'https://', $base);
        }
        return $base;
    }

    private static function supportReplyTo(): ?string
    {
        $support = trim((string)(Env::get('APP_SUPPORT_EMAIL') ?? ''));
        return filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : null;
    }

    private function audit(?int $clinicId, int $userId, string $action, ?string $ip, ?string $ua, ?string $reason): void
    {
        try {
            $details = ['channel' => 'email'];
            if ($reason !== null) {
                $details['reason'] = $reason;
            }
            $st = $this->db->prepare(
                "INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                 VALUES (:c, :u, :a, 'users', :r, :ip, :ua, :d, :n)"
            );
            $st->execute([
                'c' => $clinicId,
                'u' => $userId,
                'a' => $action,
                'r' => (string)$userId,
                'ip' => $ip,
                'ua' => $ua !== null ? substr($ua, 0, 255) : null,
                'd' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'n' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][prof-security] auditoria indisponível: ' . $e->getMessage());
        }
    }
}
