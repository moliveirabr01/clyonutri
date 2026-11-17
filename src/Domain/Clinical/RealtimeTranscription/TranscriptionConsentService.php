<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical\RealtimeTranscription;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Core\Security\TenantGuard;
use DomainException;
use PDO;

/**
 * Consentimento verificável do paciente para gravação e transcrição da consulta (LGPD / NI2-820 + NI2-830 SEC-001).
 *
 * Regra central: quem decide é o PACIENTE. O profissional só PEDE o aceite (createRequest) e recebe um link/QR de uso
 * único; o aceite nasce de uma ação do paciente fora da sessão do profissional:
 *   - 'patient_link'   : link com token curto (100 bits), guardado só como hash, validade de 15 min, uso único;
 *   - 'patient_portal' : paciente autenticado no portal com a própria senha.
 * Nenhum método deste serviço grava "granted" a partir de uma chamada do profissional. Quem consulta o aceite
 * (isConsentActive) confere clínica + atendimento + paciente + validade + revogação no servidor, a cada uso.
 * A tabela consultation_ai_consents (NI2-820) deixou de ser fonte de autorização.
 */
class TranscriptionConsentService
{
    public const MECH_LINK = 'patient_link';
    public const MECH_PORTAL = 'patient_portal';
    public const MECH_OTP = 'otp';
    private const OTP_MAX_ATTEMPTS = 5;

    private PDO $db;
    private AuditLogger $auditLogger;

    public function __construct(?PDO $db = null, ?AuditLogger $auditLogger = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    // ------------------------------------------------------------------------------------------------
    // Consulta (lado do profissional, com tenant)
    // ------------------------------------------------------------------------------------------------

    /** Última solicitação do atendimento, com o estado já resolvido (pending/accepted/rejected/expired/revoked/cancelled). */
    public function getConsent(int $appointmentId, int $patientId): ?array
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $row = $this->latestRequest($clinicId, $appointmentId, $patientId);
        return $row === null ? null : $this->present($row);
    }

    /**
     * Aceite válido AGORA: concedido pelo paciente, não revogado, dentro da validade, para exatamente este atendimento,
     * esta clínica e este paciente (o atendimento precisa pertencer ao paciente informado).
     */
    public function isConsentActive(int $appointmentId, int $patientId): bool
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        return $this->activeRequest($clinicId, $appointmentId, $patientId) !== null;
    }

    /**
     * Pede o aceite ao paciente. NÃO concede nada: cria um pedido pendente e devolve o token em claro uma única vez.
     * Cancela pedidos pendentes anteriores do mesmo atendimento. Se já existe aceite válido, não cria outro.
     */
    public function createRequest(int $appointmentId, int $patientId, int $userId, string $origin = 'professional_session', string $requestIp = '0.0.0.0'): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $app = $this->appointmentRow($clinicId, $appointmentId);
        if ($app === null || (int)$app['patient_id'] !== $patientId) {
            throw new DomainException('Atendimento não corresponde ao paciente informado.');
        }
        if (in_array((string)$app['status'], ['cancelled', 'no_show'], true)) {
            throw new DomainException('Este atendimento foi cancelado. Não é possível pedir o aceite.');
        }
        if ($this->activeRequest($clinicId, $appointmentId, $patientId) !== null) {
            return ['created' => false, 'consent' => $this->present($this->latestRequest($clinicId, $appointmentId, $patientId))];
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare("UPDATE consultation_ai_consent_requests SET status = 'cancelled'
            WHERE clinic_id = :c AND appointment_id = :a AND patient_id = :p AND status = 'pending'")
            ->execute(['c' => $clinicId, 'a' => $appointmentId, 'p' => $patientId]);

        $token = self::newToken();
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expires = gmdate('Y-m-d H:i:s', time() + TranscriptionConsentTerms::REQUEST_TTL_SECONDS);
        $reqIpHash = self::hashIp($requestIp);
        $this->db->prepare("INSERT INTO consultation_ai_consent_requests
            (clinic_id, appointment_id, patient_id, requested_by_user_id, token_hash, otp_hash, otp_expires_at_utc, otp_attempts, requested_ip_hash, same_ip_alert, terms_version, terms_sha256, status, request_origin, expires_at_utc, created_at)
            VALUES (:c, :a, :p, :u, :th, :oh, :oe, 0, :rih, 0, :tv, :ts, 'pending', :o, :e, :n)")
            ->execute([
                'c' => $clinicId, 'a' => $appointmentId, 'p' => $patientId, 'u' => $userId,
                'th' => self::hashToken($token), 'oh' => $otpHash, 'oe' => $expires, 'rih' => $reqIpHash,
                'tv' => TranscriptionConsentTerms::VERSION, 'ts' => TranscriptionConsentTerms::sha256(),
                'o' => substr($origin, 0, 40), 'e' => $expires, 'n' => $now,
            ]);
        $id = (int)$this->db->lastInsertId();

        $deliveryChannel = $this->dispatchConsentOtp($clinicId, $patientId, $token, $otp);

        $this->auditLogger->log('AI_TRANSCRIPTION_CONSENT_REQUESTED', 'appointment', (string)$appointmentId, [
            'request_id' => $id, 'patient_id' => $patientId, 'user_id' => $userId,
            'version' => TranscriptionConsentTerms::VERSION, 'origin' => $origin, 'expires_at_utc' => $expires,
            'delivery_channel' => $deliveryChannel,
        ]);

        return [
            'created' => true,
            'token' => $token,
            'otp' => $otp,
            'delivery_channel' => $deliveryChannel,
            'consent' => $this->present($this->requestById($id)),
        ];
    }

    /** Revogação pelo profissional (a pedido do paciente): registra quem revogou e para a captação. */
    public function revokeConsent(int $appointmentId, int $patientId, int $userId): bool
    {
        $clinicId = (int)TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $now = gmdate('Y-m-d H:i:s');
        // Pedido ainda pendente (link não usado): só cancela, não é revogação de aceite.
        $this->db->prepare("UPDATE consultation_ai_consent_requests SET status = 'cancelled'
            WHERE clinic_id = :c AND appointment_id = :a AND patient_id = :p AND status = 'pending'")
            ->execute(['c' => $clinicId, 'a' => $appointmentId, 'p' => $patientId]);
        $st = $this->db->prepare("UPDATE consultation_ai_consent_requests SET status = 'revoked', revoked_at_utc = :n, revoked_by = 'professional'
            WHERE clinic_id = :c AND appointment_id = :a AND patient_id = :p AND status = 'accepted' AND revoked_at_utc IS NULL");
        $st->execute(['n' => $now, 'c' => $clinicId, 'a' => $appointmentId, 'p' => $patientId]);
        $revoked = $st->rowCount() > 0;
        if ($revoked) {
            $this->stopCapture($clinicId, $appointmentId);
            $this->auditLogger->log('AI_TRANSCRIPTION_CONSENT_REVOKED', 'appointment', (string)$appointmentId, [
                'patient_id' => $patientId, 'user_id' => $userId, 'revoked_by' => 'professional',
            ]);
        }
        return $revoked;
    }

    // ------------------------------------------------------------------------------------------------
    // Lado do paciente (sem sessão do profissional; clínica vem do próprio pedido)
    // ------------------------------------------------------------------------------------------------

    /** Dados mínimos para a página do paciente. Null se o token não existe (a página não diferencia para quem adivinha). */
    public function previewByToken(string $token): ?array
    {
        $row = $this->requestByToken($token);
        return $row === null ? null : $this->patientView($row);
    }

    /**
     * Decisão do paciente pelo link com validação obrigatória de OTP (SEC-001 / NI2-858).
     * Uso único: só um UPDATE condicionado a status='pending' e validade vence a corrida.
     * @return array{state:string,revoke_token:?string,consent:array}
     */
    public function decideByToken(string $token, string $decision, string $postedTermsSha, string $ip, string $userAgent, ?string $otp = null): array
    {
        $row = $this->requestByToken($token);
        if ($row === null) {
            throw new DomainException('Link inválido ou expirado.');
        }
        if ($decision === 'accept') {
            $attempts = (int)($row['otp_attempts'] ?? 0);
            if ($attempts >= self::OTP_MAX_ATTEMPTS) {
                throw new DomainException('Muitas tentativas inválidas de código OTP. Peça um novo pedido de aceite ao profissional.');
            }
            $now = gmdate('Y-m-d H:i:s');
            if (empty($row['otp_expires_at_utc']) || (string)$row['otp_expires_at_utc'] <= $now) {
                throw new DomainException('Código OTP expirado. Peça um novo pedido de aceite ao profissional.');
            }
            $otpClean = trim((string)$otp);
            if (!preg_match('/^\d{6}$/', $otpClean) || empty($row['otp_hash']) || !password_verify($otpClean, (string)$row['otp_hash'])) {
                $this->db->prepare('UPDATE consultation_ai_consent_requests SET otp_attempts = otp_attempts + 1 WHERE id = :id')
                    ->execute(['id' => (int)$row['id']]);
                throw new DomainException('Código de verificação (OTP) obrigatório ou inválido.');
            }
        }
        return $this->decide($row, $decision, $postedTermsSha, self::MECH_OTP, $ip, $userAgent);
    }

    /** Decisão do paciente autenticado no portal. O pedido precisa ser do mesmo paciente e da mesma clínica. */
    public function decideByPortal(int $requestId, int $patientId, int $clinicId, string $decision, string $postedTermsSha, string $ip, string $userAgent): array
    {
        $row = $this->requestById($requestId);
        if ($row === null || (int)$row['patient_id'] !== $patientId || (int)$row['clinic_id'] !== $clinicId) {
            throw new DomainException('Pedido não encontrado para este paciente.');
        }
        return $this->decide($row, $decision, $postedTermsSha, self::MECH_PORTAL, $ip, $userAgent);
    }

    /** Pedidos pendentes e aceites vigentes do paciente logado no portal (só dele). */
    public function listForPatient(int $patientId, int $clinicId): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $st = $this->db->prepare("SELECT * FROM consultation_ai_consent_requests
            WHERE clinic_id = :c AND patient_id = :p AND (
                (status = 'pending' AND expires_at_utc > :n1) OR (status = 'accepted' AND revoked_at_utc IS NULL AND valid_until_utc > :n2))
            ORDER BY id DESC LIMIT 10");
        $st->execute(['c' => $clinicId, 'p' => $patientId, 'n1' => $now, 'n2' => $now]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->patientView($row);
        }
        return $out;
    }

    /** Revogação pelo paciente com o token de revogação (mostrado só na página de confirmação do aceite). */
    public function revokeByPatientToken(string $revokeToken, string $ip, string $userAgent): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $revokeToken)) {
            throw new DomainException('Código de revogação inválido.');
        }
        $st = $this->db->prepare('SELECT * FROM consultation_ai_consent_requests WHERE revoke_token_hash = :h LIMIT 1');
        $st->execute(['h' => self::hashToken($revokeToken)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Código de revogação inválido.');
        }
        return $this->revokeByPatient($row, self::MECH_LINK, $ip, $userAgent);
    }

    public function revokeByPatientPortal(int $requestId, int $patientId, int $clinicId, string $ip, string $userAgent): array
    {
        $row = $this->requestById($requestId);
        if ($row === null || (int)$row['patient_id'] !== $patientId || (int)$row['clinic_id'] !== $clinicId) {
            throw new DomainException('Pedido não encontrado para este paciente.');
        }
        return $this->revokeByPatient($row, self::MECH_PORTAL, $ip, $userAgent);
    }

    // ------------------------------------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------------------------------------

    private function decide(array $row, string $decision, string $postedTermsSha, string $mechanism, string $ip, string $userAgent): array
    {
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw new DomainException('Escolha aceitar ou recusar.');
        }
        // O texto que o paciente viu precisa ser o do pedido e o vigente: aceite de texto desatualizado não vale.
        if (!hash_equals((string)$row['terms_sha256'], $postedTermsSha) || !hash_equals(TranscriptionConsentTerms::sha256(), $postedTermsSha)) {
            throw new DomainException('O texto do aceite mudou enquanto esta página estava aberta. Peça um novo link ao profissional.');
        }
        $app = $this->appointmentRow((int)$row['clinic_id'], (int)$row['appointment_id']);
        if ($app === null || (int)$app['patient_id'] !== (int)$row['patient_id'] || in_array((string)$app['status'], ['cancelled', 'no_show'], true)) {
            throw new DomainException('Este pedido não corresponde mais ao atendimento. Peça um novo ao profissional.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $accept = $decision === 'accept';
        $revokeToken = $accept ? bin2hex(random_bytes(16)) : null;
        $decisionIpHash = self::hashIp($ip);
        $reqIpHash = (string)($row['requested_ip_hash'] ?? '');
        $sameIpAlert = ($reqIpHash !== '' && hash_equals($reqIpHash, $decisionIpHash)) ? 1 : 0;
        $st = $this->db->prepare("UPDATE consultation_ai_consent_requests
            SET status = :s, decided_at_utc = :n, decision_mechanism = :m, decision_ip_hash = :ip, decision_user_agent = :ua,
                same_ip_alert = :sia, valid_until_utc = :vu, revoke_token_hash = :rt
            WHERE id = :id AND status = 'pending' AND expires_at_utc > :n2");
        $st->execute([
            's' => $accept ? 'accepted' : 'rejected', 'n' => $now, 'm' => $mechanism,
            'ip' => $decisionIpHash, 'ua' => self::cleanUa($userAgent), 'sia' => $sameIpAlert,
            'vu' => $accept ? gmdate('Y-m-d H:i:s', time() + TranscriptionConsentTerms::CONSENT_VALIDITY_SECONDS) : null,
            'rt' => $revokeToken !== null ? self::hashToken($revokeToken) : null,
            'id' => (int)$row['id'], 'n2' => $now,
        ]);
        if ($st->rowCount() !== 1) {
            $fresh = $this->requestById((int)$row['id']);
            $state = $fresh === null ? 'invalid' : $this->resolveState($fresh);
            throw new DomainException($state === 'pending'
                ? 'Não foi possível registrar agora. Tente de novo.'
                : 'Este link já foi usado, expirou ou foi cancelado. Peça um novo ao profissional.');
        }

        $this->withAuditContext($row, function () use ($row, $accept, $mechanism, $sameIpAlert): void {
            $this->auditLogger->log($accept ? 'AI_TRANSCRIPTION_CONSENT_ACCEPTED_BY_PATIENT' : 'AI_TRANSCRIPTION_CONSENT_REJECTED_BY_PATIENT', 'appointment', (string)$row['appointment_id'], [
                'request_id' => (int)$row['id'], 'patient_id' => (int)$row['patient_id'],
                'version' => (string)$row['terms_version'], 'mechanism' => $mechanism, 'origin' => 'patient_' . ($mechanism === self::MECH_PORTAL ? 'portal' : 'otp'),
                'same_ip_alert' => $sameIpAlert === 1,
            ]);
            if ($sameIpAlert === 1) {
                $this->auditLogger->log('AI_TRANSCRIPTION_CONSENT_SAME_IP_ALERT', 'appointment', (string)$row['appointment_id'], [
                    'request_id' => (int)$row['id'], 'patient_id' => (int)$row['patient_id'], 'mechanism' => $mechanism,
                ]);
            }
        });

        return ['state' => $accept ? 'accepted' : 'rejected', 'revoke_token' => $revokeToken, 'consent' => $this->present($this->requestById((int)$row['id']))];
    }

    private function revokeByPatient(array $row, string $mechanism, string $ip, string $userAgent): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $st = $this->db->prepare("UPDATE consultation_ai_consent_requests SET status = 'revoked', revoked_at_utc = :n, revoked_by = 'patient'
            WHERE id = :id AND status = 'accepted' AND revoked_at_utc IS NULL");
        $st->execute(['n' => $now, 'id' => (int)$row['id']]);
        if ($st->rowCount() !== 1) {
            throw new DomainException('Este aceite já foi revogado ou não está mais vigente.');
        }
        $this->stopCapture((int)$row['clinic_id'], (int)$row['appointment_id']);
        $this->withAuditContext($row, function () use ($row, $mechanism): void {
            $this->auditLogger->log('AI_TRANSCRIPTION_CONSENT_REVOKED', 'appointment', (string)$row['appointment_id'], [
                'request_id' => (int)$row['id'], 'patient_id' => (int)$row['patient_id'], 'revoked_by' => 'patient', 'mechanism' => $mechanism,
            ]);
        });
        return ['state' => 'revoked', 'consent' => $this->present($this->requestById((int)$row['id']))];
    }

    /** Revogação interrompe a captação do atendimento: sessões abertas vão para "paused" e não aceitam segmentos. */
    private function stopCapture(int $clinicId, int $appointmentId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare("UPDATE consultation_transcription_sessions SET status = 'paused', paused_at_utc = :n, updated_at = :n2
            WHERE clinic_id = :c AND appointment_id = :a AND status IN ('capturing', 'connected')")
            ->execute(['n' => $now, 'n2' => $now, 'c' => $clinicId, 'a' => $appointmentId]);
    }

    private function activeRequest(int $clinicId, int $appointmentId, int $patientId): ?array
    {
        $st = $this->db->prepare("SELECT r.* FROM consultation_ai_consent_requests r
            JOIN appointments a ON a.id = r.appointment_id AND a.clinic_id = r.clinic_id AND a.patient_id = r.patient_id
            WHERE r.clinic_id = :c AND r.appointment_id = :a AND r.patient_id = :p
              AND r.status = 'accepted' AND r.revoked_at_utc IS NULL AND r.valid_until_utc > :n
              AND r.decision_mechanism IN ('otp', 'patient_portal')
            ORDER BY r.id DESC LIMIT 1");
        $st->execute(['c' => $clinicId, 'a' => $appointmentId, 'p' => $patientId, 'n' => gmdate('Y-m-d H:i:s')]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function latestRequest(int $clinicId, int $appointmentId, int $patientId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM consultation_ai_consent_requests
            WHERE clinic_id = :c AND appointment_id = :a AND patient_id = :p ORDER BY id DESC LIMIT 1');
        $st->execute(['c' => $clinicId, 'a' => $appointmentId, 'p' => $patientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function requestById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM consultation_ai_consent_requests WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function requestByToken(string $token): ?array
    {
        $token = strtoupper(trim($token));
        if (!preg_match('/^[A-Z2-7]{20}$/', $token)) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM consultation_ai_consent_requests WHERE token_hash = :h LIMIT 1');
        $st->execute(['h' => self::hashToken($token)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function appointmentRow(int $clinicId, int $appointmentId): ?array
    {
        $st = $this->db->prepare('SELECT id, patient_id, status, scheduled_at FROM appointments WHERE id = :a AND clinic_id = :c');
        $st->execute(['a' => $appointmentId, 'c' => $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function resolveState(array $row): string
    {
        $now = gmdate('Y-m-d H:i:s');
        $status = (string)$row['status'];
        if ($status === 'pending' && (string)$row['expires_at_utc'] <= $now) {
            return 'expired';
        }
        if ($status === 'accepted' && (string)($row['valid_until_utc'] ?? '') <= $now) {
            return 'expired';
        }
        return $status;
    }

    /** Visão segura para o profissional: nunca devolve hashes nem dados do dispositivo do paciente. */
    private function present(?array $row): array
    {
        if ($row === null) {
            return ['state' => 'none'];
        }
        return [
            'id' => (int)$row['id'],
            'state' => $this->resolveState($row),
            'terms_version' => (string)$row['terms_version'],
            'expires_at_utc' => (string)$row['expires_at_utc'],
            'accepted_at_utc' => $row['status'] === 'accepted' || $row['status'] === 'revoked' ? ($row['decided_at_utc'] ?? null) : null,
            'valid_until_utc' => $row['valid_until_utc'] ?? null,
            'mechanism' => $row['decision_mechanism'] ?? null,
            'same_ip_alert' => !empty($row['same_ip_alert']),
            'revoked_at_utc' => $row['revoked_at_utc'] ?? null,
            'revoked_by' => $row['revoked_by'] ?? null,
        ];
    }

    /** Dados mínimos mostrados ao paciente: clínica, profissional, primeiro nome, data da consulta. */
    private function patientView(array $row): array
    {
        $st = $this->db->prepare('SELECT a.scheduled_at, p.name AS patient_name, u.name AS professional_name, c.name AS clinic_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN professionals pr ON pr.id = a.professional_id
            JOIN users u ON u.id = pr.user_id
            JOIN clinics c ON c.id = a.clinic_id
            WHERE a.id = :a AND a.clinic_id = :c AND a.patient_id = :p');
        $st->execute(['a' => (int)$row['appointment_id'], 'c' => (int)$row['clinic_id'], 'p' => (int)$row['patient_id']]);
        $info = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $first = trim(explode(' ', trim((string)($info['patient_name'] ?? '')))[0] ?? '');
        return [
            'id' => (int)$row['id'],
            'state' => $this->resolveState($row),
            'terms_version' => (string)$row['terms_version'],
            'terms_sha256' => (string)$row['terms_sha256'],
            'expires_at_utc' => (string)$row['expires_at_utc'],
            'valid_until_utc' => $row['valid_until_utc'] ?? null,
            'patient_first_name' => $first,
            'professional_name' => (string)($info['professional_name'] ?? ''),
            'clinic_name' => (string)($info['clinic_name'] ?? ''),
            'scheduled_at' => (string)($info['scheduled_at'] ?? ''),
        ];
    }

    /** O AuditLogger lê clínica e usuário do TenantContext; no fluxo público o contexto vem do próprio pedido. */
    private function withAuditContext(array $row, callable $fn): void
    {
        $had = TenantContext::hasClinic();
        $prevClinic = $had ? TenantContext::getClinicId() : null;
        $prevUser = TenantContext::getUser();
        TenantContext::set((int)$row['clinic_id'], ['id' => (int)$row['patient_id'], 'role' => 'patient']);
        try {
            $fn();
        } finally {
            if ($had && $prevClinic !== null) {
                TenantContext::set($prevClinic, $prevUser ?? []);
            } else {
                TenantContext::clear();
            }
        }
    }

    private static function newToken(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes(13); // 104 bits; 20 caracteres base32 = 100 bits
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[bindec(substr($bits, $i * 5, 5))];
        }
        return $out;
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', 'ai-consent|' . $token);
    }

    private static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string)(Env::get('APP_KEY') ?: 'clyonutri-local'));
    }

    private function dispatchConsentOtp(int $clinicId, int $patientId, string $token, string $otp): string
    {
        $st = $this->db->prepare('SELECT name, email FROM patients WHERE id = :id AND clinic_id = :c LIMIT 1');
        $st->execute(['id' => $patientId, 'c' => $clinicId]);
        $patient = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $email = trim((string)($patient['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'portal';
        }
        try {
            $base = rtrim((string)(Env::get('APP_URL') ?: 'https://clyonutri.com.br'), '/');
            $link = $base . '/consentimento-transcricao/' . $token;
            $name = trim((string)($patient['name'] ?? ''));
            $first = $name !== '' ? (preg_split('/\s+/u', $name)[0] ?? '') : '';
            $hello = $first !== '' ? 'Olá, ' . $first . '.' : 'Olá.';
            $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $subject = 'Código para autorizar a transcrição da consulta · Clyo Nutri';
            $text = "{$hello}\n\n"
                . "Seu nutricionista pediu autorização para transcrever a consulta.\n"
                . "Para aceitar, abra o link abaixo e informe o código {$otp} (válido por 15 minutos):\n{$link}\n\n"
                . "Você também pode responder pelo portal do paciente. Se não reconhece este pedido, ignore este e-mail.\n\n"
                . "Equipe Clyo Nutri\n";
            $html = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>' . $e($subject) . '</title></head>'
                . '<body style="margin:0;padding:24px 12px;background:#FAF9F4;font-family:Arial,Helvetica,sans-serif;color:#0F3E17;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border-radius:12px;">'
                . '<tr><td style="background:#0F3E17;padding:24px 32px;color:#FFFFFF;font-size:20px;font-weight:bold;">Clyo Nutri</td></tr>'
                . '<tr><td style="padding:32px;font-size:16px;line-height:1.5;">'
                . '<p style="margin:0 0 16px;">' . $e($hello) . '</p>'
                . '<p style="margin:0 0 16px;">Seu nutricionista pediu autorização para transcrever a consulta. Para aceitar, abra o termo e informe o código abaixo.</p>'
                . '<p style="margin:0 0 16px;font-size:24px;font-weight:bold;letter-spacing:4px;">' . $e($otp) . '</p>'
                . '<p style="margin:0 0 24px;font-size:14px;">O código vale por <strong>15 minutos</strong>.</p>'
                . '<p style="margin:0 0 24px;"><a href="' . $e($link) . '" style="display:inline-block;padding:14px 28px;background:#39734A;color:#FFFFFF;font-weight:bold;text-decoration:none;border-radius:8px;">Abrir termo de aceite</a></p>'
                . '<p style="margin:0;font-size:14px;color:#5A6B85;">Você também pode responder pelo portal do paciente. Se não reconhece este pedido, ignore este e-mail.</p>'
                . '</td></tr></table></body></html>';
            \ClyoNutri\Core\Mail\MailerFactory::fromEnv()->send(
                new \ClyoNutri\Core\Mail\MailMessage($email, $name, $subject, $html, $text)
            );
            return \ClyoNutri\Core\Mail\MailerFactory::describe()['delivers'] ? 'email' : 'portal';
        } catch (\Throwable $e) {
            error_log('[transcription-consent] envio de OTP falhou: ' . get_class($e));
            return 'portal';
        }
    }

    private static function cleanUa(string $ua): string
    {
        return substr(preg_replace('/[\x00-\x1f\x7f]/', '', $ua) ?? '', 0, 120);
    }
}
