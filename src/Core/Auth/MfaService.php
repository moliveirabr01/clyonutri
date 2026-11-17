<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\LoginRateLimiter;
use ClyoNutri\Core\Security\TotpService;
use PDO;

/**
 * Gerenciamento de MFA (TOTP + códigos de recuperação) para plataforma e profissionais.
 * NI2-859 (SEG360-SEC-004).
 */
final class MfaService
{
    public const REQUIRED_ROLES = ['superadmin', 'admin_operacional', 'support_agent'];
    public const MAX_ATTEMPTS = 5;

    private PDO $db;
    private AuditLogger $audit;

    public function __construct(?PDO $db = null, ?AuditLogger $audit = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->audit = $audit ?? new AuditLogger($this->db);
    }

    /**
     * v54.71: MFA dos perfis de plataforma suspenso por decisão do Maicon (16/11/2026) até nova liberação.
     * Para voltar a exigir, ponha MFA_PLATFORM_REQUIRED=true no .env (sem mudar código).
     */
    public static function platformEnforced(): bool
    {
        $v = strtolower(trim((string)(\ClyoNutri\Core\Security\Env::get('MFA_PLATFORM_REQUIRED') ?? '')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function isRequiredForRole(string $role): bool
    {
        return self::platformEnforced() && in_array($role, self::REQUIRED_ROLES, true);
    }

    public function status(int $userId): array
    {
        if (!\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'users', 'mfa_enabled')) {
            return ['enabled' => false, 'required' => false, 'enrolled_at' => null, 'recovery_remaining' => 0];
        }
        $st = $this->db->prepare('SELECT id, role, mfa_enabled, mfa_enrolled_at, mfa_recovery_codes_json FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['enabled' => false, 'required' => false, 'enrolled_at' => null, 'recovery_remaining' => 0];
        }
        $codes = json_decode((string)($row['mfa_recovery_codes_json'] ?? '[]'), true);
        $role = (string)($row['role'] ?? '');
        // v54.71: com o MFA de plataforma suspenso, um MFA já cadastrado por esses perfis também não é pedido no login.
        $suspended = !self::platformEnforced() && \ClyoNutri\Core\Security\RoleModel::isPlatformConsole($role);
        return [
            'enabled' => !empty($row['mfa_enabled']) && !$suspended,
            'required' => self::isRequiredForRole((string)($row['role'] ?? '')),
            'enrolled_at' => $row['mfa_enrolled_at'] ?? null,
            'recovery_remaining' => is_array($codes) ? count($codes) : 0,
        ];
    }

    public function beginEnrollment(string $email): array
    {
        $secret = TotpService::generateSecret();
        return [
            'secret' => $secret,
            'provisioning_uri' => TotpService::provisioningUri($secret, $email),
        ];
    }

    /**
     * Ativa TOTP após validar um código TOTP gerado com o segredo.
     * @return array{recovery_codes:list<string>}
     */
    public function confirmEnrollment(int $userId, string $secret, string $code): array
    {
        $matchedStep = null;
        if (!TotpService::verifyCode($secret, $code, 1, null, $matchedStep)) {
            throw new \InvalidArgumentException('Código TOTP inválido. Confira o relógio do autenticador e tente novamente.');
        }

        $recovery = TotpService::generateRecoveryCodes(8);
        $now = gmdate('Y-m-d H:i:s');
        $st = $this->db->prepare('UPDATE users SET mfa_enabled = 1, mfa_secret_enc = :sec, mfa_recovery_codes_json = :rc, mfa_enrolled_at = :ea, mfa_last_step = :ls WHERE id = :id');
        $st->execute([
            'sec' => $this->encryptSecret($secret),
            'rc' => json_encode($recovery['hashes']),
            'ea' => $now,
            'ls' => $matchedStep,
            'id' => $userId,
        ]);

        $this->audit->log('AUTH_MFA_ENROLLED', 'user', (string)$userId, ['method' => 'totp']);
        return ['recovery_codes' => $recovery['plain']];
    }

    /**
     * Valida desafio MFA (TOTP ou código de recuperação) com limite de 5 tentativas e proteção contra replay.
     * @return array{ok:bool,method:?string,locked:bool,retry_after:int,error:?string}
     */
    public function verifyChallenge(int $userId, string $code, string $ip = '0.0.0.0', ?int $timestamp = null): array
    {
        $limiter = new LoginRateLimiter($this->db);
        $accountKey = 'mfa#' . $userId;
        $gate = $limiter->attempt('mfa', $accountKey, $ip, self::MAX_ATTEMPTS, 900);
        if (!$gate['allowed']) {
            $this->audit->log('AUTH_MFA_RATE_LIMITED', 'user', (string)$userId, ['retry_after' => (int)$gate['retry_after']]);
            return [
                'ok' => false,
                'method' => null,
                'locked' => true,
                'retry_after' => (int)$gate['retry_after'],
                'error' => 'Muitas tentativas inválidas de MFA. Aguarde antes de tentar novamente.',
            ];
        }

        $st = $this->db->prepare('SELECT id, mfa_enabled, mfa_secret_enc, mfa_recovery_codes_json, mfa_last_step FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user['mfa_enabled']) || empty($user['mfa_secret_enc'])) {
            return ['ok' => false, 'method' => null, 'locked' => false, 'retry_after' => 0, 'error' => 'MFA não configurado para esta conta.'];
        }

        $secret = $this->decryptSecret((string)$user['mfa_secret_enc']);
        $lastStep = $user['mfa_last_step'] !== null ? (int)$user['mfa_last_step'] : null;
        $matchedStep = null;

        if ($secret !== null && TotpService::verifyCode($secret, $code, 1, $lastStep, $matchedStep, $timestamp)) {
            $this->db->prepare('UPDATE users SET mfa_last_step = :ls WHERE id = :id')
                ->execute(['ls' => $matchedStep, 'id' => $userId]);
            $limiter->success('mfa', $accountKey, $ip);
            $this->audit->log('AUTH_MFA_VERIFIED', 'user', (string)$userId, ['method' => 'totp']);
            return ['ok' => true, 'method' => 'totp', 'locked' => false, 'retry_after' => 0, 'error' => null];
        }

        $hashes = json_decode((string)($user['mfa_recovery_codes_json'] ?? '[]'), true);
        $hashes = is_array($hashes) ? $hashes : [];
        $matchedIdx = null;
        if (TotpService::verifyRecoveryCode($code, $hashes, $matchedIdx)) {
            array_splice($hashes, (int)$matchedIdx, 1);
            $this->db->prepare('UPDATE users SET mfa_recovery_codes_json = :rc WHERE id = :id')
                ->execute(['rc' => json_encode(array_values($hashes)), 'id' => $userId]);
            $limiter->success('mfa', $accountKey, $ip);
            $this->audit->log('AUTH_MFA_RECOVERY_USED', 'user', (string)$userId, ['remaining' => count($hashes)]);
            return ['ok' => true, 'method' => 'recovery_code', 'locked' => false, 'retry_after' => 0, 'error' => null];
        }

        $limiter->failure('mfa', $accountKey, $ip, 900);
        $this->audit->log('AUTH_MFA_FAILED', 'user', (string)$userId, []);
        return ['ok' => false, 'method' => null, 'locked' => false, 'retry_after' => 0, 'error' => 'Código TOTP ou de recuperação inválido.'];
    }

    public function disable(int $userId): void
    {
        $st = $this->db->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $userId]);
        $role = (string)($st->fetchColumn() ?: '');
        if (self::isRequiredForRole($role)) {
            throw new \DomainException('MFA é obrigatório para o seu papel de plataforma e não pode ser desativado.');
        }
        $this->db->prepare('UPDATE users SET mfa_enabled = 0, mfa_secret_enc = NULL, mfa_recovery_codes_json = NULL, mfa_enrolled_at = NULL, mfa_last_step = NULL WHERE id = :id')
            ->execute(['id' => $userId]);
        $this->audit->log('AUTH_MFA_DISABLED', 'user', (string)$userId, []);
    }

    private function encryptSecret(string $secret): string
    {
        $key = hash('sha256', (string)(Env::get('CLINICAL_ENCRYPTION_KEY') ?: Env::get('APP_KEY') ?: 'clyonutri-mfa-local'), true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Falha ao cifrar segredo MFA.');
        }
        return 'mfa:v1:' . base64_encode($iv . $tag . $cipher);
    }

    private function decryptSecret(string $stored): ?string
    {
        if (!str_starts_with($stored, 'mfa:v1:')) {
            return null;
        }
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', (string)(Env::get('CLINICAL_ENCRYPTION_KEY') ?: Env::get('APP_KEY') ?: 'clyonutri-mfa-local'), true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }
}
