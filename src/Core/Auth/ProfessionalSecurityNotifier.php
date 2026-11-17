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
     * e confirma no endereço NOVO com token de verificação.
     * @return array{old:array{status:string,reason?:string}, new:array{status:string,reason?:string}, verification_token:string}
     */
    public function emailChanged(int $userId, string $oldEmail, string $newEmail, ?string $ip = null, ?string $ua = null): array
    {
        $name = (string)($this->user($userId)['name'] ?? '');
        $maskedNew = self::maskEmail($newEmail);
        $maskedOld = self::maskEmail($oldEmail);
        $token = $this->generateEmailToken($userId, $newEmail);
        $old = $this->sendTo($userId, $oldEmail, $name, 'PROFESSIONAL_EMAIL_CHANGED_OLD', 'O e-mail de acesso foi alterado', 'O e-mail de acesso foi alterado', [
            'O e-mail de login da sua conta no Clyo Nutri foi alterado para ' . $maskedNew . '.',
            'Se foi você, pode ignorar este aviso.',
            'Se não foi você, fale com o suporte imediatamente: quem tiver o novo e-mail pode acessar a conta.',
        ], null, null, $ip, $ua);
        $new = $this->sendTo($userId, $newEmail, $name, 'PROFESSIONAL_EMAIL_CHANGED_NEW', 'Este agora é o seu e-mail de acesso', 'Este agora é o seu e-mail de acesso', [
            'Este endereço passou a ser o e-mail de login da sua conta no Clyo Nutri (o anterior era ' . $maskedOld . ').',
            'Confirme seu novo e-mail clicando no botão abaixo para garantir o acesso.',
        ], 'Confirmar novo e-mail', '/verificar-email/' . rawurlencode($token), $ip, $ua);
        return ['old' => $old, 'new' => $new, 'verification_token' => $token];
    }

    public static function signingKey(): string
    {
        $k = (string)(Env::get('APP_KEY') ?: '');
        if (strlen($k) >= 16) {
            return $k;
        }
        $appEnv = strtolower((string)(Env::get('APP_ENV') ?: 'local'));
        if ($appEnv === 'production' || $appEnv === 'prod') {
            throw new \RuntimeException('APP_KEY ausente ou fraca em produção para assinatura de tokens de verificação.');
        }
        return hash('sha256', __FILE__ . '|' . (string)Env::get('DB_DATABASE'));
    }

    public function generateEmailToken(int $userId, string $email): string
    {
        $payload = base64_encode((string)json_encode([
            'uid' => $userId,
            'email' => strtolower(trim($email)),
            'exp' => time() + 86400 * 7,
            'action' => 'verify_email',
        ], JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $payload, self::signingKey());
        return $payload . '.' . $sig;
    }

    public function verifyEmailToken(string $token, ?string $ip = null, ?string $ua = null): ?int
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2) {
            return null;
        }
        [$payloadB64, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $payloadB64, self::signingKey());
        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }
        $json = base64_decode($payloadB64, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['action'] ?? '') !== 'verify_email') {
            return null;
        }
        $exp = (int)($data['exp'] ?? 0);
        if ($exp < time()) {
            return null;
        }
        $userId = (int)($data['uid'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        $user = $this->user($userId);
        if ($user === null) {
            return null;
        }
        $tokenEmail = strtolower(trim((string)($data['email'] ?? '')));
        $currentEmail = strtolower(trim((string)($user['email'] ?? '')));
        if ($tokenEmail === '' || $currentEmail === '' || !hash_equals($currentEmail, $tokenEmail)) {
            return null;
        }
        $clinicId = !empty($user['clinic_id']) ? (int)$user['clinic_id'] : null;
        $this->db->prepare("UPDATE users SET is_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$userId]);
        $this->audit($clinicId, $userId, 'PROFESSIONAL_EMAIL_VERIFIED', $ip, $ua, null);
        return $userId;
    }

    /**
     * ET08 + NI2-213: Boas-vindas ao profissional no cadastro com token de verificação de e-mail.
     * @return array{status:'sent'|'logged'|'skipped'|'failed', verification_token:string, reason?:string}
     */
    public function welcomeProfessional(int $userId, string $clinicName, string $trialEndsAt): array
    {
        $user = $this->user($userId);
        $email = $user !== null && filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) ? (string)$user['email'] : null;
        $clinicId = $user !== null && !empty($user['clinic_id']) ? (int)$user['clinic_id'] : null;
        if ($user === null || $email === null) {
            $this->audit($clinicId, $userId, 'PROFESSIONAL_WELCOME_SKIPPED', null, null, 'user_without_email');
            return ['status' => 'skipped', 'reason' => 'Usuário sem e-mail válido.', 'verification_token' => ''];
        }

        $token = $this->generateEmailToken($userId, $email);

        $delivers = true;
        if ($this->mailer === null) {
            $describe = MailerFactory::describe();
            if (in_array($describe['driver'], ['null', 'none', null], true)) {
                $this->audit($clinicId, $userId, 'PROFESSIONAL_WELCOME_SKIPPED', null, null, 'mail_disabled');
                return ['status' => 'skipped', 'reason' => 'Envio de e-mail desativado no servidor.', 'verification_token' => $token];
            }
            $delivers = (bool)$describe['delivers'];
        }

        $base = $this->canonicalBase();
        $actionUrl = $base !== null ? $base . '/verificar-email/' . rawurlencode($token) : null;
        $trialEndFormatted = date('d/m/Y', strtotime($trialEndsAt));

        try {
            $message = SecurityNoticeEmail::build(
                $email,
                (string)$user['name'],
                'Bem-vindo(a) ao Clyo Nutri — Confirme seu e-mail',
                'Bem-vindo(a) ao Clyo Nutri',
                [
                    'Sua conta para o consultório ' . $clinicName . ' foi criada com sucesso.',
                    'Seu período de teste vai até ' . $trialEndFormatted . '.',
                    'Para ativar todos os recursos e garantir a segurança do seu acesso, confirme seu endereço de e-mail.',
                ],
                'Confirmar meu e-mail',
                $actionUrl,
                self::supportReplyTo()
            );
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            $this->audit($clinicId, $userId, 'PROFESSIONAL_WELCOME_FAILED', null, null, str_contains($e->getMessage(), 'não configurado') ? 'mail_not_configured' : 'smtp_error');
            return ['status' => 'failed', 'reason' => 'Falha ao enviar o e-mail de boas-vindas.', 'verification_token' => $token];
        }

        $this->audit($clinicId, $userId, 'PROFESSIONAL_WELCOME_' . ($delivers ? 'SENT' : 'LOGGED'), null, null, $delivers ? null : 'log_driver');
        return ['status' => $delivers ? 'sent' : 'logged', 'verification_token' => $token];
    }

    /**
     * ET09: Avisos de ciclo de trial ao titular (proximidade de fim de trial com deduplicação).
     * @return array{status:'sent'|'logged'|'skipped'|'failed', reason?:string}
     */
    public function trialExpiringSoon(int $userId, string $clinicName, string $planName, string $expiresAt): array
    {
        $user = $this->user($userId);
        $email = $user !== null && filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) ? (string)$user['email'] : null;
        $clinicId = $user !== null && !empty($user['clinic_id']) ? (int)$user['clinic_id'] : null;
        if ($user === null || $email === null) {
            $this->audit($clinicId, $userId, 'PROFESSIONAL_TRIAL_EXPIRING_SOON_SKIPPED', null, null, 'user_without_email');
            return ['status' => 'skipped', 'reason' => 'Usuário sem e-mail válido.'];
        }

        $dateKey = date('Y-m-d', strtotime($expiresAt));
        $stCheck = $this->db->prepare("SELECT id FROM audit_logs WHERE user_id = :u AND action IN ('PROFESSIONAL_TRIAL_EXPIRING_SOON_SENT','PROFESSIONAL_TRIAL_EXPIRING_SOON_LOGGED') AND details LIKE :d LIMIT 1");
        $stCheck->execute(['u' => $userId, 'd' => '%' . $dateKey . '%']);
        if ($stCheck->fetchColumn()) {
            return ['status' => 'skipped', 'reason' => 'already_notified'];
        }

        $delivers = true;
        if ($this->mailer === null) {
            $describe = MailerFactory::describe();
            if (in_array($describe['driver'], ['null', 'none', null], true)) {
                $this->audit($clinicId, $userId, 'PROFESSIONAL_TRIAL_EXPIRING_SOON_SKIPPED', null, null, 'mail_disabled');
                return ['status' => 'skipped', 'reason' => 'Envio de e-mail desativado no servidor.'];
            }
            $delivers = (bool)$describe['delivers'];
        }

        $base = $this->canonicalBase();
        $actionUrl = $base !== null ? $base . '/configuracoes/assinatura' : null;

        try {
            $message = SecurityNoticeEmail::build(
                $email,
                (string)$user['name'],
                'Seu período de teste no Clyo Nutri encerra em breve',
                'Período de teste encerra em breve',
                [
                    'Seu período de avaliação gratuita do plano ' . $planName . ' no consultório ' . $clinicName . ' encerra em ' . date('d/m/Y', strtotime($expiresAt)) . '.',
                    'Para continuar utilizando todas as funcionalidades clínicas e relatórios sem interrupções, configure seu plano definitivo.',
                ],
                'Ver planos e assinatura',
                $actionUrl,
                self::supportReplyTo()
            );
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable $e) {
            $this->audit($clinicId, $userId, 'PROFESSIONAL_TRIAL_EXPIRING_SOON_FAILED', null, null, 'smtp_error');
            return ['status' => 'failed', 'reason' => 'Falha no envio do aviso de trial.'];
        }

        $action = $delivers ? 'PROFESSIONAL_TRIAL_EXPIRING_SOON_SENT' : 'PROFESSIONAL_TRIAL_EXPIRING_SOON_LOGGED';
        $this->audit($clinicId, $userId, $action, null, null, 'expires_at:' . $dateKey);
        return ['status' => $delivers ? 'sent' : 'logged'];
    }

    /**
     * ET09: Aviso de trial encerrado.
     * @return array{status:'sent'|'logged'|'skipped'|'failed', reason?:string}
     */
    public function trialExpired(int $userId, string $clinicName, string $planName): array
    {
        $user = $this->user($userId);
        $email = $user !== null && filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) ? (string)$user['email'] : null;
        $clinicId = $user !== null && !empty($user['clinic_id']) ? (int)$user['clinic_id'] : null;
        if ($user === null || $email === null) {
            return ['status' => 'skipped', 'reason' => 'user_without_email'];
        }

        $stCheck = $this->db->prepare("SELECT id FROM audit_logs WHERE user_id = :u AND action IN ('PROFESSIONAL_TRIAL_EXPIRED_SENT','PROFESSIONAL_TRIAL_EXPIRED_LOGGED') LIMIT 1");
        $stCheck->execute(['u' => $userId]);
        if ($stCheck->fetchColumn()) {
            return ['status' => 'skipped', 'reason' => 'already_notified'];
        }

        $delivers = true;
        if ($this->mailer === null) {
            $describe = MailerFactory::describe();
            if (in_array($describe['driver'], ['null', 'none', null], true)) {
                return ['status' => 'skipped', 'reason' => 'mail_disabled'];
            }
            $delivers = (bool)$describe['delivers'];
        }

        $base = $this->canonicalBase();
        $actionUrl = $base !== null ? $base . '/configuracoes/assinatura' : null;

        try {
            $message = SecurityNoticeEmail::build(
                $email,
                (string)$user['name'],
                'Período de avaliação gratuita encerrado',
                'Período de avaliação encerrado',
                [
                    'O período de teste da sua assinatura (' . $planName . ') para o consultório ' . $clinicName . ' encerrou.',
                    'Seus dados e prontuários estão salvos com segurança, mas a inclusão de novos registros fica suspensa até a ativação da assinatura.',
                ],
                'Ativar assinatura',
                $actionUrl,
                self::supportReplyTo()
            );
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);
        } catch (\Throwable) {
            return ['status' => 'failed'];
        }

        $action = $delivers ? 'PROFESSIONAL_TRIAL_EXPIRED_SENT' : 'PROFESSIONAL_TRIAL_EXPIRED_LOGGED';
        $this->audit($clinicId, $userId, $action, null, null, null);
        return ['status' => $delivers ? 'sent' : 'logged'];
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
