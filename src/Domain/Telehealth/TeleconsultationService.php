<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Exceptions\ExternalMediaProviderBlockedException;
use ClyoNutri\Domain\Telehealth\Providers\DailyVideoProvider;
use PDO;

/**
 * Teleconsulta (NI2-221) — modelo único de sessão (teleconsultation_sessions).
 *
 * v11 (correções do code review rodada 2, independentes de provedor):
 * - F01/F01b: o profissional é resolvido no servidor (professionals.user_id + vínculo ativo na clínica);
 *   nunca se compara users.id com professionals.id. Só o responsável pelo agendamento age e lê a sala (F07).
 * - F04: convite do paciente = hash SHA-256 + geração + validade + janela; revogado ao cancelar/reagendar/encerrar.
 * - F05: o status do paciente devolve só o estado.
 * - F08: máquina de estados explícita, transições atômicas (UPDATE ... WHERE status IN) e idempotentes.
 * - F10: encerrar a mídia NÃO conclui o agendamento nem publica nada.
 * - F18: consentimento gravado em patient_consents com term_id; aceite e recusa auditados.
 * - F19: consulta presencial nunca cria sessão nem sala.
 * - F20: outro tenant/ID inexistente → 404 (TeleconsultationException), nunca 500.
 */
class TeleconsultationService
{
    /** Tipos de agendamento que são online. Qualquer outro é presencial (F19). */
    public const ONLINE_TYPES = ['teleconsultation', 'telehealth', 'teleconsulta', 'online', 'video'];

    /** Estados do agendamento em que a teleconsulta pode existir/ser acessada. */
    public const ACTIVE_APPOINTMENT_STATUSES = ['scheduled', 'confirmed', 'in_progress'];

    public const TERMINAL = ['completed', 'rejected', 'cancelled'];

    /**
     * Máquina de estados (F08). 'in_progress' = em chamada (in_call); 'completed' = encerrada (ended).
     * Repetir o estado atual é idempotente (sem evento novo). Estados terminais não reabrem.
     */
    public const TRANSITIONS = [
        'scheduled' => ['device_checked', 'waiting_room', 'rejected', 'cancelled'],
        'device_checked' => ['waiting_room', 'rejected', 'cancelled'],
        'waiting_room' => ['admitted', 'rejected', 'cancelled'],
        'admitted' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    private PDO $db;
    private VideoConferenceProviderInterface $videoProvider;
    private ClinicalRecordService $clinicalRecordService;
    /** @var callable|null */
    private $clock;

    public function __construct(
        ?PDO $db = null,
        ?VideoConferenceProviderInterface $videoProvider = null,
        ?ClinicalRecordService $clinicalRecordService = null,
        ?callable $clock = null
    ) {
        $this->db = $db ?? Connection::getInstance();
        $this->videoProvider = $videoProvider ?? new DailyVideoProvider();
        $this->clinicalRecordService = $clinicalRecordService ?? new ClinicalRecordService($this->db);
        $this->clock = $clock;
    }

    // ------------------------------------------------------------------ utilidades

    private function now(): int
    {
        return $this->clock ? (int)($this->clock)() : time();
    }

    private function nowStr(): string
    {
        return date('Y-m-d H:i:s', $this->now());
    }

    /** Transação própria só se não houver uma externa (mesmo padrão do ClinicalRecordService). */
    private function begin(): bool
    {
        if ($this->db->inTransaction()) {
            return false;
        }
        $this->db->beginTransaction();
        return true;
    }

    private function commit(bool $own): void
    {
        if ($own && $this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    private function rollback(bool $own): void
    {
        if ($own && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public static function isOnlineType(?string $type): bool
    {
        return in_array(strtolower(trim((string)$type)), self::ONLINE_TYPES, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Janela de entrada (minutos), configurável no .env. Padrão: 15 min antes até o fim + 30 min. */
    public static function entryBeforeSeconds(): int
    {
        $v = (int)(Env::get('TELEHEALTH_ENTRY_BEFORE_MIN', 15) ?? 15);
        return max(0, min(240, $v)) * 60;
    }

    public static function entryAfterEndSeconds(): int
    {
        $v = (int)(Env::get('TELEHEALTH_ENTRY_AFTER_END_MIN', 30) ?? 30);
        return max(0, min(240, $v)) * 60;
    }

    /** @return array{0:int,1:int} [início, fim] da janela em timestamp */
    public static function entryWindow(string $scheduledAt, int $durationMinutes): array
    {
        $start = strtotime($scheduledAt);
        $end = $start + max(15, $durationMinutes) * 60;
        return [$start - self::entryBeforeSeconds(), $end + self::entryAfterEndSeconds()];
    }

    public function isVideoConfigured(): bool
    {
        return method_exists($this->videoProvider, 'isConfigured') ? (bool)$this->videoProvider->isConfigured() : false;
    }

    // ------------------------------------------------------------------ identidade (F01)

    /**
     * Resolve o professionals.id do usuário logado NA clínica ativa (vínculo ativo, perfil clínico).
     * Retorna null se não houver exatamente um.
     */
    public function resolveProfessionalId(int $userId, int $clinicId): ?int
    {
        $st = $this->db->prepare("
            SELECT p.id
            FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id AND cm.clinic_id = :clinic_id
            WHERE p.user_id = :user_id AND cm.status = 'active' AND u.status = 'active'
              AND cm.role IN ('owner','admin','nutritionist') AND u.role IN ('nutritionist','admin','owner')
            LIMIT 2
        ");
        $st->execute(['user_id' => $userId, 'clinic_id' => $clinicId]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    /** Exige que o usuário seja o profissional responsável. Devolve o professionals.id resolvido. */
    private function assertResponsible(int $userId, int $clinicId, int $responsibleProfessionalId): int
    {
        $pid = $this->resolveProfessionalId($userId, $clinicId);
        if ($pid === null || $pid !== $responsibleProfessionalId) {
            throw TeleconsultationException::forbidden();
        }
        return $pid;
    }

    // ------------------------------------------------------------------ leitura

    private function fetchAppointment(int $appointmentId, int $clinicId): array
    {
        $st = $this->db->prepare("
            SELECT a.id, a.clinic_id, a.patient_id, a.professional_id, a.scheduled_at, a.duration_minutes, a.status, a.type
            FROM appointments a WHERE a.id = :id AND a.clinic_id = :clinic_id
        ");
        $st->execute(['id' => $appointmentId, 'clinic_id' => $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw TeleconsultationException::notFound();
        }
        return $row;
    }

    private function sessionSelect(): string
    {
        return "
            SELECT s.*, p.name AS patient_name, u.name AS professional_name, pr.crn AS council_number,
                   a.scheduled_at, a.duration_minutes, a.status AS appointment_status, a.type AS appointment_type,
                   a.professional_id AS appointment_professional_id, c.name AS clinic_name
            FROM teleconsultation_sessions s
            INNER JOIN patients p ON p.id = s.patient_id
            INNER JOIN professionals pr ON pr.id = s.professional_id
            INNER JOIN users u ON u.id = pr.user_id
            INNER JOIN appointments a ON a.id = s.appointment_id
            INNER JOIN clinics c ON c.id = s.clinic_id
        ";
    }

    /** Sessão por ID restrita ao tenant. Outro tenant ou inexistente → 404 (F20). */
    public function getSessionById(int $sessionId, ?int $clinicId = null): array
    {
        $clinicId ??= TenantContext::getClinicId();
        $st = $this->db->prepare($this->sessionSelect() . " WHERE s.id = :id AND s.clinic_id = :clinic_id");
        $st->execute(['id' => $sessionId, 'clinic_id' => $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw TeleconsultationException::notFound();
        }
        return $row;
    }

    /** Sala do profissional: só o responsável (F07). Sem efeito colateral. */
    public function getSessionForProfessional(int $sessionId, int $userId, int $clinicId): array
    {
        $s = $this->getSessionById($sessionId, $clinicId);
        $this->assertResponsible($userId, $clinicId, (int)$s['professional_id']);
        return $s;
    }

    public function findSessionIdByAppointment(int $appointmentId, int $clinicId): ?int
    {
        $st = $this->db->prepare("SELECT id FROM teleconsultation_sessions WHERE appointment_id = :a AND clinic_id = :c");
        $st->execute(['a' => $appointmentId, 'c' => $clinicId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Pré-visualização para GET /teleconsulta/sessao-agenda/{id}: valida acesso SEM criar nada (F07).
     * @return array{appointment: array, session_id: ?int}
     */
    public function previewForAppointment(int $appointmentId, int $userId, int $clinicId): array
    {
        $app = $this->fetchAppointment($appointmentId, $clinicId);
        $this->assertResponsible($userId, $clinicId, (int)$app['professional_id']);
        if (!self::isOnlineType($app['type'])) {
            throw TeleconsultationException::conflict('Esta consulta é presencial: não há sala de vídeo para ela.');
        }
        return ['appointment' => $app, 'session_id' => $this->findSessionIdByAppointment($appointmentId, $clinicId)];
    }

    // ------------------------------------------------------------------ criação (POST, idempotente)

    /**
     * Cria (ou devolve) a sessão do agendamento. Só o responsável, só consulta online (F19),
     * só agendamento ativo. Uma sessão por agendamento (UNIQUE appointment_id + checagem).
     * Não emite convite: o link do paciente é gerado à parte (issuePatientLink), uma vez por vez.
     * @return array{session_id:int, created:bool}
     */
    public function createSessionForAppointment(int $appointmentId, int $userId, int $clinicId): array
    {
        $app = $this->fetchAppointment($appointmentId, $clinicId);
        $professionalId = $this->assertResponsible($userId, $clinicId, (int)$app['professional_id']);
        if (!self::isOnlineType($app['type'])) {
            throw TeleconsultationException::conflict('Esta consulta é presencial: não há sala de vídeo para ela.');
        }
        if (!in_array($app['status'], self::ACTIVE_APPOINTMENT_STATUSES, true)) {
            throw TeleconsultationException::conflict('Este agendamento não está ativo (cancelado, concluído ou faltou).');
        }

        $existing = $this->findSessionIdByAppointment($appointmentId, $clinicId);
        if ($existing !== null) {
            return ['session_id' => $existing, 'created' => false];
        }

        $roomName = 'clyo-' . bin2hex(random_bytes(12)); // sem IDs de clínica/consulta no nome
        $roomId = null;
        $roomUrl = null;
        $mediaStatus = 'unconfigured';
        if ($this->isVideoConfigured()) {
            try {
                $room = $this->videoProvider->createRoom($roomName, 7200);
                $roomId = $room['room_id'] ?? null;
                $roomUrl = $room['room_url'] ?? null;
                $roomName = $room['room_name'] ?? $roomName;
                $mediaStatus = 'room_created';
            } catch (ExternalMediaProviderBlockedException $e) {
                error_log('[teleconsulta] provedor de vídeo indisponível ao criar sala: ' . substr($e->getMessage(), 0, 200));
                $mediaStatus = 'provider_error';
            }
        }

        $now = $this->nowStr();
        $own = $this->begin();
        try {
            $ins = $this->db->prepare("
                INSERT INTO teleconsultation_sessions (
                    clinic_id, appointment_id, patient_id, professional_id, provider,
                    external_room_id, external_room_url, external_room_name, patient_token_hash, professional_token_hash,
                    status, token_generation, scheduled_at_snapshot, created_at, updated_at
                ) VALUES (
                    :clinic_id, :appointment_id, :patient_id, :professional_id, :provider,
                    :room_id, :room_url, :room_name, :token_hash, NULL,
                    'scheduled', 0, :snap, :now, :now2
                )
            ");
            $ins->execute([
                'clinic_id' => $clinicId,
                'appointment_id' => $appointmentId,
                'patient_id' => (int)$app['patient_id'],
                'professional_id' => $professionalId,
                'provider' => $this->videoProvider->getProviderName(),
                'room_id' => $roomId,
                'room_url' => $roomUrl,
                'room_name' => $roomUrl !== null ? $roomName : null,
                // nenhum convite ativo até o profissional gerar o link: hash de um valor descartado
                'token_hash' => hash('sha256', 'unissued|' . bin2hex(random_bytes(32))),
                'snap' => $app['scheduled_at'],
                'now' => $now,
                'now2' => $now,
            ]);
            $sessionId = (int)$this->db->lastInsertId();
            $this->recordEvent($sessionId, $clinicId, 'session_created', 'professional', $professionalId, [
                'media_provider_status' => $mediaStatus,
            ]);
            $this->commit($own);
        } catch (\PDOException $e) {
            $this->rollback($own);
            // corrida: outra requisição criou a sessão (UNIQUE appointment_id) → idempotente
            $existing = $this->findSessionIdByAppointment($appointmentId, $clinicId);
            if ($existing !== null) {
                return ['session_id' => $existing, 'created' => false];
            }
            throw $e;
        }
        return ['session_id' => $sessionId, 'created' => true];
    }

    /** Compatibilidade com chamadas antigas (testes/benchmark): cria pelo usuário responsável. */
    public function scheduleSession(int $appointmentId, ?int $clinicId = null, ?int $userId = null): array
    {
        $clinicId ??= TenantContext::getClinicId();
        $userId ??= (int)(TenantContext::getUserId() ?? 0);
        $r = $this->createSessionForAppointment($appointmentId, $userId, $clinicId);
        return ['session_id' => $r['session_id'], 'appointment_id' => $appointmentId, 'status' => 'scheduled', 'created' => $r['created']];
    }

    // ------------------------------------------------------------------ convite do paciente (F03 parcial / F04)

    /**
     * Gera (ou regera) o link do paciente. Só o responsável. O token bruto é devolvido UMA vez e
     * nunca gravado; a geração anterior deixa de valer. A entrega ao paciente ainda é manual (F03).
     * @return array{token:string, expires_at:string, window_start:string, generation:int}
     */
    public function issuePatientLink(int $sessionId, int $userId, int $clinicId): array
    {
        $s = $this->getSessionForProfessional($sessionId, $userId, $clinicId);
        if (in_array($s['status'], self::TERMINAL, true)) {
            throw TeleconsultationException::conflict('A teleconsulta já foi encerrada, recusada ou cancelada: não é possível gerar link.');
        }
        if (!in_array($s['appointment_status'], self::ACTIVE_APPOINTMENT_STATUSES, true)) {
            throw TeleconsultationException::conflict('Este agendamento não está ativo.');
        }
        [$wStart, $wEnd] = self::entryWindow($s['scheduled_at'], (int)$s['duration_minutes']);
        if ($this->now() > $wEnd) {
            throw TeleconsultationException::conflict('O horário desta consulta já passou: reagende antes de gerar um link.');
        }
        $raw = bin2hex(random_bytes(32));
        $now = $this->nowStr();
        $own = $this->begin();
        try {
            $up = $this->db->prepare("
                UPDATE teleconsultation_sessions
                SET patient_token_hash = :h, token_generation = token_generation + 1, token_issued_at = :now,
                    token_expires_at = :exp, token_revoked_at = NULL, scheduled_at_snapshot = :snap, updated_at = :now2
                WHERE id = :id AND clinic_id = :c AND status NOT IN ('completed','rejected','cancelled')
            ");
            $up->execute([
                'h' => hash('sha256', $raw), 'now' => $now, 'exp' => date('Y-m-d H:i:s', $wEnd),
                'snap' => $s['scheduled_at'], 'now2' => $now, 'id' => $sessionId, 'c' => $clinicId,
            ]);
            if ($up->rowCount() !== 1) {
                throw TeleconsultationException::conflict();
            }
            $gen = (int)$this->db->query('SELECT token_generation FROM teleconsultation_sessions WHERE id = ' . (int)$sessionId)->fetchColumn();
            $this->recordEvent($sessionId, $clinicId, 'token_issued', 'professional', (int)$s['professional_id'], [
                'generation' => $gen, 'expires_at' => date('Y-m-d H:i:s', $wEnd),
            ]);
            $this->commit($own);
        } catch (\Throwable $e) {
            $this->rollback($own);
            throw $e;
        }
        return ['token' => $raw, 'expires_at' => date('Y-m-d H:i:s', $wEnd), 'window_start' => date('Y-m-d H:i:s', $wStart), 'generation' => $gen];
    }

    /**
     * Revoga o convite quando o agendamento é cancelado/reagendado/marcado como falta ou concluído
     * (chamado pelo AppointmentService). Cancelamento também cancela a sessão não terminal.
     */
    public function revokeForAppointment(int $appointmentId, int $clinicId, string $reason): void
    {
        $sid = $this->findSessionIdByAppointment($appointmentId, $clinicId);
        if ($sid === null) {
            return;
        }
        $now = $this->nowStr();
        $cancel = in_array($reason, ['appointment_cancelled', 'appointment_no_show'], true);
        // Cancelar/falta: sessão não terminal vira 'cancelled'. Reagendar: só revoga o convite (estado mantido).
        $statusSql = $cancel ? "status = CASE WHEN status NOT IN ('completed','rejected','cancelled') THEN 'cancelled' ELSE status END," : '';
        $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET patient_token_hash = :h, token_generation = token_generation + 1, token_revoked_at = :now,
                {$statusSql}
                updated_at = :now2
            WHERE id = :id AND clinic_id = :c
        ")->execute([
            'h' => hash('sha256', 'revoked|' . bin2hex(random_bytes(32))), 'now' => $now,
            'now2' => $now, 'id' => $sid, 'c' => $clinicId,
        ]);
        $this->recordEvent($sid, $clinicId, 'token_revoked', 'system', null, ['reason' => $reason]);
    }

    /**
     * Valida um convite (token bruto do link). Inválido, revogado, expirado, consulta cancelada/reagendada
     * ou fora do prazo → TeleconsultationException 404 genérica. Antes da janela abrir, devolve a sessão
     * com 'too_early' = true (o paciente vê só "ainda não abriu", sem dados).
     */
    public function getSessionByPatientToken(string $rawToken): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
            throw TeleconsultationException::invalidLink();
        }
        $st = $this->db->prepare($this->sessionSelect() . " WHERE s.patient_token_hash = :h");
        $st->execute(['h' => hash('sha256', $rawToken)]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            throw TeleconsultationException::invalidLink();
        }
        return $this->assertPatientAccess($s);
    }

    /** Revalida a sessão do paciente guardada no cookie (id + geração + hash). */
    public function getSessionForPatientCookie(int $sessionId, int $generation, string $tokenHash): array
    {
        $st = $this->db->prepare($this->sessionSelect() . " WHERE s.id = :id");
        $st->execute(['id' => $sessionId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s || (int)$s['token_generation'] !== $generation || !hash_equals((string)$s['patient_token_hash'], $tokenHash)) {
            throw TeleconsultationException::invalidLink();
        }
        return $this->assertPatientAccess($s);
    }

    private function assertPatientAccess(array $s): array
    {
        $now = $this->now();
        [$wStart, $wEnd] = self::entryWindow($s['scheduled_at'], (int)$s['duration_minutes']);
        $ok = !empty($s['token_issued_at'])
            && empty($s['token_revoked_at'])
            && !in_array($s['status'], self::TERMINAL, true)
            && in_array($s['appointment_status'], self::ACTIVE_APPOINTMENT_STATUSES, true)
            && self::isOnlineType($s['appointment_type'])
            && (string)$s['scheduled_at_snapshot'] === (string)$s['scheduled_at']
            && (int)$s['appointment_professional_id'] === (int)$s['professional_id']
            && !empty($s['token_expires_at']) && $now <= strtotime((string)$s['token_expires_at'])
            && $now <= $wEnd;
        if (!$ok) {
            throw TeleconsultationException::invalidLink();
        }
        $s['too_early'] = $now < $wStart;
        $s['window_start'] = date('Y-m-d H:i:s', $wStart);
        return $s;
    }

    private function assertInWindow(array $s): void
    {
        if (!empty($s['too_early'])) {
            throw TeleconsultationException::conflict('A sala ainda não abriu. Volte perto do horário da consulta.');
        }
    }

    // ------------------------------------------------------------------ paciente

    public function submitDeviceCheck(array $s, array $metrics): array
    {
        $this->assertInWindow($s);
        if (empty($metrics['camera_ok']) || empty($metrics['microphone_ok'])) {
            throw TeleconsultationException::conflict('Confirme câmera e microfone para continuar.');
        }
        // F17 (aberto): a flag vem do navegador; serve como UX, não como prova técnica.
        $this->db->prepare("
            UPDATE teleconsultation_sessions
            SET device_check_passed = 1,
                status = CASE WHEN status = 'scheduled' THEN 'device_checked' ELSE status END,
                updated_at = :now
            WHERE id = :id AND status IN ('scheduled','device_checked','waiting_room')
        ")->execute(['now' => $this->nowStr(), 'id' => $s['id']]);
        $this->recordEvent((int)$s['id'], (int)$s['clinic_id'], 'device_checked', 'patient', (int)$s['patient_id'], []);
        return ['ok' => true];
    }

    public function getActiveConsentTerm(): ?array
    {
        $st = $this->db->query("SELECT id, version, title, term_text FROM consent_terms WHERE purpose_code = 'telehealth' AND is_active = 1 ORDER BY id DESC LIMIT 1");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return $row ?: null;
    }

    /**
     * Aceite ou recusa do termo (F18). Grava em patient_consents (clinic_id, granted, term_id) e na sessão,
     * na mesma transação; qualquer erro sobe (não é engolido). IP/UA minimizados.
     */
    public function recordConsent(array $s, int $termId, bool $granted, ?string $ip, ?string $ua): array
    {
        $this->assertInWindow($s);
        $term = $this->getActiveConsentTerm();
        if ($term === null) {
            throw new TeleconsultationException(503, 'O termo de consentimento da teleconsulta não está configurado. Avise a clínica.');
        }
        if ((int)$term['id'] !== $termId) {
            throw TeleconsultationException::conflict('O termo foi atualizado. Recarregue a página e leia a versão atual.');
        }
        $now = $this->nowStr();
        $ipMin = \ClyoNutri\Core\Security\TelehealthRateLimiter::anonymizeIp($ip);
        $uaMin = \ClyoNutri\Core\Security\TelehealthRateLimiter::summarizeUserAgent($ua);
        $own = $this->begin();
        try {
            $this->db->prepare("
                INSERT INTO patient_consents (clinic_id, patient_id, consent_type, granted, ip_address, granted_at, term_id, source_channel, user_agent)
                VALUES (:c, :p, 'telehealth', :g, :ip, :now, :t, 'teleconsulta_web', :ua)
            ")->execute([
                'c' => (int)$s['clinic_id'], 'p' => (int)$s['patient_id'], 'g' => $granted ? 1 : 0,
                'ip' => $ipMin, 'now' => $now, 't' => (int)$term['id'], 'ua' => $uaMin,
            ]);
            $consentId = (int)$this->db->lastInsertId();
            $this->db->prepare("
                UPDATE teleconsultation_sessions
                SET consent_term_version = :v, consent_term_id = :t, consent_accepted_at = :acc, updated_at = :now
                WHERE id = :id
            ")->execute([
                'v' => $term['version'], 't' => (int)$term['id'], 'acc' => $granted ? $now : null, 'now' => $now, 'id' => $s['id'],
            ]);
            $this->recordEvent((int)$s['id'], (int)$s['clinic_id'], $granted ? 'consent_signed' : 'consent_declined', 'patient', (int)$s['patient_id'], [
                'term_id' => (int)$term['id'], 'term_version' => $term['version'], 'patient_consent_id' => $consentId,
            ]);
            $this->commit($own);
        } catch (\Throwable $e) {
            $this->rollback($own);
            throw $e;
        }
        return ['ok' => true, 'granted' => $granted, 'term_version' => $term['version']];
    }

    /** Compatibilidade: aceite pela versão (usa o termo ativo). */
    public function acceptConsent(string $rawToken, string $termVersion, ?string $ip = null, ?string $ua = null): array
    {
        $s = $this->getSessionByPatientToken($rawToken);
        $term = $this->getActiveConsentTerm();
        if ($term === null || $term['version'] !== $termVersion) {
            throw TeleconsultationException::conflict('Versão do termo inválida.');
        }
        return $this->recordConsent($s, (int)$term['id'], true, $ip, $ua);
    }

    public function enterWaitingRoom(array $s): array
    {
        $this->assertInWindow($s);
        if ((int)$s['device_check_passed'] !== 1) {
            throw TeleconsultationException::conflict('Faça o teste de câmera e microfone antes de entrar.');
        }
        if (empty($s['consent_accepted_at'])) {
            throw TeleconsultationException::conflict('É preciso aceitar o termo de consentimento antes de entrar.');
        }
        if ($s['status'] === 'waiting_room' || in_array($s['status'], ['admitted', 'in_progress'], true)) {
            return ['status' => $s['status']];
        }
        $this->transition($s, ['scheduled', 'device_checked'], 'waiting_room', 'waiting_room_entered', 'patient', (int)$s['patient_id'], [], ['waiting_room_entered_at']);
        return ['status' => 'waiting_room'];
    }

    /** F05: só o estado; nunca URL de sala nem credencial. */
    public function patientStatus(array $s): array
    {
        return [
            'status' => $s['status'],
            'is_admitted' => in_array($s['status'], ['admitted', 'in_progress'], true),
        ];
    }

    // ------------------------------------------------------------------ profissional (F01/F07/F08)

    public function admitPatient(int $sessionId, int $userId, ?int $clinicId = null): array
    {
        $clinicId ??= TenantContext::getClinicId();
        $s = $this->getSessionForProfessional($sessionId, $userId, $clinicId);
        if (in_array($s['status'], ['admitted', 'in_progress'], true)) {
            return ['status' => $s['status'], 'changed' => false];
        }
        $this->transition($s, ['waiting_room'], 'admitted', 'patient_admitted', 'professional', (int)$s['professional_id'], [], ['admitted_at', 'started_at']);
        return ['status' => 'admitted', 'changed' => true];
    }

    public function rejectPatient(int $sessionId, int $userId, string $reason, ?int $clinicId = null): array
    {
        $clinicId ??= TenantContext::getClinicId();
        $s = $this->getSessionForProfessional($sessionId, $userId, $clinicId);
        if ($s['status'] === 'rejected') {
            return ['status' => 'rejected', 'changed' => false];
        }
        $this->transition($s, ['scheduled', 'device_checked', 'waiting_room'], 'rejected', 'patient_rejected', 'professional', (int)$s['professional_id'],
            ['reason' => mb_substr($reason, 0, 200)], ['ended_at'], true);
        return ['status' => 'rejected', 'changed' => true];
    }

    /**
     * Evolução no prontuário com a autoria do profissional resolvido (= responsável).
     * admitted → in_progress; depois de encerrada entra como adendo e NÃO muda o estado.
     */
    public function recordClinicalEvolution(int $sessionId, int $userId, string $title, array $data, ?int $clinicId = null): array
    {
        $clinicId ??= TenantContext::getClinicId();
        $s = $this->getSessionForProfessional($sessionId, $userId, $clinicId);
        if (!in_array($s['status'], ['admitted', 'in_progress', 'completed'], true)) {
            throw TeleconsultationException::conflict('A evolução só pode ser gravada depois de admitir o paciente.');
        }
        $professionalId = (int)$s['professional_id'];
        $data['teleconsultation_session_id'] = $sessionId;
        $data['appointment_id'] = (int)$s['appointment_id'];
        $data['recorded_during_telehealth'] = $s['status'] !== 'completed';
        if ($s['status'] === 'completed') {
            $data['addendum_after_end'] = true;
        }

        $own = $this->begin();
        try {
            $record = $this->clinicalRecordService->appendRecord((int)$s['patient_id'], $professionalId, $title, $data);
            $now = $this->nowStr();
            $this->db->prepare("UPDATE teleconsultation_sessions SET clinical_record_id = :r, updated_at = :now WHERE id = :id AND clinic_id = :c")
                ->execute(['r' => $record['id'], 'now' => $now, 'id' => $sessionId, 'c' => $clinicId]);
            if ($s['status'] === 'admitted') {
                $this->db->prepare("UPDATE teleconsultation_sessions SET status = 'in_progress', updated_at = :now WHERE id = :id AND clinic_id = :c AND status = 'admitted'")
                    ->execute(['now' => $now, 'id' => $sessionId, 'c' => $clinicId]);
            }
            $this->recordEvent($sessionId, $clinicId, $s['status'] === 'completed' ? 'clinical_addendum_appended' : 'clinical_note_appended', 'professional', $professionalId, [
                'clinical_record_id' => $record['id'],
            ]);
            $this->commit($own);
        } catch (\Throwable $e) {
            $this->rollback($own);
            throw $e;
        }
        return $record;
    }

    /**
     * Encerra a mídia (F08/F10). Idempotente. NÃO altera appointments.status nem publica nada:
     * finalizar a consulta continua sendo uma ação separada na agenda (NI2-431).
     */
    public function endTeleconsultation(int $sessionId, int $userId, ?int $clinicId = null): array
    {
        $clinicId ??= TenantContext::getClinicId();
        $s = $this->getSessionForProfessional($sessionId, $userId, $clinicId);
        if ($s['status'] === 'completed') {
            return ['status' => 'completed', 'changed' => false];
        }
        $this->transition($s, ['admitted', 'in_progress'], 'completed', 'call_ended', 'professional', (int)$s['professional_id'], [], ['ended_at'], true);
        $room = (string)($s['external_room_name'] ?? '');
        if ($room !== '') {
            try {
                $this->videoProvider->deleteRoom($room); // F11: a API espera o NOME da sala
            } catch (\Throwable $e) {
                error_log('[teleconsulta] falha ao encerrar sala no provedor (sessão ' . (int)$sessionId . ')');
            }
        }
        return ['status' => 'completed', 'changed' => true];
    }

    /**
     * Transição atômica: UPDATE ... WHERE status IN (origens). 0 linhas → 409. Evento na mesma transação.
     * $revokeToken: encerra o convite (estado terminal).
     */
    private function transition(array $s, array $from, string $to, string $event, string $actorType, ?int $actorId,
                                array $details = [], array $stampCols = [], bool $revokeToken = false): void
    {
        foreach ($from as $f) {
            if (!self::canTransition($f, $to)) {
                throw new \LogicException("transição não declarada {$f}->{$to}");
            }
        }
        if (!in_array($s['status'], $from, true)) {
            throw TeleconsultationException::conflict();
        }
        $now = $this->nowStr();
        $set = ['status = :to', 'updated_at = :now'];
        foreach ($stampCols as $c) {
            $set[] = $c === 'started_at' ? 'started_at = COALESCE(started_at, :now_' . $c . ')' : "{$c} = :now_{$c}";
        }
        $params = ['to' => $to, 'now' => $now, 'id' => (int)$s['id'], 'c' => (int)$s['clinic_id']];
        foreach ($stampCols as $c) {
            $params['now_' . $c] = $now;
        }
        if ($revokeToken) {
            $set[] = 'patient_token_hash = :rh';
            $set[] = 'token_revoked_at = :now_rv';
            $params['rh'] = hash('sha256', 'ended|' . bin2hex(random_bytes(32)));
            $params['now_rv'] = $now;
        }
        $in = [];
        foreach (array_values($from) as $i => $f) {
            $in[] = ':f' . $i;
            $params['f' . $i] = $f;
        }
        $own = $this->begin();
        try {
            $up = $this->db->prepare('UPDATE teleconsultation_sessions SET ' . implode(', ', $set)
                . ' WHERE id = :id AND clinic_id = :c AND status IN (' . implode(',', $in) . ')');
            $up->execute($params);
            if ($up->rowCount() !== 1) {
                throw TeleconsultationException::conflict();
            }
            $this->recordEvent((int)$s['id'], (int)$s['clinic_id'], $event, $actorType, $actorId, $details);
            $this->commit($own);
        } catch (\Throwable $e) {
            $this->rollback($own);
            throw $e;
        }
    }

    public function getSessionEvents(int $sessionId, ?int $clinicId = null): array
    {
        $clinicId ??= TenantContext::getClinicId();
        $st = $this->db->prepare("SELECT * FROM teleconsultation_events WHERE session_id = :id AND clinic_id = :c ORDER BY id ASC");
        $st->execute(['id' => $sessionId, 'c' => $clinicId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Auditoria: sem IP/UA e sem conteúdo clínico (F15). Falha sobe (não é engolida). */
    private function recordEvent(int $sessionId, int $clinicId, string $eventType, string $actorType, ?int $actorId, array $details = []): void
    {
        $this->db->prepare("
            INSERT INTO teleconsultation_events (clinic_id, session_id, event_type, actor_type, actor_id, details_json, created_at)
            VALUES (:clinic_id, :session_id, :event_type, :actor_type, :actor_id, :details, :now)
        ")->execute([
            'clinic_id' => $clinicId, 'session_id' => $sessionId, 'event_type' => $eventType,
            'actor_type' => $actorType, 'actor_id' => $actorId,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'now' => $this->nowStr(),
        ]);
    }
}
