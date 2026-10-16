<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationStateException;
use ClyoNutri\Domain\Telehealth\Exceptions\TeleconsultationTicketUsedException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Consultório Online — serviço de domínio (NI2-235/236/237/243/244/247/249/431).
 *
 * Contratos:
 * - Uma sessão por agendamento; criação idempotente.
 * - Estados e transições explícitos (TRANSITIONS); toda transição grava evento
 *   de auditoria na mesma transação — se a auditoria falhar, a ação falha.
 * - Instantes novos em UTC (colunas *_utc). A janela de entrada deriva do horário
 *   da consulta, interpretado no fuso da aplicação (APP_TIMEZONE).
 * - Convite (token do paciente) guardado só como hash, com janela e geração;
 *   cancelamento, reagendamento, recusa e encerramento revogam a geração atual.
 * - Credenciais de mídia são emitidas sob demanda e nunca persistidas;
 *   o paciente só recebe credencial depois de admitido.
 * - Encerrar a mídia NÃO conclui a consulta nem publica nada: a conclusão
 *   clínica segue pelo fluxo da agenda (/consulta/{id}).
 * - Somente o nutricionista responsável pelo agendamento opera a sessão;
 *   outros membros do tenant (inclusive secretaria) não acessam sala nem notas.
 */
class TeleconsultationService
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DEVICE_CHECKED = 'device_checked';
    public const STATUS_WAITING_ROOM = 'waiting_room';
    public const STATUS_ADMITTED = 'admitted';
    public const STATUS_ENDED = 'ended';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const REJECT_REASONS = ['identity_not_confirmed', 'wrong_patient', 'patient_request', 'other'];

    /** 'completed' e 'in_progress' são valores legados (antes de 15/10/2026). */
    /** v11 (NI2-221 F19): tipos de agendamento que são consulta online. */
    public const ONLINE_TYPES = ['teleconsultation', 'telehealth', 'teleconsulta', 'online', 'video'];

    public static function isOnlineType(?string $type): bool
    {
        return in_array(strtolower(trim((string)$type)), self::ONLINE_TYPES, true);
    }

    public const TERMINAL_STATUSES = ['ended', 'rejected', 'cancelled', 'expired', 'completed'];

    private const TRANSITIONS = [
        'scheduled' => ['device_checked', 'waiting_room', 'cancelled', 'expired', 'scheduled'],
        'device_checked' => ['waiting_room', 'cancelled', 'expired', 'scheduled'],
        'waiting_room' => ['admitted', 'rejected', 'cancelled', 'expired', 'scheduled'],
        'admitted' => ['ended', 'cancelled'],
        'in_progress' => ['ended', 'cancelled'],
        'rejected' => ['scheduled'],
        'expired' => ['scheduled'],
        'ended' => [],
        'cancelled' => [],
        'completed' => [],
    ];

    private PDO $db;
    private VideoConferenceProviderInterface $videoProvider;
    private ClinicalRecordService $clinicalRecordService;
    private ?DateTimeImmutable $fixedNow;

    public function __construct(
        ?PDO $db = null,
        ?VideoConferenceProviderInterface $videoProvider = null,
        ?ClinicalRecordService $clinicalRecordService = null,
        ?DateTimeImmutable $now = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->videoProvider = $videoProvider ?? TeleconsultationProviderFactory::make($this->db);
        $this->clinicalRecordService = $clinicalRecordService ?? new ClinicalRecordService($this->db);
        $this->fixedNow = $now;
    }

    /**
     * Converte o usuário autenticado (users.id) no profissional (professionals.id)
     * habilitado no tenant. Nunca use user_id como professional_id.
     */
    public function resolveProfessionalIdForUser(int $userId, ?int $clinicId = null): int
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        $stmt = $this->db->prepare('SELECT id FROM professionals WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        $professionalId = (int)($stmt->fetchColumn() ?: 0);
        if ($professionalId === 0) {
            throw new TenantSecurityException('Usuário sem perfil de nutricionista não pode operar o Consultório Online.');
        }
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        return $professionalId;
    }

    /**
     * Cria (ou devolve) a sessão do agendamento. Idempotente.
     * O convite em claro só é retornado quando a sessão é criada;
     * depois disso use issueInvite().
     */
    public function scheduleSession(int $appointmentId, int $professionalId, ?int $clinicId = null): array
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        $appointment = $this->loadAppointmentForProfessional($appointmentId, $professionalId, $clinicId);

        $existing = $this->findSessionIdByAppointment($appointmentId, $clinicId);
        if ($existing !== null) {
            return ['session_id' => $existing, 'created' => false, 'raw_patient_token' => null];
        }

        if (!in_array($appointment['status'], ['scheduled', 'confirmed', 'in_progress'], true)) {
            throw new TeleconsultationStateException("Consulta #{$appointmentId} está '{$appointment['status']}' e não pode receber teleconsulta.");
        }
        // v11 (NI2-221 F19): consulta presencial não ganha sala de teleconsulta.
        if (!self::isOnlineType($appointment['type'] ?? null)) {
            throw new TeleconsultationStateException("Consulta #{$appointmentId} é presencial; a teleconsulta só vale para consulta online.");
        }

        [$opensAt, $closesAt] = $this->joinWindow($appointment);
        // v13: consulta já passada (fim + tolerância) não ganha sala nova.
        if ($closesAt < $this->now()) {
            throw new TeleconsultationStateException("Consulta #{$appointmentId} já passou; não é possível abrir a teleconsulta. Reagende a consulta para criar uma nova sala.");
        }
        $rawToken = bin2hex(random_bytes(32));
        $now = $this->nowSql();

        $sessionId = 0;
        try {
            $this->transaction(function () use (&$sessionId, $clinicId, $appointmentId, $appointment, $professionalId, $rawToken, $opensAt, $closesAt, $now): void {
                $this->db->prepare("
                    INSERT INTO teleconsultation_sessions (
                        clinic_id, appointment_id, patient_id, professional_id, provider, provider_status,
                        patient_token_hash, professional_token_hash, status,
                        join_opens_at_utc, join_closes_at_utc, invite_generation, invite_issued_at_utc,
                        created_at, updated_at
                    ) VALUES (
                        :clinic_id, :appointment_id, :patient_id, :professional_id, :provider, 'pending',
                        :token_hash, NULL, 'scheduled',
                        :opens, :closes, 1, :now, :now, :now
                    )
                ")->execute([
                    'clinic_id' => $clinicId,
                    'appointment_id' => $appointmentId,
                    'patient_id' => (int)$appointment['patient_id'],
                    'professional_id' => $professionalId,
                    'provider' => $this->videoProvider->getProviderName(),
                    'token_hash' => hash('sha256', $rawToken),
                    'opens' => $opensAt->format('Y-m-d H:i:s'),
                    'closes' => $closesAt->format('Y-m-d H:i:s'),
                    'now' => $now,
                ]);
                $sessionId = (int)$this->db->lastInsertId();
                $this->recordEvent($sessionId, $clinicId, 'session_created', 'professional', $professionalId, [
                    'appointment_id' => $appointmentId,
                    'invite_generation' => 1,
                ]);
            });
        } catch (\PDOException $e) {
            // Corrida: outra requisição criou a sessão do mesmo agendamento.
            $raced = $this->findSessionIdByAppointment($appointmentId, $clinicId);
            if ($raced !== null) {
                return ['session_id' => $raced, 'created' => false, 'raw_patient_token' => null];
            }
            throw $e;
        }

        return [
            'session_id' => $sessionId,
            'created' => true,
            'raw_patient_token' => $rawToken,
            'patient_invite_path' => '/teleconsulta/sala-espera/' . $rawToken,
            'join_opens_at_utc' => $opensAt->format('Y-m-d H:i:s'),
            'join_closes_at_utc' => $closesAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Emite novo convite, revogando o anterior (nova geração). Volta a sessão para
     * 'scheduled' e exige novo teste de dispositivo. Não vale para sessões
     * encerradas ou canceladas.
     */
    public function issueInvite(int $sessionId, int $professionalId, ?int $clinicId = null): array
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        $session = $this->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        $appointment = $this->loadAppointmentForProfessional((int)$session['appointment_id'], $professionalId, $clinicId);
        if (!in_array($appointment['status'], ['scheduled', 'confirmed', 'in_progress'], true)) {
            throw new TeleconsultationStateException('A consulta não está ativa; não é possível emitir convite.');
        }
        $this->assertTransition($session['status'], self::STATUS_SCHEDULED);

        [$opensAt, $closesAt] = $this->joinWindow($appointment);
        $rawToken = bin2hex(random_bytes(32));
        $generation = (int)$session['invite_generation'] + 1;
        $now = $this->nowSql();

        $this->transaction(function () use ($sessionId, $clinicId, $session, $rawToken, $generation, $now, $opensAt, $closesAt, $professionalId): void {
            $this->guardedUpdate($sessionId, $clinicId, $session['status'], [
                'patient_token_hash' => hash('sha256', $rawToken),
                'invite_generation' => $generation,
                'invite_issued_at_utc' => $now,
                'invite_revoked_at_utc' => null,
                'join_opens_at_utc' => $opensAt->format('Y-m-d H:i:s'),
                'join_closes_at_utc' => $closesAt->format('Y-m-d H:i:s'),
                'device_check_passed' => 0,
                'status' => self::STATUS_SCHEDULED,
            ]);
            $this->recordEvent($sessionId, $clinicId, 'invite_reissued', 'professional', $professionalId, [
                'invite_generation' => $generation,
                'previous_status' => $session['status'],
            ]);
        });

        return [
            'session_id' => $sessionId,
            'raw_patient_token' => $rawToken,
            'patient_invite_path' => '/teleconsulta/sala-espera/' . $rawToken,
            'invite_generation' => $generation,
            'join_opens_at_utc' => $opensAt->format('Y-m-d H:i:s'),
            'join_closes_at_utc' => $closesAt->format('Y-m-d H:i:s'),
        ];
    }

    /** Sessão vista pelo profissional responsável (aplica expiração). */
    public function getSessionForProfessional(int $sessionId, int $professionalId, ?int $clinicId = null): array
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $session = $this->getSessionById($sessionId, $clinicId);
        if ((int)$session['professional_id'] !== $professionalId) {
            throw new TenantSecurityException('Apenas o nutricionista responsável pelo agendamento acessa esta teleconsulta.');
        }
        return $this->applyExpiry($session);
    }

    /** Leitura escopada ao tenant, sem checagem de responsável (uso interno/auditoria). */
    public function getSessionById(int $sessionId, ?int $clinicId = null): array
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        $stmt = $this->db->prepare(self::SESSION_SELECT . ' WHERE s.id = :id AND s.clinic_id = :clinic_id');
        $stmt->execute(['id' => $sessionId, 'clinic_id' => $clinicId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new TenantSecurityException("Sessão de teleconsulta #{$sessionId} não encontrada no tenant.");
        }
        return $session;
    }

    /** Estado resumido para o painel do profissional (polling). */
    public function professionalState(int $sessionId, int $professionalId, ?int $clinicId = null): array
    {
        $session = $this->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        return [
            'session_id' => (int)$session['id'],
            'status' => $session['status'],
            'patient_waiting' => $session['status'] === self::STATUS_WAITING_ROOM,
            'media_ready' => $this->roomReady($session),
            'provider_status' => $session['provider_status'],
        ];
    }

    /**
     * Acesso do paciente autenticado (app/portal): bilhete assinado, curto e sem
     * rotacionar o convite do nutricionista. Só existe se a sessão já foi aberta
     * pelo nutricionista e não está encerrada. O bilhete é revalidado a cada uso.
     */
    /**
     * Bilhete assinado para o paciente entrar na sala de espera.
     * v13: por padrão o bilhete é de uso único ("q.": leva um nonce e é consumido na primeira troca por cookie);
     * o app/portal pede um novo a cada leitura de meeting_url. O link do e-mail ($singleUse = false) segue
     * reutilizável dentro da janela ("p."), pois o paciente pode abri-lo mais de uma vez.
     */
    public function issuePortalTicket(int $patientId, int $appointmentId, int $clinicId, int $maxTtlSeconds = 7200, bool $singleUse = true): ?string
    {
        $session = $this->findSessionRowByAppointment($appointmentId, $clinicId);
        if ($session === null || (int)$session['patient_id'] !== $patientId) {
            return null;
        }
        $session = $this->applyExpiry($session);
        if (in_array($session['status'], self::TERMINAL_STATUSES, true)) {
            return null;
        }
        $key = self::ticketKey();
        if ($key === null) {
            return null;
        }
        $exp = min(
            $this->utc((string)$session['join_closes_at_utc'])->getTimestamp(),
            $this->now()->getTimestamp() + max(60, $maxTtlSeconds)
        );
        // Geração do convite no bilhete: emitir novo convite invalida bilhetes (app e e-mail) antigos.
        $base = (int)$session['id'] . '.' . $patientId . '.' . (int)$session['invite_generation'] . '.' . $exp;
        $body = $singleUse ? 'q.' . $base . '.' . self::b64url(random_bytes(16)) : 'p.' . $base;
        return $body . '.' . self::b64url(hash_hmac('sha256', $body, $key, true));
    }

    public const SINGLE_USE_TICKET_PATTERN = '/^(q\.(\d{1,10})\.(\d{1,10})\.(\d{1,6})\.(\d{1,12})\.[A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})$/D';

    public static function isSingleUseTicket(string $token): bool
    {
        return preg_match(self::SINGLE_USE_TICKET_PATTERN, $token) === 1;
    }

    /**
     * v13: troca o link pelo acesso deste navegador. Bilhete de uso único é consumido aqui (atomicamente);
     * devolve [sessão, valor do cookie]. Para convite de 64 hex e bilhete "p." o cookie é o próprio código.
     * @return array{0: array, 1: string}
     */
    public function redeemPatientLink(string $rawToken): array
    {
        if (!self::isSingleUseTicket($rawToken)) {
            return [$this->getSessionByPatientToken($rawToken), $rawToken];
        }
        $session = $this->verifySingleUseTicket($rawToken);
        $browserKey = self::b64url(random_bytes(32));
        $this->purgeExpiredRedemptions();
        try {
            $this->db->prepare('INSERT INTO teleconsultation_ticket_redemptions
                    (ticket_hash, session_id, clinic_id, browser_key_hash, redeemed_at_utc, expires_at_utc)
                VALUES (:t, :s, :c, :b, :now, :exp)')
                ->execute([
                    't' => hash('sha256', $rawToken), 's' => (int)$session['id'], 'c' => (int)$session['clinic_id'],
                    'b' => hash('sha256', $browserKey), 'now' => $this->nowSql(),
                    'exp' => gmdate('Y-m-d H:i:s', self::ticketExpiry($rawToken)),
                ]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
            throw new TeleconsultationTicketUsedException();
        }
        $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'portal_ticket_redeemed', 'patient', (int)$session['patient_id']);
        return [$session, $rawToken . '~' . $browserKey];
    }

    private function verifySingleUseTicket(string $ticket): array
    {
        if (preg_match(self::SINGLE_USE_TICKET_PATTERN, $ticket, $m) !== 1) {
            throw new TenantSecurityException('Convite de teleconsulta inválido.');
        }
        $key = self::ticketKey();
        if ($key === null || !hash_equals(self::b64url(hash_hmac('sha256', $m[1], $key, true)), $m[6])
            || (int)$m[5] < $this->now()->getTimestamp()) {
            throw new TenantSecurityException('Este acesso à teleconsulta expirou. Abra novamente pelo aplicativo ou peça um novo convite.');
        }
        $stmt = $this->db->prepare(self::SESSION_SELECT . ' WHERE s.id = :id AND s.patient_id = :pid');
        $stmt->execute(['id' => (int)$m[2], 'pid' => (int)$m[3]]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session || (int)$session['invite_generation'] !== (int)$m[4]) {
            throw new TenantSecurityException('Convite de teleconsulta inválido ou substituído.');
        }
        return $this->assertPatientSessionUsable($session);
    }

    private static function ticketExpiry(string $ticket): int
    {
        return preg_match(self::SINGLE_USE_TICKET_PATTERN, $ticket, $m) === 1 ? (int)$m[5] : 0;
    }

    private function purgeExpiredRedemptions(): void
    {
        try {
            $this->db->prepare('DELETE FROM teleconsultation_ticket_redemptions WHERE expires_at_utc < :cut')
                ->execute(['cut' => $this->now()->modify('-1 day')->format('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            // limpeza oportunista; não bloqueia a entrada
        }
    }

    private function assertPatientSessionUsable(array $session): array
    {
        $session = $this->applyExpiry($session);
        if (in_array($session['status'], self::TERMINAL_STATUSES, true)) {
            throw new TeleconsultationStateException('Esta teleconsulta não está mais disponível. Fale com seu nutricionista.');
        }
        return $session;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Valida o convite (hash de 64 hex) ou o bilhete do app; estado não terminal e janela. */
    public function getSessionByPatientToken(string $rawToken): array
    {
        // v13: cookie de bilhete de uso único = "<bilhete>~<chave do navegador>" (vínculo ao navegador que trocou o link).
        if (str_contains($rawToken, '~')) {
            [$ticket, $browserKey] = explode('~', $rawToken, 2);
            $session = $this->verifySingleUseTicket($ticket);
            if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $browserKey) !== 1) {
                throw new TenantSecurityException('Convite de teleconsulta inválido.');
            }
            $stmt = $this->db->prepare('SELECT browser_key_hash FROM teleconsultation_ticket_redemptions WHERE ticket_hash = :t AND session_id = :s');
            $stmt->execute(['t' => hash('sha256', $ticket), 's' => (int)$session['id']]);
            $stored = $stmt->fetchColumn();
            if (!is_string($stored) || !hash_equals($stored, hash('sha256', $browserKey))) {
                throw new TeleconsultationTicketUsedException();
            }
            return $session;
        }
        if (self::isSingleUseTicket($rawToken)) {
            // o código de uso único só vale na troca pelo link (redeemPatientLink), nunca como credencial direta
            throw new TenantSecurityException('Abra a teleconsulta pelo link do aplicativo.');
        }
        if (preg_match('/^p\.(\d{1,10})\.(\d{1,10})\.(\d{1,6})\.(\d{1,12})\.([A-Za-z0-9_-]{43})$/', $rawToken, $m)) {
            $key = self::ticketKey();
            $body = "p.{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}";
            $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, (string)$key, true)), '+/', '-_'), '=');
            if ($key === null || !hash_equals($expected, $m[5]) || (int)$m[4] < $this->now()->getTimestamp()) {
                throw new TenantSecurityException('Este acesso à teleconsulta expirou. Abra novamente pelo aplicativo ou peça um novo convite.');
            }
            $stmt = $this->db->prepare(self::SESSION_SELECT . ' WHERE s.id = :id AND s.patient_id = :pid');
            $stmt->execute(['id' => (int)$m[1], 'pid' => (int)$m[2]]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$session || (int)$session['invite_generation'] !== (int)$m[3]) {
                throw new TenantSecurityException('Convite de teleconsulta inválido ou substituído.');
            }
        } else {
            if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
                throw new TenantSecurityException('Convite de teleconsulta inválido.');
            }
            $stmt = $this->db->prepare(self::SESSION_SELECT . ' WHERE s.patient_token_hash = :hash');
            $stmt->execute(['hash' => hash('sha256', $rawToken)]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session || !empty($session['invite_revoked_at_utc'])) {
                throw new TenantSecurityException('Convite de teleconsulta inválido, substituído ou revogado.');
            }
        }
        $session = $this->applyExpiry($session);
        if (in_array($session['status'], self::TERMINAL_STATUSES, true)) {
            throw new TeleconsultationStateException('Esta teleconsulta não está mais disponível. Fale com seu nutricionista.');
        }
        return $session;
    }

    public function submitDeviceCheck(string $rawToken, array $metrics): array
    {
        $session = $this->getSessionByPatientToken($rawToken);
        if (empty($metrics['camera_ok']) || empty($metrics['microphone_ok'])) {
            throw new InvalidArgumentException('O teste precisa confirmar câmera e microfone funcionando.');
        }
        $target = $session['status'] === self::STATUS_SCHEDULED ? self::STATUS_DEVICE_CHECKED : $session['status'];
        if ($target !== $session['status']) {
            $this->assertTransition($session['status'], $target);
        }

        $this->transaction(function () use ($session, $target, $metrics): void {
            $this->guardedUpdate((int)$session['id'], (int)$session['clinic_id'], $session['status'], [
                'device_check_passed' => 1,
                'status' => $target,
            ]);
            $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'device_checked', 'patient', (int)$session['patient_id'], [
                'browser_family' => self::browserFamily((string)($metrics['browser'] ?? '')),
            ]);
        });
        return ['device_check_passed' => true, 'status' => $target];
    }

    /** Versão oficial do termo de teleconsulta (inserida pela migração 35, consent_terms). */
    public const CONSENT_TERM_VERSION = '2026.10-r1';

    /**
     * Termo de teleconsulta em vigor (consent_terms, purpose_code='telehealth', ativo, versão oficial).
     * Sem o termo no banco a teleconsulta não avança (fail-closed).
     */
    public function getActiveConsentTerm(): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, version, title, term_text FROM consent_terms
            WHERE purpose_code = 'telehealth' AND version = :v AND is_active = 1
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['v' => self::CONSENT_TERM_VERSION]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Aceite do termo pelo paciente (F18 do v11): referencia o termo por consent_term_id.
     * Grava sessão (consent_term_id + versão) e patient_consents (consent_type='telehealth', term_id)
     * na mesma transação, com auditoria. Termo divergente do vigente = página desatualizada (409).
     */
    public function acceptConsent(string $rawToken, int $termId, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $session = $this->getSessionByPatientToken($rawToken);
        $term = $this->getActiveConsentTerm();
        if ($term === null) {
            throw new TeleconsultationStateException('O termo de consentimento da teleconsulta não está configurado. Avise a clínica.');
        }
        if ((int)$term['id'] !== $termId) {
            throw new TeleconsultationStateException('O termo foi atualizado. Recarregue a página e leia a versão atual.');
        }
        $now = $this->nowSql();

        $this->transaction(function () use ($session, $term, $ipAddress, $userAgent, $now): void {
            $this->guardedUpdate((int)$session['id'], (int)$session['clinic_id'], $session['status'], [
                'consent_term_id' => (int)$term['id'],
                'consent_term_version' => (string)$term['version'],
                'consent_accepted_at' => $now,
            ]);
            // Evidência no registro LGPD do paciente, com tenant e o termo exato aceito.
            $this->db->prepare("
                INSERT INTO patient_consents (clinic_id, patient_id, consent_type, granted, ip_address, granted_at, term_id, source_channel, user_agent)
                VALUES (:clinic_id, :patient_id, 'telehealth', 1, :ip, :at, :term_id, 'teleconsultation', :ua)
            ")->execute([
                'clinic_id' => (int)$session['clinic_id'],
                'patient_id' => (int)$session['patient_id'],
                'ip' => $ipAddress,
                'at' => $now,
                'term_id' => (int)$term['id'],
                'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ]);
            $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'consent_signed', 'patient', (int)$session['patient_id'], [
                'term_id' => (int)$term['id'],
                'term_version' => (string)$term['version'],
            ]);
        });
        return ['term_id' => (int)$term['id'], 'term_version' => (string)$term['version']];
    }

    public function enterWaitingRoom(string $rawToken): array
    {
        $session = $this->getSessionByPatientToken($rawToken);
        if ((int)$session['device_check_passed'] !== 1) {
            throw new TeleconsultationStateException('Conclua o teste de câmera e microfone antes de entrar na sala de espera.');
        }
        if (empty($session['consent_accepted_at'])) {
            throw new TeleconsultationStateException('Aceite o termo da teleconsulta antes de entrar na sala de espera.');
        }
        if ($this->now() < $this->utc((string)$session['join_opens_at_utc'])) {
            throw new TeleconsultationStateException('A sala de espera ainda não abriu. Ela abre pouco antes do horário da consulta.');
        }
        if ($session['status'] === self::STATUS_WAITING_ROOM) {
            return ['session_id' => (int)$session['id'], 'status' => self::STATUS_WAITING_ROOM];
        }
        $this->assertTransition($session['status'], self::STATUS_WAITING_ROOM);

        $this->transaction(function () use ($session): void {
            $this->guardedUpdate((int)$session['id'], (int)$session['clinic_id'], $session['status'], [
                'status' => self::STATUS_WAITING_ROOM,
                'waiting_room_entered_at' => $this->nowSql(),
            ]);
            $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'waiting_room_entered', 'patient', (int)$session['patient_id']);
        });
        return ['session_id' => (int)$session['id'], 'status' => self::STATUS_WAITING_ROOM];
    }

    /** Estado para o paciente: nunca inclui sala ou credencial. */
    public function checkWaitingRoomStatus(string $rawToken): array
    {
        $session = $this->getSessionByPatientToken($rawToken);
        return [
            'status' => $session['status'],
            'is_admitted' => $this->isAdmitted($session),
        ];
    }

    /** Credencial de mídia do paciente — somente após admissão e com sala pronta. */
    public function issuePatientMediaCredential(string $rawToken): array
    {
        $session = $this->getSessionByPatientToken($rawToken);
        if (!$this->isAdmitted($session)) {
            throw new TeleconsultationStateException('Aguarde a admissão pelo nutricionista.');
        }
        if (!$this->roomReady($session)) {
            throw new ExternalMediaProviderBlockedException('Sala de vídeo indisponível para esta sessão.');
        }
        return $this->issueCredential($session, 'patient', (string)$session['patient_name'], 'patient-' . (int)$session['patient_id'], (int)$session['patient_id']);
    }

    /**
     * Credencial de mídia do profissional (moderador). Provisiona a sala se
     * ainda não existir. Fail-closed se o provedor não estiver configurado.
     */
    public function issueProfessionalMediaCredential(int $sessionId, int $professionalId, ?int $clinicId = null): array
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        $session = $this->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        if (in_array($session['status'], self::TERMINAL_STATUSES, true)) {
            throw new TeleconsultationStateException('Teleconsulta encerrada.');
        }
        $session = $this->ensureRoom($session, $professionalId);
        return $this->issueCredential($session, 'professional', (string)$session['professional_name'], 'professional-' . $professionalId, $professionalId);
    }

    /**
     * Autoriza uma operação de mídia/sinalização apresentada com credencial.
     * Revalida o estado da sessão a cada chamada: encerrar, cancelar ou recusar
     * corta o acesso imediatamente, mesmo com credencial ainda no prazo.
     *
     * @return array{session: array, role: string}
     */
    public function authorizeMediaAccess(string $roomName, string $credential): array
    {
        if (!$this->videoProvider instanceof Contracts\VerifiableCredentialProviderInterface) {
            throw new TenantSecurityException('Este provedor não usa sinalização própria.');
        }
        $claims = $this->videoProvider->verifyMeetingToken($roomName, $credential, $this->now()->getTimestamp());

        $stmt = $this->db->prepare(self::SESSION_SELECT . " WHERE s.external_room_name = :room AND s.provider_status = 'provisioned'");
        $stmt->execute(['room' => $roomName]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new TenantSecurityException('Sala de teleconsulta encerrada ou inexistente.');
        }
        $session = $this->applyExpiry($session);

        $role = $claims['role'];
        $allowed = $role === 'professional'
            ? !in_array($session['status'], self::TERMINAL_STATUSES, true) && $claims['ref'] === 'professional-' . (int)$session['professional_id']
            : $this->isAdmitted($session) && $claims['ref'] === 'patient-' . (int)$session['patient_id'];
        if (!$allowed) {
            throw new TenantSecurityException('Acesso à sala de teleconsulta não autorizado neste momento.');
        }
        return ['session' => $session, 'role' => $role];
    }

    /** Admite o paciente que está na sala de espera. Exige sala provisionada. */
    public function admitPatient(int $sessionId, int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        $session = $this->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        if ($session['status'] === self::STATUS_ADMITTED) {
            return ['session_id' => $sessionId, 'status' => self::STATUS_ADMITTED];
        }
        if ($session['status'] !== self::STATUS_WAITING_ROOM) {
            throw new TeleconsultationStateException('Só é possível admitir um paciente que está na sala de espera.');
        }
        // Provisiona antes: admitir sem sala deixaria o paciente sem mídia.
        $session = $this->ensureRoom($session, $professionalId);
        $now = $this->nowSql();

        $this->transaction(function () use ($sessionId, $clinicId, $session, $now, $professionalId): void {
            $this->guardedUpdate($sessionId, $clinicId, self::STATUS_WAITING_ROOM, [
                'status' => self::STATUS_ADMITTED,
                'admitted_at' => $now,
                'started_at' => $session['started_at'] ?: $now,
            ]);
            $this->recordEvent($sessionId, $clinicId, 'patient_admitted', 'professional', $professionalId);
        });
        return ['session_id' => $sessionId, 'status' => self::STATUS_ADMITTED];
    }

    /** Recusa a entrada: estado terminal e convite revogado (reabrir = issueInvite). */
    public function rejectPatient(int $sessionId, int $professionalId, string $reason): array
    {
        $clinicId = TenantContext::getClinicId();
        $session = $this->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        $this->assertTransition($session['status'], self::STATUS_REJECTED);

        $this->transaction(function () use ($sessionId, $clinicId, $session, $reason, $professionalId): void {
            $this->guardedUpdate($sessionId, $clinicId, $session['status'], [
                'status' => self::STATUS_REJECTED,
                'invite_revoked_at_utc' => $this->nowSql(),
            ]);
            // Motivo como código fixo: texto livre na auditoria poderia carregar dado pessoal.
            $this->recordEvent($sessionId, $clinicId, 'patient_rejected', 'professional', $professionalId, [
                'reason' => in_array($reason, self::REJECT_REASONS, true) ? $reason : 'other',
            ]);
        });
        return ['session_id' => $sessionId, 'status' => self::STATUS_REJECTED];
    }

    /**
     * Registra evolução no prontuário durante ou logo após a chamada.
     * Não altera o estado da sessão nem conclui a consulta.
     */
    public function recordClinicalEvolution(int $sessionId, int $professionalId, string $title, array $evolutionData): array
    {
        $clinicId = TenantContext::getClinicId();
        $session = $this->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        if (in_array($session['status'], [self::STATUS_CANCELLED, self::STATUS_REJECTED], true)) {
            throw new TeleconsultationStateException('Não há atendimento para registrar nesta sessão.');
        }
        if (trim((string)($evolutionData['notes'] ?? '')) === '') {
            throw new InvalidArgumentException('A anotação clínica não pode ficar vazia.');
        }
        $evolutionData['teleconsultation_session_id'] = $sessionId;
        $evolutionData['appointment_id'] = (int)$session['appointment_id'];
        $evolutionData['recorded_during_telehealth'] = true;

        $record = $this->clinicalRecordService->appendRecord((int)$session['patient_id'], $professionalId, $title, $evolutionData);
        // Finalizou: o rascunho da chamada deixa de existir (NI2-249).
        $this->db->prepare('DELETE FROM teleconsultation_note_drafts WHERE session_id = :s AND professional_id = :p AND clinic_id = :c')
            ->execute(['s' => $sessionId, 'p' => $professionalId, 'c' => $clinicId]);

        $this->transaction(function () use ($sessionId, $clinicId, $session, $record, $professionalId): void {
            $this->guardedUpdate($sessionId, $clinicId, $session['status'], ['clinical_record_id' => (int)$record['id']]);
            $this->recordEvent($sessionId, $clinicId, 'clinical_note_appended', 'professional', $professionalId, [
                'clinical_record_id' => (int)$record['id'],
            ]);
        });
        return $record;
    }

    /**
     * Encerra a MÍDIA: estado 'ended', convite revogado, sala liberada.
     * Não muda o status da consulta na agenda nem publica plano (NI2-431).
     */
    public function endMedia(int $sessionId, int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();
        $session = $this->getSessionForProfessional($sessionId, $professionalId, $clinicId);
        $result = ['session_id' => $sessionId, 'status' => self::STATUS_ENDED, 'appointment_id' => (int)$session['appointment_id']];
        if ($session['status'] === self::STATUS_ENDED) {
            return $result;
        }
        $this->assertTransition($session['status'], self::STATUS_ENDED);
        $now = $this->nowSql();

        $this->transaction(function () use ($sessionId, $clinicId, $session, $now, $professionalId): void {
            $this->guardedUpdate($sessionId, $clinicId, $session['status'], [
                'status' => self::STATUS_ENDED,
                'ended_at' => $now,
                'media_ended_at_utc' => $now,
                'invite_revoked_at_utc' => $now,
            ]);
            $this->recordEvent($sessionId, $clinicId, 'call_ended', 'professional', $professionalId);
        });
        $this->releaseRoom($session, $clinicId);
        return $result;
    }

    /** Cancelamento / não comparecimento na agenda: cancela a sessão e revoga o acesso. */
    public function onAppointmentCancelled(int $appointmentId, int $clinicId, string $appointmentStatus): void
    {
        $session = $this->findSessionRowByAppointment($appointmentId, $clinicId);
        if ($session === null || in_array($session['status'], self::TERMINAL_STATUSES, true)) {
            return;
        }
        $now = $this->nowSql();
        $this->transaction(function () use ($session, $clinicId, $now, $appointmentStatus): void {
            $this->guardedUpdate((int)$session['id'], $clinicId, $session['status'], [
                'status' => self::STATUS_CANCELLED,
                'ended_at' => $now,
                'invite_revoked_at_utc' => $now,
            ]);
            $this->recordEvent((int)$session['id'], $clinicId, 'session_cancelled', 'system', null, [
                'appointment_status' => $appointmentStatus,
            ]);
            $this->releaseRoom($session, $clinicId);
        });
    }

    /** Reagendamento: revoga o convite atual e recalcula a janela; exige novo convite. */
    public function onAppointmentRescheduled(int $appointmentId, int $clinicId): void
    {
        $session = $this->findSessionRowByAppointment($appointmentId, $clinicId);
        if ($session === null || in_array($session['status'], self::TERMINAL_STATUSES, true) || $this->isAdmitted($session)) {
            return;
        }
        $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id = :id AND clinic_id = :c');
        $stmt->execute(['id' => $appointmentId, 'c' => $clinicId]);
        [$opensAt, $closesAt] = $this->joinWindow($stmt->fetch(PDO::FETCH_ASSOC));

        $this->transaction(function () use ($session, $clinicId, $opensAt, $closesAt): void {
        $this->releaseRoom($session, $clinicId);
        $this->guardedUpdate((int)$session['id'], $clinicId, $session['status'], [
            'status' => self::STATUS_SCHEDULED,
            'invite_revoked_at_utc' => $this->nowSql(),
            'device_check_passed' => 0,
            'join_opens_at_utc' => $opensAt->format('Y-m-d H:i:s'),
            'join_closes_at_utc' => $closesAt->format('Y-m-d H:i:s'),
            'external_room_id' => null,
            'external_room_name' => null,
            'external_room_url' => null,
            'provider_status' => 'pending',
        ]);
        $this->recordEvent((int)$session['id'], $clinicId, 'invite_revoked', 'system', null, ['reason' => 'appointment_rescheduled']);
        });
    }

    /** Evento de auditoria sem dados pessoais, para serviços do módulo (ex.: e-mails). */
    public function recordSystemEvent(int $sessionId, int $clinicId, string $eventType, string $actorType, ?int $actorId, array $details = []): void
    {
        if (!preg_match('/^[a-z_]{3,40}$/', $eventType)) {
            throw new InvalidArgumentException('Tipo de evento inválido.');
        }
        $this->recordEvent($sessionId, $clinicId, $eventType, $actorType, $actorId, $details);
    }

    public function getSessionEvents(int $sessionId, ?int $clinicId = null): array
    {
        $clinicId = $clinicId ?? TenantContext::getClinicId();
        $stmt = $this->db->prepare('SELECT * FROM teleconsultation_events WHERE session_id = :id AND clinic_id = :c ORDER BY id ASC');
        $stmt->execute(['id' => $sessionId, 'c' => $clinicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private const SESSION_SELECT = "
        SELECT s.*, p.name AS patient_name, u.name AS professional_name, pr.crn AS council_number,
               a.scheduled_at, a.duration_minutes, a.status AS appointment_status, c.name AS clinic_name
        FROM teleconsultation_sessions s
        INNER JOIN patients p ON p.id = s.patient_id AND p.clinic_id = s.clinic_id
        INNER JOIN professionals pr ON pr.id = s.professional_id
        INNER JOIN users u ON u.id = pr.user_id
        INNER JOIN appointments a ON a.id = s.appointment_id AND a.clinic_id = s.clinic_id
        INNER JOIN clinics c ON c.id = s.clinic_id";

    private function loadAppointmentForProfessional(int $appointmentId, int $professionalId, int $clinicId): array
    {
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id = :id AND clinic_id = :c');
        $stmt->execute(['id' => $appointmentId, 'c' => $clinicId]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$appointment || (int)$appointment['professional_id'] !== $professionalId) {
            throw new TenantSecurityException('Apenas o nutricionista responsável pelo agendamento pode abrir a teleconsulta.');
        }
        return $appointment;
    }

    private function findSessionIdByAppointment(int $appointmentId, int $clinicId): ?int
    {
        $row = $this->findSessionRowByAppointment($appointmentId, $clinicId);
        return $row === null ? null : (int)$row['id'];
    }

    private function findSessionRowByAppointment(int $appointmentId, int $clinicId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM teleconsultation_sessions WHERE appointment_id = :a AND clinic_id = :c');
        $stmt->execute(['a' => $appointmentId, 'c' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} janela de entrada em UTC */
    private function joinWindow(array $appointment): array
    {
        $appTz = new DateTimeZone(date_default_timezone_get());
        $start = (new DateTimeImmutable((string)$appointment['scheduled_at'], $appTz))->setTimezone(new DateTimeZone('UTC'));
        $duration = max(15, (int)($appointment['duration_minutes'] ?? 60));
        $early = self::intEnv('TELECONSULT_JOIN_EARLY_MINUTES', 15, 0, 120);
        $grace = self::intEnv('TELECONSULT_JOIN_GRACE_MINUTES', 30, 0, 240);
        return [$start->modify("-{$early} minutes"), $start->modify('+' . ($duration + $grace) . ' minutes')];
    }

    private function credentialExpiry(array $session): int
    {
        $closes = $this->utc((string)$session['join_closes_at_utc'])->getTimestamp();
        $cap = $this->now()->getTimestamp() + self::intEnv('TELECONSULT_CREDENTIAL_TTL_MINUTES', 90, 5, 240) * 60;
        return min($closes, $cap);
    }

    private function issueCredential(array $session, string $role, string $displayName, string $ref, int $actorId): array
    {
        $credential = $this->videoProvider->issueMeetingToken(
            (string)$session['external_room_name'],
            $role,
            $displayName,
            $ref,
            $this->credentialExpiry($session)
        );
        $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'media_credential_issued', $role, $actorId, [
            'expires_at_unix' => $credential['expires_at_unix'],
        ]);
        $iceServers = $this->videoProvider instanceof Contracts\VerifiableCredentialProviderInterface
            ? $this->videoProvider->iceServers() : [];
        $hasTurn = false;
        foreach ($iceServers as $server) {
            foreach ((array)$server['urls'] as $url) {
                $hasTurn = $hasTurn || preg_match('/^turns?:/', (string)$url) === 1;
            }
        }
        return [
            'provider' => $this->videoProvider->getProviderName(),
            'room_name' => (string)$session['external_room_name'],
            'room_url' => (string)($session['external_room_url'] ?? ''),
            'token' => $credential['token'],
            'expires_at_unix' => $credential['expires_at_unix'],
            'ice_servers' => $iceServers,
            'has_turn' => $hasTurn,
        ];
    }

    private function isAdmitted(array $session): bool
    {
        return in_array($session['status'], [self::STATUS_ADMITTED, 'in_progress'], true);
    }

    private function roomReady(array $session): bool
    {
        return !empty($session['external_room_name']) && $session['provider_status'] === 'provisioned';
    }

    /** Marca como expirada a sessão cuja janela fechou sem atendimento em curso. */
    private function applyExpiry(array $session): array
    {
        if (in_array($session['status'], self::TERMINAL_STATUSES, true) || $this->isAdmitted($session)
            || empty($session['join_closes_at_utc'])
            || $this->now() <= $this->utc((string)$session['join_closes_at_utc'])) {
            return $session;
        }
        try {
            $this->transaction(function () use ($session): void {
                $this->guardedUpdate((int)$session['id'], (int)$session['clinic_id'], $session['status'], [
                    'status' => self::STATUS_EXPIRED,
                    'invite_revoked_at_utc' => $this->nowSql(),
                ]);
                $this->recordEvent((int)$session['id'], (int)$session['clinic_id'], 'session_expired', 'system', null);
            });
        } catch (TeleconsultationStateException $e) {
            // Outra requisição já alterou o estado.
        }
        $session['status'] = self::STATUS_EXPIRED;
        $session['invite_revoked_at_utc'] = $this->nowSql();
        return $session;
    }

    private function ensureRoom(array $session, int $professionalId): array
    {
        if ($this->roomReady($session)) {
            return $session;
        }
        $clinicId = (int)$session['clinic_id'];
        $roomName = 'cn-' . bin2hex(random_bytes(12)); // sem ids de tenant/paciente no nome
        $expiresAt = $this->utc((string)$session['join_closes_at_utc'])->getTimestamp();

        try {
            $room = $this->videoProvider->createRoom($roomName, $expiresAt);
        } catch (ExternalMediaProviderBlockedException $e) {
            $this->db->prepare("UPDATE teleconsultation_sessions SET provider_status = 'blocked', updated_at = :now WHERE id = :id AND clinic_id = :c")
                ->execute(['now' => $this->nowSql(), 'id' => $session['id'], 'c' => $clinicId]);
            $this->recordEvent((int)$session['id'], $clinicId, 'provider_blocked', 'system', null, ['provider' => $this->videoProvider->getProviderName()]);
            throw $e;
        }

        $this->transaction(function () use ($session, $clinicId, $room, $professionalId): void {
            $this->db->prepare("
                UPDATE teleconsultation_sessions
                SET external_room_id = :rid, external_room_name = :rname, external_room_url = :rurl,
                    provider_status = 'provisioned', updated_at = :now
                WHERE id = :id AND clinic_id = :c
            ")->execute([
                'rid' => $room['room_id'], 'rname' => $room['room_name'], 'rurl' => $room['room_url'],
                'now' => $this->nowSql(), 'id' => $session['id'], 'c' => $clinicId,
            ]);
            $this->recordEvent((int)$session['id'], $clinicId, 'room_provisioned', 'professional', $professionalId, [
                'provider' => $this->videoProvider->getProviderName(),
                'expires_at_unix' => $room['expires_at_unix'],
            ]);
        });

        $session['external_room_id'] = $room['room_id'];
        $session['external_room_name'] = $room['room_name'];
        $session['external_room_url'] = $room['room_url'];
        $session['provider_status'] = 'provisioned';
        return $session;
    }

    private function releaseRoom(array $session, int $clinicId): void
    {
        if (empty($session['external_room_name'])) {
            return;
        }
        // Fecha a sala para novas gravações de sinal antes de apagar o que existe.
        $this->db->prepare("UPDATE teleconsultation_sessions SET provider_status = 'releasing' WHERE id = :id AND clinic_id = :c")
            ->execute(['id' => $session['id'], 'c' => $clinicId]);
        try {
            $released = $this->videoProvider->deleteRoom((string)$session['external_room_name']);
        } catch (\Throwable $e) {
            $released = false;
        }
        $this->db->prepare("UPDATE teleconsultation_sessions SET provider_status = :ps WHERE id = :id AND clinic_id = :c")
            ->execute(['ps' => $released ? 'released' : 'release_failed', 'id' => $session['id'], 'c' => $clinicId]);
        $this->recordEvent((int)$session['id'], $clinicId, $released ? 'room_released' : 'room_release_failed', 'system', null);
    }

    private function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new TeleconsultationStateException("Transição inválida da teleconsulta: '{$from}' → '{$to}'.");
        }
    }

    /**
     * UPDATE condicionado ao estado lido (controle otimista): se outra requisição
     * mudou o estado no meio, nada é gravado e a ação falha.
     */
    private function guardedUpdate(int $sessionId, int $clinicId, string $expectedStatus, array $fields): void
    {
        $fields['updated_at'] = $this->nowSql();
        $sets = [];
        $params = ['id' => $sessionId, 'c' => $clinicId, 'expected' => $expectedStatus];
        foreach ($fields as $column => $value) {
            if (!preg_match('/^[a-z_]+$/', $column)) {
                throw new InvalidArgumentException('Coluna inválida.');
            }
            $sets[] = "{$column} = :f_{$column}";
            $params["f_{$column}"] = $value;
        }
        $stmt = $this->db->prepare('UPDATE teleconsultation_sessions SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND clinic_id = :c AND status = :expected');
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new TeleconsultationStateException('A teleconsulta mudou de estado durante a operação. Atualize a página.');
        }
    }

    private int $savepointDepth = 0;

    /**
     * Executa em transação própria ou, se o chamador já abriu uma (ex.: agenda),
     * em SAVEPOINT — a falha desfaz só esta unidade e é propagada.
     */
    private function transaction(callable $work): void
    {
        if ($this->db->inTransaction()) {
            $name = 'tc_sp_' . (++$this->savepointDepth);
            $this->db->exec("SAVEPOINT {$name}");
            try {
                $work();
                $this->db->exec("RELEASE SAVEPOINT {$name}");
            } catch (\Throwable $e) {
                $this->db->exec("ROLLBACK TO SAVEPOINT {$name}");
                $this->db->exec("RELEASE SAVEPOINT {$name}");
                throw $e;
            } finally {
                $this->savepointDepth--;
            }
            return;
        }
        $this->db->beginTransaction();
        try {
            $work();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Auditoria obrigatória: falha aqui interrompe a ação. Sem PII em details. */
    private function recordEvent(int $sessionId, int $clinicId, string $eventType, string $actorType, ?int $actorId, array $details = []): void
    {
        $this->db->prepare("
            INSERT INTO teleconsultation_events (clinic_id, session_id, event_type, actor_type, actor_id, details_json, created_at)
            VALUES (:c, :s, :t, :at, :aid, :d, :now)
        ")->execute([
            'c' => $clinicId, 's' => $sessionId, 't' => $eventType, 'at' => $actorType, 'aid' => $actorId,
            'd' => json_encode($details, JSON_UNESCAPED_UNICODE), 'now' => $this->nowSql(),
        ]);
    }

    private function now(): DateTimeImmutable
    {
        return $this->fixedNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function nowSql(): string
    {
        return $this->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function intEnv(string $key, int $default, int $min, int $max): int
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[$key] ?? null;
        }
        return ($raw === null || !is_numeric($raw)) ? $default : max($min, min($max, (int)$raw));
    }

    private static function ticketKey(): ?string
    {
        $appKey = getenv('APP_KEY');
        if ($appKey === false || $appKey === '') {
            $appKey = $_ENV['APP_KEY'] ?? null;
        }
        return ($appKey === null || strlen((string)$appKey) < 16)
            ? null : hash_hmac('sha256', 'clyonutri-teleconsulta-portal-v1', (string)$appKey, true);
    }

    private static function browserFamily(string $userAgent): string
    {
        foreach (['Edg/' => 'edge', 'Firefox/' => 'firefox', 'Chrome/' => 'chrome', 'Safari/' => 'safari'] as $needle => $family) {
            if (str_contains($userAgent, $needle)) {
                return $family;
            }
        }
        return 'other';
    }
}
