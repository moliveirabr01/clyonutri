<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PatientPortal;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Http\Transport;
use ClyoNutri\Core\Mail\Mailer;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Mail\PatientPasswordResetEmail;
use ClyoNutri\Core\Security\Env;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * NI2-481 — Recuperação de senha do PACIENTE (app web).
 *
 * Espelha o serviço dos profissionais (PX-29/NI2-322), mas escopado por patient_id:
 * - Token forte (64 hex), só o hash SHA-256 persiste em patient_password_resets.
 * - Expiração estrita de 30 min (UTC) e uso único (used_at).
 * - Resposta uniforme e não-enumerável (não revela se o e-mail existe).
 * - Rate limit por e-mail e por IP via audit_logs.
 * - Ao redefinir: revoga os tokens de app do paciente (personal_access_tokens) e limpa o OTP.
 * - Link sempre a partir de APP_URL (https em produção), nunca do Host da requisição.
 * - log/null NÃO contam como envio (sent/logged/skipped/failed).
 */
final class PatientPasswordResetService
{
    public const TOKEN_VALIDITY_SECONDS = 1800; // 30 min
    public const MAX_REQUESTS_PER_EMAIL_15M = 5;
    public const MAX_REQUESTS_PER_IP_15M = 10;

    private PDO $db;
    private ?Mailer $mailer;
    private ?string $appUrl;
    /** @var (callable(\Closure): void)|null */
    private $deliveryScheduler = null;

    public function __construct(?PDO $db = null, ?Mailer $mailer = null, ?string $appUrl = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->mailer = $mailer;
        $this->appUrl = $appUrl;
    }

    public function deferEmailDelivery(?callable $scheduler): void
    {
        $this->deliveryScheduler = $scheduler;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', strtolower($token));
    }

    private static function nowUtc(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    public const GENERIC_MESSAGE = 'Se esse e-mail tiver uma conta de paciente, enviamos um link para criar uma nova senha. Ele vale por 30 minutos. Confira também o spam.';

    /**
     * Solicita a recuperação. Nunca devolve o token (ele só sai no e-mail).
     * @return array{success:bool,email:string,message:string,delivered:int}
     */
    public function requestReset(string $email, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Por favor, informe um endereço de e-mail válido.');
        }
        if ($this->isRateLimited($email, $ipAddress)) {
            return ['success' => true, 'email' => $email, 'message' => self::GENERIC_MESSAGE, 'delivered' => 0];
        }

        // Pacientes ativos, com conta já ativada (senha ou PIN), para este e-mail.
        $stmt = $this->db->prepare(
            "SELECT p.id, p.clinic_id, p.name, p.email
             FROM patients p JOIN clinics c ON c.id = p.clinic_id AND c.status = 'active'
             WHERE LOWER(p.email) = :email AND p.status = 'active'
               AND (COALESCE(p.password_hash,'') <> '' OR COALESCE(p.access_pin,'') <> '')"
        );
        $stmt->execute(['email' => $email]);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $emailHash = hash('sha256', $email);

        if ($patients === []) {
            $this->audit(null, null, 'PATIENT_PASSWORD_RESET_ATTEMPT_UNKNOWN', null, $ipAddress, $userAgent, ['email_hash' => $emailHash]);
            return ['success' => true, 'email' => $email, 'message' => self::GENERIC_MESSAGE, 'delivered' => 0];
        }

        $delivered = 0;
        foreach ($patients as $p) {
            $patientId = (int)$p['id'];
            $clinicId = (int)$p['clinic_id'];
            $token = bin2hex(random_bytes(32));
            $tokenHash = self::hashToken($token);
            $expiresAt = self::nowUtc(self::TOKEN_VALIDITY_SECONDS);

            $this->db->beginTransaction();
            try {
                // invalida pedidos anteriores não usados deste paciente
                $this->db->prepare('UPDATE patient_password_resets SET used_at = :now WHERE patient_id = :pid AND used_at IS NULL')
                    ->execute(['now' => self::nowUtc(), 'pid' => $patientId]);
                $this->db->prepare('INSERT INTO patient_password_resets (clinic_id, patient_id, token_hash, expires_at, created_at) VALUES (:c, :p, :t, :e, :n)')
                    ->execute(['c' => $clinicId, 'p' => $patientId, 't' => $tokenHash, 'e' => $expiresAt, 'n' => self::nowUtc()]);
                $this->audit($clinicId, $patientId, 'PATIENT_PASSWORD_RESET_REQUESTED', (string)$patientId, $ipAddress, $userAgent, ['email_hash' => $emailHash, 'expires_at_utc' => $expiresAt]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            // Envio sempre depois do commit.
            $send = fn () => $this->sendResetEmail($p, $email, $token, $clinicId, $patientId, $ipAddress, $userAgent);
            if ($this->deliveryScheduler !== null) {
                ($this->deliveryScheduler)($send);
            } else {
                if ($send() === 'sent') {
                    $delivered++;
                }
            }
            unset($token);
        }

        return ['success' => true, 'email' => $email, 'message' => self::GENERIC_MESSAGE, 'delivered' => $delivered];
    }

    /** @return array{patient_id:int,clinic_id:int,name:string,email:string,token_hash:string}|null */
    public function validateToken(string $token): ?array
    {
        $token = trim($token);
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $tokenHash = self::hashToken($token);
        $stmt = $this->db->prepare(
            "SELECT r.token_hash, r.expires_at, r.used_at, p.id AS patient_id, p.clinic_id, p.name, p.email, p.status
             FROM patient_password_resets r
             JOIN patients p ON p.id = r.patient_id AND p.clinic_id = r.clinic_id
             JOIN clinics c ON c.id = p.clinic_id AND c.status = 'active'
             WHERE r.token_hash = :t LIMIT 1"
        );
        $stmt->execute(['t' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row['token_hash'], $tokenHash)) {
            return null;
        }
        if ($row['status'] !== 'active' || $row['used_at'] !== null) {
            return null;
        }
        if (($exp = strtotime((string)$row['expires_at'] . ' UTC')) === false || $exp <= time()) {
            return null;
        }
        return [
            'patient_id' => (int)$row['patient_id'],
            'clinic_id' => (int)$row['clinic_id'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'token_hash' => (string)$row['token_hash'],
        ];
    }

    /**
     * Redefine a senha. Uso único; revoga tokens de app e limpa OTP.
     * @return array{success:bool,patient_id:int,clinic_id:int,name:string,email:string,message:string}
     */
    public function resetPassword(string $token, string $newPassword, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            throw new InvalidArgumentException('Use uma senha de 8 a 72 caracteres.');
        }
        $valid = $this->validateToken($token);
        if (!$valid) {
            throw new InvalidArgumentException('Link de recuperação inválido ou expirado. Solicite um novo.');
        }
        $patientId = $valid['patient_id'];
        $clinicId = $valid['clinic_id'];
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE patients SET password_hash = :h, otp_code = NULL, otp_expires_at = NULL, updated_at = :now WHERE LOWER(email) = LOWER(:email) AND status = 'active'")
                ->execute(['h' => $hash, 'now' => self::nowUtc(), 'email' => $valid['email']]);
            // uso único: marca este token e qualquer outro pendente do paciente
            $this->db->prepare('UPDATE patient_password_resets SET used_at = :now WHERE patient_id = :pid AND used_at IS NULL')
                ->execute(['now' => self::nowUtc(), 'pid' => $patientId]);
            // revoga sessões de app do paciente (bearer) para todos os perfis desse e-mail
            $this->db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_type = 'patient' AND tokenable_id IN (SELECT id FROM patients WHERE LOWER(email) = LOWER(:email))")
                ->execute(['email' => $valid['email']]);
            $this->audit($clinicId, $patientId, 'PATIENT_PASSWORD_RESET_COMPLETED', (string)$patientId, $ipAddress, $userAgent, ['email_hash' => hash('sha256', (string)$valid['email']), 'sessions_revoked' => true, 'method' => 'token_recovery']);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'success' => true,
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
            'name' => $valid['name'],
            'email' => $valid['email'],
            'message' => 'Senha alterada. Já dá para entrar no app com a nova senha.',
        ];
    }

    /** Hash do token ativo (para testes/verificação). O token em claro só existe no e-mail. */
    public function activeTokenHashForPatient(int $patientId): ?string
    {
        $st = $this->db->prepare('SELECT token_hash FROM patient_password_resets WHERE patient_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1');
        $st->execute([$patientId, self::nowUtc()]);
        $h = $st->fetchColumn();
        return $h === false ? null : (string)$h;
    }

    // ------------------------------------------------------------------ interno

    private function sendResetEmail(array $patient, string $email, string $token, int $clinicId, int $patientId, ?string $ip, ?string $ua): string
    {
        try {
            $base = $this->canonicalBase();
            if ($base === null) {
                throw new RuntimeException('APP_URL ausente ou inválida.');
            }
            $delivers = true;
            $emailHash = hash('sha256', $email);
            if ($this->mailer === null) {
                $describe = MailerFactory::describe();
                if (in_array($describe['driver'], ['null', 'none', null], true)) {
                    $this->audit($clinicId, $patientId, 'PATIENT_PASSWORD_RESET_EMAIL_SKIPPED', (string)$patientId, $ip, $ua, ['email_hash' => $emailHash, 'reason' => 'mail_disabled']);
                    return 'skipped';
                }
                $delivers = (bool)$describe['delivers'];
            }
            $resetUrl = $base . '/paciente/recuperar-senha/' . rawurlencode($token);
            $message = PatientPasswordResetEmail::build($email, (string)($patient['name'] ?? ''), $resetUrl, intdiv(self::TOKEN_VALIDITY_SECONDS, 60), self::supportReplyTo());
            ($this->mailer ?? MailerFactory::fromEnv())->send($message);

            $action = $delivers ? 'PATIENT_PASSWORD_RESET_EMAIL_SENT' : 'PATIENT_PASSWORD_RESET_EMAIL_LOGGED';
            $this->audit($clinicId, $patientId, $action, (string)$patientId, $ip, $ua, ['email_hash' => $emailHash]);
            return $delivers ? 'sent' : 'logged';
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][patient-pwreset] falha ao enviar (patient_id=' . $patientId . '): ' . $e->getMessage());
            try {
                $this->audit($clinicId, $patientId, 'PATIENT_PASSWORD_RESET_EMAIL_FAILED', (string)$patientId, $ip, $ua, ['email_hash' => hash('sha256', $email), 'error' => mb_substr($e->getMessage(), 0, 200)]);
            } catch (\Throwable) {
            }
            return 'failed';
        }
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

    private function isRateLimited(string $email, ?string $ipAddress): bool
    {
        $cutoff = self::nowUtc(-900);
        $emailHash = hash('sha256', $email);
        $st = $this->db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action IN ('PATIENT_PASSWORD_RESET_REQUESTED','PATIENT_PASSWORD_RESET_ATTEMPT_UNKNOWN') AND details LIKE :p AND created_at >= :c");
        $st->execute(['p' => '%' . $emailHash . '%', 'c' => $cutoff]);
        if ((int)$st->fetchColumn() >= self::MAX_REQUESTS_PER_EMAIL_15M) {
            return true;
        }
        if ($ipAddress !== null && $ipAddress !== '') {
            $st = $this->db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action IN ('PATIENT_PASSWORD_RESET_REQUESTED','PATIENT_PASSWORD_RESET_ATTEMPT_UNKNOWN') AND ip_address = :ip AND created_at >= :c");
            $st->execute(['ip' => $ipAddress, 'c' => $cutoff]);
            if ((int)$st->fetchColumn() >= self::MAX_REQUESTS_PER_IP_15M) {
                return true;
            }
        }
        return false;
    }

    private static function supportReplyTo(): ?string
    {
        $support = trim((string)(Env::get('APP_SUPPORT_EMAIL') ?? ''));
        return filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : null;
    }

    private function audit(?int $clinicId, ?int $patientId, string $action, ?string $resourceId, ?string $ip, ?string $ua, array $details): void
    {
        try {
            $st = $this->db->prepare(
                "INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                 VALUES (:clinic_id, NULL, :action, 'patient_password_resets', :res_id, :ip, :ua, :details, :created_at)"
            );
            $st->execute([
                'clinic_id' => $clinicId,
                'action' => $action,
                'res_id' => $resourceId,
                'ip' => $ip,
                'ua' => $ua !== null ? substr($ua, 0, 255) : null,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'created_at' => self::nowUtc(),
            ]);
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][patient-pwreset] auditoria indisponível: ' . $e->getMessage());
        }
    }
}
