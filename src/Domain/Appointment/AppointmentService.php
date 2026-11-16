<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Appointment;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Time\ClinicCalendar;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class AppointmentService
{
    private PDO $db;
    private ClinicCalendar $calendar;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const VALID_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * Matriz de transições de estado permitidas (State Machine estrita)
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_SCHEDULED => [
            self::STATUS_CONFIRMED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ],
        self::STATUS_CONFIRMED => [
            self::STATUS_IN_PROGRESS,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
        ],
        self::STATUS_IN_PROGRESS => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_COMPLETED => [], // Terminal
        self::STATUS_CANCELLED => [], // Terminal
        self::STATUS_NO_SHOW   => [], // Terminal
    ];

    public function __construct(?PDO $db = null, ?DateTimeImmutable $now = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->calendar = new ClinicCalendar($now);
    }

    public function todayDate(): string
    {
        return $this->calendar->today();
    }

    /**
     * Agenda uma nova consulta com validação de tenant e detecção de conflitos de horário.
     */
    public function schedule(int $patientId, int $professionalId, string $scheduledAt,
        string $type = 'first_visit', int $duration = 60, ?string $notes = null,
        bool $allowConflict = false, ?int $userId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        return $this->atomic(fn() => $this->scheduleAtomically($patientId, $professionalId, $scheduledAt, $type, $duration, $notes, $allowConflict, $userId));
    }

    private function scheduleAtomically(
        int $patientId,
        int $professionalId,
        string $scheduledAt,
        string $type = 'first_visit',
        int $duration = 60,
        ?string $notes = null,
        bool $allowConflict = false,
        ?int $userId = null
    ): array {
        $clinicId = TenantContext::getClinicId();

        $parsedDate = $this->normalizeDateTime($scheduledAt);
        $duration = max(15, min(240, $duration));
        $this->assertAvailability($professionalId,$parsedDate,$duration);

        if (!$allowConflict) {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lockStmt = $this->db->prepare("SELECT id FROM professionals WHERE id = :id FOR UPDATE");
                $lockStmt->execute(['id' => $professionalId]);
            }

            $conflict = $this->findConflict($clinicId, $professionalId, $parsedDate, $duration);
            if ($conflict !== null) {
                if (($conflict['resource_type'] ?? '') === 'hold') {
                    throw new AppointmentConflictException('Este horário está reservado temporariamente para autoagendamento.', $conflict);
                }
                throw new AppointmentConflictException(
                    "Conflito de agenda: o profissional já possui a consulta #{$conflict['id']} com '{$conflict['patient_name']}' às " . date('H:i', strtotime($conflict['scheduled_at'])) . " com duração de {$conflict['duration_minutes']} min.",
                    $conflict
                );
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO appointments (
                clinic_id, patient_id, professional_id, scheduled_at,
                duration_minutes, status, type, notes, created_at, updated_at
            ) VALUES (
                :clinic_id, :patient_id, :professional_id, :scheduled_at,
                :duration, :status, :type, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'scheduled_at' => $parsedDate,
            'duration' => $duration,
            'status' => self::STATUS_SCHEDULED,
            'type' => $type,
            'notes' => $notes,
        ]);

        $id = (int)$this->db->lastInsertId();

        // Registrar trilha de auditoria inicial
        $this->recordStatusHistory(
            $clinicId,
            $id,
            null,
            self::STATUS_SCHEDULED,
            $userId ?? TenantContext::getUserId(),
            'Agendamento inicial criado'
        );
        // NI2-138: consulta marcada com o profissional = acompanhamento ativo; o vínculo libera o chat.
        (new \ClyoNutri\Domain\Chat\PatientLinkService($this->db))->ensureFromCare($clinicId, $patientId, $professionalId, 'appointment');

        (new \ClyoNutri\Domain\Notification\AppointmentConfirmationPublisher($this->db))->publish($clinicId, $id);
        $this->notifyPatientAppointment($clinicId, $patientId, 'appointment.scheduled', $id, $parsedDate, 'scheduled.' . strtotime($parsedDate));

        return [
            'id' => $id,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'scheduled_at' => $parsedDate,
            'duration_minutes' => $duration,
            'status' => self::STATUS_SCHEDULED,
            'type' => $type,
            'notes' => $notes,
        ];
    }

    /**
     * NI2-705: atendimento sem agendamento prévio, iniciado agora a partir do perfil do paciente.
     * Cria a consulta no horário atual e já a coloca em andamento (sem disparar confirmação ao paciente).
     * Idempotente: se o paciente já tem consulta em andamento, devolve essa consulta.
     */
    public function startNow(int $patientId, int $professionalId, ?int $userId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $userId ??= TenantContext::getUserId();
        return $this->atomic(function () use ($clinicId, $patientId, $professionalId, $userId): array {
            $q = $this->db->prepare("SELECT id FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
            $q->execute([$clinicId, $patientId]);
            if ($existing = $q->fetchColumn()) {
                return ['id' => (int)$existing, 'created' => false];
            }
            $tz = new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
            $now = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:00');
            $c = $this->db->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND patient_id = ? AND status = 'completed'");
            $c->execute([$clinicId, $patientId]);
            $type = ((int)$c->fetchColumn()) > 0 ? 'follow_up' : 'first_visit';
            $duration = (int)($this->practicePreferences($professionalId)['default_duration_minutes'] ?? 60);
            $duration = max(15, min(240, $duration ?: 60));
            $note = 'Atendimento iniciado agora pelo perfil do paciente (sem agendamento prévio).';
            $this->db->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
                ->execute([$clinicId, $patientId, $professionalId, $now, $duration, self::STATUS_SCHEDULED, $type, $note]);
            $id = (int)$this->db->lastInsertId();
            $this->recordStatusHistory($clinicId, $id, null, self::STATUS_SCHEDULED, $userId, $note);
            (new \ClyoNutri\Domain\Chat\PatientLinkService($this->db))->ensureFromCare($clinicId, $patientId, $professionalId, 'appointment');
            $this->updateStatusAtomically($id, self::STATUS_IN_PROGRESS, 'Início imediato (atendimento sem agendamento).', $userId);
            return ['id' => $id, 'created' => true];
        });
    }

    /**
     * Edita dados de um agendamento existente (data, horário, duração, notas, tipo).
     */
    public function edit(int $appointmentId, array $data, bool $allowConflict = false, ?int $userId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        $before = $this->getAppointment($appointmentId);
        $res = $this->atomic(fn() => $this->editAtomically($appointmentId, $data, $allowConflict, $userId));
        if (!$this->db->inTransaction() && $before !== null && (string)($before['scheduled_at'] ?? '') !== (string)($res['scheduled_at'] ?? '')) {
            $this->notifyPatientAppointment($clinicId, (int)$res['patient_id'], 'appointment.rescheduled', $appointmentId, (string)$res['scheduled_at'], 'rescheduled.' . strtotime((string)$res['scheduled_at']));
        }
        return $res;
    }

    private function editAtomically(
        int $appointmentId,
        array $data,
        bool $allowConflict = false,
        ?int $userId = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        $current = $this->lockAppointment($appointmentId, $clinicId);
        if (!in_array($current['status'], [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED], true)) {
            throw new InvalidArgumentException('Só é possível editar consultas agendadas ou confirmadas.');
        }

        $patientId = isset($data['patient_id']) ? (int)$data['patient_id'] : (int)$current['patient_id'];
        $professionalId = isset($data['professional_id']) ? (int)$data['professional_id'] : (int)$current['professional_id'];
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $scheduledAt = isset($data['scheduled_at']) ? $this->normalizeDateTime($data['scheduled_at']) : $current['scheduled_at'];
        $duration = isset($data['duration_minutes']) ? max(15, min(240, (int)$data['duration_minutes'])) : (int)($current['duration_minutes'] ?? 60);
        if($scheduledAt!==$current['scheduled_at']||$duration!==(int)$current['duration_minutes']||$professionalId!==(int)$current['professional_id'])$this->assertAvailability($professionalId,$scheduledAt,$duration);
        $type = $data['type'] ?? ($current['type'] ?? 'first_visit');
        $notes = array_key_exists('notes', $data) ? $data['notes'] : ($current['notes'] ?? null);

        if (!$allowConflict) {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lockStmt = $this->db->prepare("SELECT id FROM professionals WHERE id = :id FOR UPDATE");
                $lockStmt->execute(['id' => $professionalId]);
            }

            $conflict = $this->findConflict($clinicId, $professionalId, $scheduledAt, $duration, $appointmentId);
            if ($conflict !== null) {
                if (($conflict['resource_type'] ?? '') === 'hold') {
                    throw new AppointmentConflictException('Este horário está reservado temporariamente para autoagendamento.', $conflict);
                }
                throw new AppointmentConflictException(
                    "Conflito de agenda no reagendamento: o profissional já possui a consulta #{$conflict['id']} com '{$conflict['patient_name']}' às " . date('H:i', strtotime($conflict['scheduled_at'])) . ".",
                    $conflict
                );
            }
        }

        $stmt = $this->db->prepare("
            UPDATE appointments
            SET patient_id = :patient_id,
                professional_id = :professional_id,
                scheduled_at = :scheduled_at,
                duration_minutes = :duration,
                type = :type,
                notes = :notes,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id AND status IN ('scheduled', 'confirmed')
        ");

        $stmt->execute([
            'patient_id' => $patientId,
            'professional_id' => $professionalId,
            'scheduled_at' => $scheduledAt,
            'duration' => $duration,
            'type' => $type,
            'notes' => $notes,
            'id' => $appointmentId,
            'clinic_id' => $clinicId,
        ]);

        $this->recordStatusHistory(
            $clinicId,
            $appointmentId,
            $current['status'],
            $current['status'],
            $userId ?? TenantContext::getUserId(),
            // UX-04 (v14): trilha legível com horário anterior, novo horário e motivo (se informado).
            ($scheduledAt !== $current['scheduled_at']
                ? 'Consulta reagendada de ' . date('d/m/Y H:i', strtotime((string)$current['scheduled_at'])) . ' para ' . date('d/m/Y H:i', strtotime($scheduledAt))
                : "Consulta editada (horário mantido: " . date('d/m/Y H:i', strtotime($scheduledAt)) . ')')
            . (trim((string)($data['reschedule_reason'] ?? '')) !== '' ? '. Motivo: ' . mb_substr(trim((string)$data['reschedule_reason']), 0, 300) : '')
        );

        // Teleconsulta (NI2-236/250 + v11 F04): trocar profissional/paciente ou deixar de ser online encerra a sessão;
        // reagendar (horário/duração) revoga o convite e recalcula a janela.
        if ($professionalId !== (int)$current['professional_id'] || $patientId !== (int)$current['patient_id']) {
            $this->teleconsultation()->onAppointmentCancelled($appointmentId, $clinicId, 'participant_reassigned');
        } elseif (\ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType((string)($current['type'] ?? ''))
            && !\ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType((string)$type)) {
            $this->teleconsultation()->onAppointmentCancelled($appointmentId, $clinicId, 'modality_changed');
        } elseif ($scheduledAt !== $current['scheduled_at'] || $duration !== (int)$current['duration_minutes']) {
            $this->teleconsultation()->onAppointmentRescheduled($appointmentId, $clinicId);
        }

        return $this->getAppointment($appointmentId);
    }

    /**
     * Atualiza o status da consulta segundo a máquina de estados estrita (NI2-82).
     */
    public function updateStatus(int $appointmentId, string $newStatus, ?string $notes = null, ?int $userId = null): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        $before = $this->getAppointment($appointmentId);
        $ok = $this->atomic(fn() => $this->updateStatusAtomically($appointmentId, $newStatus, $notes, $userId));
        if ($ok && !$this->db->inTransaction() && $before !== null && (string)($before['status'] ?? '') !== $newStatus) {
            if ($newStatus === self::STATUS_CANCELLED) {
                $this->notifyPatientAppointment($clinicId, (int)$before['patient_id'], 'appointment.cancelled_by_clinic', $appointmentId, (string)$before['scheduled_at'], 'clinic_cancelled');
            } elseif ($newStatus === self::STATUS_CONFIRMED) {
                $this->notifyPatientAppointment($clinicId, (int)$before['patient_id'], 'appointment.confirmed_by_clinic', $appointmentId, (string)$before['scheduled_at'], 'clinic_confirmed');
            }
        }
        return $ok;
    }

    private function notifyPatientAppointment(int $clinicId, int $patientId, string $eventType, int $appointmentId, string $scheduledAt, string $dedupeSuffix): void
    {
        if ($clinicId <= 0 || $patientId <= 0) {
            return;
        }
        try {
            if (!$this->hasValidatedPatientChannel($clinicId, $patientId)) {
                return;
            }
            $ts = strtotime($scheduledAt);
            if ($ts === false) {
                return;
            }
            (new \ClyoNutri\Domain\Notification\NotificationService($this->db))->publish(
                $clinicId,
                $eventType,
                'patient',
                $patientId,
                'appt.' . $appointmentId . '.' . $dedupeSuffix,
                '/paciente/app',
                ['date' => date('d/m/Y', $ts), 'time' => date('H:i', $ts)]
            );
        } catch (\Throwable $e) {
            error_log('[ClyoNutri][appointment-notify] ' . $eventType . ' falhou: ' . $e->getMessage());
        }
    }

    /**
     * NI2-527: Verifica se o paciente possui canal de contato validado e consentimento ativo antes de disparar mensagem externa.
     */
    private function hasValidatedPatientChannel(int $clinicId, int $patientId): bool
    {
        $st = $this->db->prepare("SELECT email, status FROM patients WHERE id = :id AND clinic_id = :c");
        $st->execute(['id' => $patientId, 'c' => $clinicId]);
        $pat = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pat || ($pat['status'] ?? 'active') !== 'active') {
            return false;
        }
        $email = trim((string)($pat['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }
        if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'patient_push_subscriptions')) {
            $ps = $this->db->prepare("SELECT 1 FROM patient_push_subscriptions WHERE clinic_id = :c AND patient_id = :p AND revoked_at IS NULL LIMIT 1");
            $ps->execute(['c' => $clinicId, 'p' => $patientId]);
            if ($ps->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * NI2-527: Prévia auditável da mensagem de confirmação de consulta (destinatário, canal, fuso horário e texto).
     * v49: profissional sem usuário vinculado não derruba a prévia (LEFT JOIN; o texto sai sem o nome).
     */
    public function previewConfirmationMessage(int $appointmentId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);

        $rows = $this->confirmationPreviewRows($clinicId, [$appointmentId]);
        if (!isset($rows[$appointmentId])) {
            throw new InvalidArgumentException("Consulta #{$appointmentId} não encontrada.");
        }
        return $this->buildConfirmationPreview($rows[$appointmentId]);
    }

    /**
     * v49: prévias de várias consultas da clínica atual com UMA consulta ao banco (a agenda do dia chamava uma por linha).
     * Consultas de outra clínica ou inexistentes simplesmente não aparecem no resultado.
     *
     * @param int[] $appointmentIds
     * @return array<int, array> prévia indexada pelo id da consulta
     */
    public function previewConfirmationMessages(array $appointmentIds): array
    {
        $clinicId = TenantContext::getClinicId();
        $out = [];
        foreach ($this->confirmationPreviewRows($clinicId, $appointmentIds) as $id => $row) {
            $out[$id] = $this->buildConfirmationPreview($row);
        }
        return $out;
    }

    /**
     * @param int[] $appointmentIds
     * @return array<int, array>
     */
    private function confirmationPreviewRows(int $clinicId, array $appointmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $appointmentIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT a.*, p.name AS patient_name, p.email AS patient_email, p.phone AS patient_phone,
                   p.status AS patient_status,
                   u.name AS professional_name, c.name AS clinic_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id AND p.clinic_id = a.clinic_id
            LEFT JOIN professionals prof ON prof.id = a.professional_id
            LEFT JOIN users u ON u.id = prof.user_id
            LEFT JOIN clinics c ON c.id = a.clinic_id
            WHERE a.clinic_id = ? AND a.id IN ({$marks})
        ");
        $stmt->execute(array_merge([$clinicId], $ids));
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['id']] = $row;
        }
        return $rows;
    }

    private function buildConfirmationPreview(array $app): array
    {
        $appointmentId = (int)$app['id'];
        $email = trim((string)($app['patient_email'] ?? ''));
        $phone = trim((string)($app['patient_phone'] ?? ''));
        $emailValid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $patientActive = ($app['patient_status'] ?? 'active') === 'active';
        $statusValid = in_array((string)$app['status'], [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED], true);

        $blockedReason = null;
        if (!$statusValid) {
            $blockedReason = 'Somente consultas agendadas ou confirmadas permitem envio de confirmação.';
        } elseif (!$patientActive) {
            $blockedReason = 'Paciente inativo não recebe comunicações externas.';
        } elseif (!$emailValid) {
            $blockedReason = 'Paciente sem e-mail válido cadastrado. Atualize o cadastro antes de enviar confirmação externa.';
        }

        $timezone = \ClyoNutri\Domain\Notification\AppointmentConfirmationPublisher::timezone();
        $date = new DateTimeImmutable((string)$app['scheduled_at'], new \DateTimeZone($timezone));
        $formattedDate = $date->format('d/m/Y \à\s H:i');
        $eventType = 'appointment.' . ($app['status'] === self::STATUS_CONFIRMED ? 'confirmed' : 'scheduled');
        $template = \ClyoNutri\Domain\Notification\NotificationService::EVENTS[$eventType];
        $body = strtr($template['body'], [
            '{date}' => $date->format('d/m/Y'), '{time}' => $date->format('H:i'),
            '{timezone}' => $timezone,
            '{modality}' => \ClyoNutri\Domain\Telehealth\TeleconsultationService::isOnlineType((string)$app['type']) ? 'online' : 'presencial',
        ]);
        return [
            'appointment_id' => $appointmentId,
            'patient_id' => (int)$app['patient_id'],
            'patient_name' => (string)$app['patient_name'],
            'recipient_email' => $emailValid ? $email : null,
            'recipient_phone' => $phone !== '' ? $phone : null,
            'contact_validated' => $emailValid && $patientActive,
            'channel' => $emailValid ? 'email_e_in_app' : 'indisponivel',
            'timezone' => $timezone,
            'scheduled_at' => (string)$app['scheduled_at'],
            'scheduled_formatted' => "{$formattedDate} ({$timezone})",
            'subject' => $template['title'],
            'body_preview' => $body,
            'can_send' => $blockedReason === null,
            'blocked_reason' => $blockedReason,
        ];
    }

    /**
     * NI2-527: Disparo autorizado e idempotente de confirmação de consulta após revisão da prévia pelo profissional.
     */
    public function sendAuthorizedConfirmation(int $appointmentId, ?int $userId = null): array
    {
        return $this->atomic(function () use ($appointmentId, $userId): array {
            $clinicId = TenantContext::getClinicId();
            $this->lockAppointment($appointmentId, $clinicId);
            $preview = $this->previewConfirmationMessage($appointmentId);
            if (!$preview['can_send']) {
                throw new InvalidArgumentException((string)$preview['blocked_reason']);
            }
            $pub = (new \ClyoNutri\Domain\Notification\AppointmentConfirmationPublisher($this->db))->publish($clinicId, $appointmentId);
            (new \ClyoNutri\Core\Audit\AuditLogger($this->db))->log('APPOINTMENT_CONFIRMATION_AUTHORIZED', 'appointments', (string)$appointmentId, [
                'event_id' => $pub['event_id'],
                'authorized_by_user_id' => $userId ?? TenantContext::getUserId(),
            ]);
            return [
                'sent' => false, // SMTP is handled exclusively by the existing worker.
                'queued' => true,
                'idempotent_reused' => $pub['duplicate'],
                'event_id' => $pub['event_id'],
                'dedupe_key' => $pub['dedupe_key'],
                'preview' => $preview,
            ];
        });
    }

    /**
     * NI2-137: mudança de status pedida pela própria paciente (confirmar ou cancelar pelo app/portal).
     * Mesmo motor da agenda: trava a consulta, aplica ALLOWED_TRANSITIONS, chama os ganchos de teleconsulta
     * e grava o histórico com a paciente como autora. $policy recebe a linha travada e lança
     * PatientAppointmentActionException quando a clínica não permite a ação ou a antecedência não foi respeitada.
     * Consulta de outra paciente ou de outra clínica: "não encontrada" (sem ids na mensagem).
     *
     * @param callable(array): void $policy
     * @return array{from_status: string, to_status: string, appointment: array}
     */
    public function transitionByPatient(int $appointmentId, int $patientId, string $newStatus, string $notes, callable $policy): array
    {
        $clinicId = TenantContext::getClinicId();
        return $this->atomic(function () use ($appointmentId, $patientId, $newStatus, $notes, $policy, $clinicId): array {
            $current = $this->lockAppointment($appointmentId, $clinicId);
            if ($current === null || (int)$current['patient_id'] !== $patientId) {
                throw PatientAppointmentActionException::notFound();
            }
            $policy($current);
            $from = (string)$current['status'];
            if ($from === $newStatus || !in_array($newStatus, self::ALLOWED_TRANSITIONS[$from] ?? [], true)) {
                throw PatientAppointmentActionException::invalidState(
                    'Esta consulta está como "' . \ClyoNutri\Core\I18n\LabelMap::appointmentStatus($from) . '" e a ação não está mais disponível. Atualize a tela.',
                    $from
                );
            }
            $this->updateStatusAtomically($appointmentId, $newStatus, $notes, null, $patientId);
            if (in_array($newStatus,[self::STATUS_CONFIRMED,self::STATUS_CANCELLED],true)) (new \ClyoNutri\Domain\Booking\BookingFlowMetrics($this->db))->count($clinicId,$newStatus===self::STATUS_CONFIRMED?'patient_confirmed':'patient_cancelled');
            return ['from_status' => $from, 'to_status' => $newStatus, 'appointment' => $current];
        });
    }

    /**
     * NI2-137: o profissional aceita o pedido de remarcação da paciente. Usa o mesmo caminho do reagendamento
     * da agenda (disponibilidade semanal, conflito, trava do profissional e convite de teleconsulta revogado
     * com a janela recalculada). Se a consulta estava confirmada, volta para "agendada": a paciente confirmou o
     * horário antigo, não o novo.
     */
    public function rescheduleFromPatientRequest(int $appointmentId, string $newScheduledAt, ?int $userId, string $note): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        return $this->atomic(function () use ($appointmentId, $newScheduledAt, $userId, $note, $clinicId): array {
            $current = $this->lockAppointment($appointmentId, $clinicId);
            if ($current === null || !in_array($current['status'], [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED], true)) {
                throw new InvalidArgumentException('Só é possível remarcar consultas agendadas ou confirmadas.');
            }
            $this->editAtomically($appointmentId, ['scheduled_at' => $newScheduledAt, 'reschedule_reason' => $note], false, $userId);
            if ($current['status'] === self::STATUS_CONFIRMED) {
                $stmt = $this->db->prepare("UPDATE appointments SET status = 'scheduled', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :c AND status = 'confirmed'");
                $stmt->execute(['id' => $appointmentId, 'c' => $clinicId]);
                if ($stmt->rowCount() === 1) {
                    $this->recordStatusHistory($clinicId, $appointmentId, self::STATUS_CONFIRMED, self::STATUS_SCHEDULED, $userId,
                        'Confirmação anterior desfeita pela remarcação: a paciente precisa confirmar o novo horário.');
                }
            }
            return $this->getAppointment($appointmentId);
        });
    }

    private function updateStatusAtomically(
        int $appointmentId,
        string $newStatus,
        ?string $notes = null,
        ?int $userId = null,
        ?int $patientId = null
    ): bool {
        $clinicId = TenantContext::getClinicId();
        $current = $this->lockAppointment($appointmentId, $clinicId);

        if (!in_array($newStatus, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Status de consulta inválido: '{$newStatus}'. Valores aceitos: " . implode(', ', self::VALID_STATUSES));
        }

        $currentStatus = (string)$current['status'];

        if ($currentStatus === $newStatus) {
            return true; // Sem alteração de estado
        }

        $allowedNext = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowedNext, true)) {
            throw new InvalidAppointmentTransitionException(
                $currentStatus,
                $newStatus,
                "Transição de status inválida: a consulta #{$appointmentId} está como '{$currentStatus}' e não pode transicionar para '{$newStatus}'."
            );
        }

        $stmt = $this->db->prepare("
            UPDATE appointments
            SET status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id AND status = :previous_status
        ");

        $success = $stmt->execute([
            'status' => $newStatus,
            'previous_status' => $currentStatus,
            'id' => $appointmentId,
            'clinic_id' => $clinicId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new InvalidArgumentException('A consulta foi alterada por outro atendimento. Recarregue a página.');
        }

        if ($success && in_array($newStatus, [self::STATUS_CANCELLED, self::STATUS_NO_SHOW, self::STATUS_COMPLETED], true)) {
            // Teleconsulta (NI2-236 + v11 F04): cancelar, falta ou concluir revoga o acesso e libera a sala.
            $this->teleconsultation()->onAppointmentCancelled($appointmentId, $clinicId, $newStatus);
        }

        if ($success) {
            $this->recordStatusHistory(
                $clinicId,
                $appointmentId,
                $currentStatus,
                $newStatus,
                $patientId !== null ? null : ($userId ?? TenantContext::getUserId()),
                $notes,
                $patientId
            );
        }

        if ($success && $newStatus === self::STATUS_CONFIRMED) {
            (new \ClyoNutri\Domain\Notification\AppointmentConfirmationPublisher($this->db))->publish($clinicId, $appointmentId);
        }
        return $success;
    }

    /**
     * Conclui a consulta médica integrando com prontuário clínico e hash SHA-256 (NI2-84).
     */
    public function completeConsultation(
        int $appointmentId,
        string $evolutionSummary,
        ?array $clinicalDetails = null,
        ?int $userId = null,
        ?int $authorProfessionalId = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        $appointment = $this->getAppointment($appointmentId);
        if (!$appointment) {
            throw new InvalidArgumentException("Consulta #{$appointmentId} não encontrada.");
        }

        if (empty(trim($evolutionSummary))) {
            throw new InvalidArgumentException("O resumo da evolução clínica é obrigatório para concluir o atendimento.");
        }

        // NI2-220 (B1): a evolução é de quem conclui, com CRN ativo nesta clínica. Nunca cai no profissional
        // agendado por padrão (antes, uma administradora sem CRN gravava em nome da nutricionista da agenda).
        $actor = $userId ?? (int)TenantContext::getUserId();
        $author = \ClyoNutri\Domain\Clinical\ClinicalAuthor::resolve($this->db, (int)$clinicId, (int)$actor);
        if ($author === null || ($authorProfessionalId !== null && $authorProfessionalId !== $author)) {
            throw new \DomainException(\ClyoNutri\Domain\Clinical\ClinicalAuthor::SEM_CRN_MSG);
        }
        // NI2-220 (B3): medidas fora da faixa são recusadas antes do registro imutável, sem cortar o valor.
        $clinicalDetails = self::validatedConsultationDetails($clinicalDetails ?? []);

        $ownsTx = !$this->db->inTransaction();
        if ($ownsTx) {
            $this->db->beginTransaction();
        } else {
            $this->db->exec('SAVEPOINT sp_complete_consult');
        }

        try {
            $appointment = $this->lockAppointment($appointmentId, $clinicId);
            if (!$appointment || !in_array($appointment['status'], [self::STATUS_SCHEDULED, self::STATUS_CONFIRMED, self::STATUS_IN_PROGRESS], true)) {
                throw new InvalidArgumentException('Esta consulta já foi encerrada. Use a retificação do prontuário para corrigir uma evolução.');
            }

            // Se estiver em 'scheduled' ou 'confirmed', avança primeiro para in_progress se necessário
            if ($appointment['status'] === self::STATUS_SCHEDULED || $appointment['status'] === self::STATUS_CONFIRMED) {
                $this->updateStatus($appointmentId, self::STATUS_IN_PROGRESS, 'Início do atendimento clínico', $userId);
            }

            // Append no Prontuário com SHA-256 e trilha criptográfica
            $clinicalService = new ClinicalRecordService($this->db);
            $recordTitle = "Evolução Clínica — Consulta #" . $appointmentId . " (" . date('d/m/Y', strtotime($appointment['scheduled_at'])) . ")";
            $recordContent = array_merge([
                'appointment_id' => $appointmentId,
                'scheduled_at' => $appointment['scheduled_at'],
                'type' => $appointment['type'],
                'evolution_summary' => $evolutionSummary,
                'concluded_at' => date('Y-m-d H:i:s'),
                'recorded_by' => $author,
            ], (int)$appointment['professional_id'] !== $author ? ['scheduled_professional_id' => (int)$appointment['professional_id']] : [], $clinicalDetails);

            $record = $clinicalService->appendRecord(
                (int)$appointment['patient_id'],
                $author,
                $recordTitle,
                $recordContent
            );

            // Atualiza status final para completed somente após o registro clínico estar persistido
            $this->updateStatus($appointmentId, self::STATUS_COMPLETED, 'Atendimento clínico concluído com registro no prontuário', $userId);

            if ($ownsTx) {
                $this->db->commit();
            } else {
                $this->db->exec('RELEASE SAVEPOINT sp_complete_consult');
            }

            return [
                'appointment_id' => $appointmentId,
                'status' => self::STATUS_COMPLETED,
                'clinical_record_id' => $record['id'],
                'record_hash' => $record['record_hash'],
                'version' => $record['version'],
            ];
        } catch (\Throwable $e) {
            if ($ownsTx) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } else {
                try {
                    $this->db->exec('ROLLBACK TO SAVEPOINT sp_complete_consult');
                    $this->db->exec('RELEASE SAVEPOINT sp_complete_consult');
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    /** Faixas plausíveis das medidas aceitas na conclusão da consulta (as mesmas da avaliação antropométrica). */
    public const CONSULT_WEIGHT_RANGE = [10.0, 400.0];
    public const CONSULT_WAIST_RANGE = [10.0, 250.0];

    /**
     * NI2-220 (B3): valida peso e cintura informados na conclusão. Vazio é "não aferido"; fora da faixa ou não
     * numérico gera erro por campo (ClinicalInputException), nunca valor cortado.
     *
     * @return array{weight_kg?: float, waist_cm?: float}
     */
    public static function validatedConsultationDetails(array $details): array
    {
        $errors = [];
        $out = [];
        $w = \ClyoNutri\Domain\Clinical\ClinicalInputException::number($details['weight_kg'] ?? null, 'weight_kg', 'Peso atual', self::CONSULT_WEIGHT_RANGE[0], self::CONSULT_WEIGHT_RANGE[1], 'kg', $errors);
        $c = \ClyoNutri\Domain\Clinical\ClinicalInputException::number($details['waist_cm'] ?? null, 'waist_cm', 'Circunferência da cintura', self::CONSULT_WAIST_RANGE[0], self::CONSULT_WAIST_RANGE[1], 'cm', $errors);
        if ($errors) {
            throw new \ClyoNutri\Domain\Clinical\ClinicalInputException($errors);
        }
        if ($w !== null) {
            $out['weight_kg'] = $w;
        }
        if ($c !== null) {
            $out['waist_cm'] = $c;
        }
        return $out;
    }

    private function atomic(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        $savepoint = 'appointment_' . bin2hex(random_bytes(8));
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        } else {
            $this->db->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->db->prepare('SELECT clinic_id FROM booking_settings WHERE clinic_id=? FOR UPDATE')->execute([TenantContext::getClinicId()]);
            }
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            } else {
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            } elseif (!$ownsTransaction && $this->db->inTransaction()) {
                $this->db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
    }

    private function lockAppointment(int $appointmentId, int $clinicId): ?array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id = ? AND clinic_id = ? FOR UPDATE');
        } else {
            // SQLite serializes writers; acquire the write lock before reading state.
            $stmt = $this->db->prepare('UPDATE appointments SET id = id WHERE id = ? AND clinic_id = ?');
        }
        $stmt->execute([$appointmentId, $clinicId]);
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id = ? AND clinic_id = ?');
            $stmt->execute([$appointmentId, $clinicId]);
        }
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function teleconsultation(): \ClyoNutri\Domain\Telehealth\TeleconsultationService
    {
        return new \ClyoNutri\Domain\Telehealth\TeleconsultationService($this->db);
    }

    /**
     * Retorna os detalhes completos da consulta.
     */
    public function getAppointment(int $appointmentId): ?array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT a.*, 
                   p.name AS patient_name, p.phone AS patient_phone, p.email AS patient_email, p.birth_date AS patient_birth_date,
                   u.name AS professional_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN professionals prof ON prof.id = a.professional_id
            JOIN users u ON u.id = prof.user_id
            WHERE a.id = :id AND a.clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $appointmentId, 'clinic_id' => $clinicId]);
        $app = $stmt->fetch();

        if (!$app) {
            return null;
        }

        $app['history'] = $this->getStatusHistory($appointmentId);
        return $app;
    }

    /** UX-07 (v14): minutos de antecedência a partir dos quais iniciar exige confirmação. */
    public const EARLY_START_TOLERANCE_MINUTES = 15;

    public static function isEarlyStart(string $scheduledAt, ?int $now = null): bool
    {
        $ts = strtotime($scheduledAt);
        return $ts !== false && $ts - ($now ?? time()) > self::EARLY_START_TOLERANCE_MINUTES * 60;
    }

    public static function humanUntil(string $scheduledAt, ?int $now = null): string
    {
        $min = max(0, (int)ceil((strtotime($scheduledAt) - ($now ?? time())) / 60));
        if ($min >= 1440) {
            $d = intdiv($min, 1440);
            return $d . ($d === 1 ? ' dia' : ' dias');
        }
        $h = intdiv($min, 60); $m = $min % 60;
        return $h > 0 ? sprintf('%d h %02d min', $h, $m) : $min . ' min';
    }

    /**
     * UX-07 (v14): horários distintos de agendamento, início real e conclusão, a partir da trilha.
     * @return array{scheduled_at: ?string, started_at: ?string, completed_at: ?string}
     */
    public static function executionTimeline(array $app): array
    {
        $started = null; $completed = null;
        foreach ($app['history'] ?? [] as $h) {
            if (($h['to_status'] ?? '') === self::STATUS_IN_PROGRESS && ($h['from_status'] ?? '') !== self::STATUS_IN_PROGRESS) {
                $started = $started ?? ($h['created_at'] ?? null);
            }
            if (($h['to_status'] ?? '') === self::STATUS_COMPLETED) {
                $completed = $h['created_at'] ?? null;
            }
        }
        return ['scheduled_at' => $app['scheduled_at'] ?? null, 'started_at' => $started, 'completed_at' => $completed];
    }

    /**
     * Retorna o histórico de auditoria de transições de status da consulta (NI2-82).
     */
    public function getStatusHistory(int $appointmentId): array
    {
        $clinicId = TenantContext::getClinicId();
        // NI2-137: mudança feita pela paciente no app aparece como "Paciente (app)".
        $byPatient = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'appointment_status_history', 'changed_by_patient_id')
            ? "COALESCE(u.name, CASE WHEN h.changed_by_patient_id IS NOT NULL THEN 'Paciente (app)' END)"
            : 'u.name';
        $stmt = $this->db->prepare("
            SELECT h.*, {$byPatient} AS changed_by_name
            FROM appointment_status_history h
            LEFT JOIN users u ON u.id = h.changed_by_user_id
            WHERE h.appointment_id = :app_id AND h.clinic_id = :clinic_id
            ORDER BY h.id ASC
        ");
        $stmt->execute(['app_id' => $appointmentId, 'clinic_id' => $clinicId]);
        return $stmt->fetchAll();
    }

    /**
     * Lista consultas de um dia específico com filtros e ordenação horária (NI2-83).
     */
    /**
     * NI2-418 (UX-06): próximo passo de atendimento do paciente, somente leitura e escopo de clínica.
     * Ordem: consulta em andamento (continuar) > consulta de hoje agendada/confirmada (iniciar, com POST) >
     * próxima consulta futura > nenhuma (agendar). Não altera estado.
     * @return array{state:string,appointment:?array}
     */
    public function careStepForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        $base = "SELECT id, patient_id, scheduled_at, status, type, duration_minutes FROM appointments WHERE clinic_id = :c AND patient_id = :p";
        $q = $this->db->prepare($base . " AND status = 'in_progress' ORDER BY scheduled_at DESC, id DESC LIMIT 1");
        $q->execute(['c' => $clinicId, 'p' => $patientId]);
        if ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
            return ['state' => 'continue', 'appointment' => $row];
        }
        $day = $this->calendar->day($this->todayDate());
        $q = $this->db->prepare($base . " AND status IN ('scheduled','confirmed') AND scheduled_at >= :s AND scheduled_at < :e ORDER BY scheduled_at ASC, id ASC LIMIT 1");
        $q->execute(['c' => $clinicId, 'p' => $patientId, 's' => $day['start'], 'e' => $day['end']]);
        if ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
            return ['state' => 'start_today', 'appointment' => $row];
        }
        $q = $this->db->prepare($base . " AND status IN ('scheduled','confirmed') AND scheduled_at >= :e ORDER BY scheduled_at ASC, id ASC LIMIT 1");
        $q->execute(['c' => $clinicId, 'p' => $patientId, 'e' => $day['end']]);
        if ($row = $q->fetch(\PDO::FETCH_ASSOC)) {
            return ['state' => 'upcoming', 'appointment' => $row];
        }
        return ['state' => 'none', 'appointment' => null];
    }

    public function listByDay(string $date, ?int $professionalId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        $window = $this->calendar->day($date);

        $sql = "
            SELECT a.*, 
                   p.name AS patient_name, p.phone AS patient_phone, p.email AS patient_email,
                   u.name AS professional_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN professionals prof ON prof.id = a.professional_id
            JOIN users u ON u.id = prof.user_id
            WHERE a.clinic_id = :clinic_id
              AND a.scheduled_at >= :day_start AND a.scheduled_at < :day_end
        ";
        $params = ['clinic_id' => $clinicId, 'day_start' => $window['start'], 'day_end' => $window['end']];

        if ($professionalId !== null) {
            $sql .= " AND a.professional_id = :prof_id";
            $params['prof_id'] = $professionalId;
        }

        $sql .= " ORDER BY a.scheduled_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Lista consultas de uma semana com agrupamento diário (NI2-83).
     */
    public function listByWeek(string $anchorDate, ?int $professionalId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        $dt = new DateTimeImmutable($anchorDate);
        // Início na segunda-feira da semana
        $dayOfWeek = (int)$dt->format('N'); // 1 (Seg) a 7 (Dom)
        $monday = $dt->modify('-' . ($dayOfWeek - 1) . ' days');
        $sunday = $monday->modify('+6 days');

        $startDate = $monday->format('Y-m-d 00:00:00');
        $endDate = $sunday->format('Y-m-d 23:59:59');

        $sql = "
            SELECT a.*, 
                   p.name AS patient_name, p.phone AS patient_phone,
                   u.name AS professional_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN professionals prof ON prof.id = a.professional_id
            JOIN users u ON u.id = prof.user_id
            WHERE a.clinic_id = :clinic_id
              AND a.scheduled_at >= :start_date
              AND a.scheduled_at <= :end_date
        ";
        $params = [
            'clinic_id' => $clinicId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        if ($professionalId !== null) {
            $sql .= " AND a.professional_id = :prof_id";
            $params['prof_id'] = $professionalId;
        }

        $sql .= " ORDER BY a.scheduled_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $all = $stmt->fetchAll();

        // Estruturar dias da semana
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $currentDay = $monday->modify("+{$i} days")->format('Y-m-d');
            $days[$currentDay] = [];
        }

        foreach ($all as $item) {
            $dayKey = date('Y-m-d', strtotime($item['scheduled_at']));
            if (isset($days[$dayKey])) {
                $days[$dayKey][] = $item;
            }
        }

        return [
            'start_date' => $monday->format('Y-m-d'),
            'end_date' => $sunday->format('Y-m-d'),
            'days' => $days,
            'total_appointments' => count($all),
        ];
    }

    /**
     * NI2-527: Lista consultas de um mês com agrupamento diário.
     */
    public function listByMonth(string $anchorDate, ?int $professionalId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        $dt = new DateTimeImmutable($anchorDate);
        $firstDay = $dt->modify('first day of this month');
        $lastDay = $dt->modify('last day of this month');

        $startDate = $firstDay->format('Y-m-d 00:00:00');
        $endDate = $lastDay->format('Y-m-d 23:59:59');

        $sql = "
            SELECT a.*, 
                   p.name AS patient_name, p.phone AS patient_phone, p.email AS patient_email,
                   u.name AS professional_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN professionals prof ON prof.id = a.professional_id
            JOIN users u ON u.id = prof.user_id
            WHERE a.clinic_id = :clinic_id
              AND a.scheduled_at >= :start_date
              AND a.scheduled_at <= :end_date
        ";
        $params = [
            'clinic_id' => $clinicId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        if ($professionalId !== null) {
            $sql .= " AND a.professional_id = :prof_id";
            $params['prof_id'] = $professionalId;
        }

        $sql .= " ORDER BY a.scheduled_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $all = $stmt->fetchAll();

        $days = [];
        $totalDays = (int)$lastDay->format('j');
        for ($d = 1; $d <= $totalDays; $d++) {
            $currentDay = $firstDay->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
            $days[$currentDay] = [];
        }

        foreach ($all as $item) {
            $dayKey = date('Y-m-d', strtotime($item['scheduled_at']));
            if (isset($days[$dayKey])) {
                $days[$dayKey][] = $item;
            }
        }

        return [
            'start_date' => $firstDay->format('Y-m-d'),
            'end_date' => $lastDay->format('Y-m-d'),
            'days' => $days,
            'total_appointments' => count($all),
        ];
    }

    /**
     * Retorna itens prioritários que exigem atenção clínica do nutricionista (NI2-85).
     * Substitui qualquer placeholder por dados reais calculados do banco de dados.
     */
    public function getAttentionRequired(?string $date = null): array
    {
        $clinicId = TenantContext::getClinicId();
        $window = $this->calendar->day($date ?? $this->todayDate());
        $items = [];

        // 1. Consultas para hoje ainda sem confirmação
        $stmtToday = $this->db->prepare("
            SELECT a.id, a.scheduled_at, p.name AS patient_name, p.phone AS patient_phone
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id
              AND a.scheduled_at >= :day_start AND a.scheduled_at < :day_end
              AND a.status = 'scheduled'
            ORDER BY a.scheduled_at ASC
        ");
        $stmtToday->execute(['clinic_id' => $clinicId, 'day_start' => $window['start'], 'day_end' => $window['end']]);
        while ($row = $stmtToday->fetch()) {
            $items[] = [
                'type' => 'unconfirmed_today',
                'severity' => 'warning',
                'title' => "Consulta não confirmada para hoje",
                'description' => "{$row['patient_name']} às " . date('H:i', strtotime($row['scheduled_at'])),
                'action_url' => "/agenda?id={$row['id']}",
                'action_label' => "Ver na agenda",
            ];
        }

        // 2. Pacientes com no-show recente nos últimos 14 dias sem remarcação
        $stmtNoShow = $this->db->prepare("
            SELECT a.id, a.scheduled_at, p.id AS patient_id, p.name AS patient_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id
              AND a.status = 'no_show'
              AND a.scheduled_at >= :follow_up_start
            ORDER BY a.scheduled_at DESC
        ");
        $stmtNoShow->execute(['clinic_id' => $clinicId, 'follow_up_start' => (new DateTimeImmutable($window['start']))->modify('-14 days')->format('Y-m-d H:i:s')]);
        while ($row = $stmtNoShow->fetch()) {
            $items[] = [
                'type' => 'no_show_follow_up',
                'severity' => 'danger',
                'title' => "Não compareceu",
                'description' => "{$row['patient_name']} não compareceu à consulta em " . date('d/m', strtotime($row['scheduled_at'])),
                'action_url' => "/pacientes/{$row['patient_id']}",
                'action_label' => "Remarcar consulta",
            ];
        }

        // NI2-137: pedidos de remarcação da paciente aguardando resposta.
        $pendingRequests = (new PatientAppointmentService($this->db))->countPendingForClinic();
        if ($pendingRequests > 0) {
            $items[] = [
                'type' => 'reschedule_request',
                'severity' => 'warning',
                'title' => $pendingRequests === 1 ? 'Pedido de remarcação' : 'Pedidos de remarcação',
                'description' => $pendingRequests === 1 ? '1 paciente pediu outro horário pelo app' : "{$pendingRequests} pacientes pediram outro horário pelo app",
                'action_url' => '/agenda/pedidos-remarcacao',
                'action_label' => 'Responder',
            ];
        }

        // 3. Prescrições em rascunho, agrupadas por paciente (mesma fonte do card do Dashboard)
        $drafts = new \ClyoNutri\Domain\MealPlan\DraftPlanSummary($this->db);
        foreach ($drafts->byPatient($clinicId) as $group) {
            $n = $group['draft_count'];
            $items[] = [
                'type' => 'draft_plan',
                'severity' => 'info',
                'title' => 'Prescrição em rascunho',
                'description' => $n === 1
                    ? "{$group['patient_name']}: 1 plano em rascunho aguarda publicação"
                    : "{$group['patient_name']}: {$n} planos em rascunho aguardam revisão",
                'patient_id' => $group['patient_id'],
                'draft_count' => $n,
                'action_url' => \ClyoNutri\Domain\MealPlan\DraftPlanSummary::reviewUrl($group),
                'action_label' => $n === 1 ? 'Revisar plano' : 'Revisar planos',
            ];
        }

        return $items;
    }

    /**
     * Retorna métricas consolidadas em tempo real do dia atual para o Dashboard (NI2-85).
     */
    public function getTodayStats(?string $date = null): array
    {
        $clinicId = TenantContext::getClinicId();
        $window = $this->calendar->day($date ?? $this->todayDate());
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) AS total_today,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS count_scheduled,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS count_confirmed,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS count_in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS count_completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS count_cancelled,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS count_no_show
            FROM appointments
            WHERE clinic_id = :clinic_id
              AND scheduled_at >= :day_start AND scheduled_at < :day_end
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'day_start' => $window['start'], 'day_end' => $window['end']]);
        $row = $stmt->fetch() ?: [];

        return [
            'total_today' => (int)($row['total_today'] ?? 0),
            'scheduled' => (int)($row['count_scheduled'] ?? 0),
            'confirmed' => (int)($row['count_confirmed'] ?? 0),
            'in_progress' => (int)($row['count_in_progress'] ?? 0),
            'completed' => (int)($row['count_completed'] ?? 0),
            'cancelled' => (int)($row['count_cancelled'] ?? 0),
            'no_show' => (int)($row['count_no_show'] ?? 0),
        ];
    }

    /** NI2-567: advisory preview; never books, locks capacity, or returns patient details. */
    public function availabilityPreview(int $professionalId,string $date,int $duration,?string $time=null): array
    {
        $clinic=TenantContext::getClinicId();
        TenantGuard::assertProfessionalBelongsToClinic($professionalId,$clinic,$this->db);
        $calendar=new \ClyoNutri\Domain\Booking\BookingCalendarService($this->db);
        $calendar::date($date);
        if($duration<15 || $duration>240)throw new InvalidArgumentException('Duração inválida. Use de 15 a 240 minutos.');
        $prefs=$this->practicePreferences($professionalId);
        $windows=$calendar->windows($clinic,$professionalId,$date,$prefs['weekly_availability']??[]);
        $slots=(new \ClyoNutri\Domain\Booking\BookingService($this->db))->getAvailableSlots($clinic,$professionalId,$date,$duration,'presential',null,false,null,true);
        $state=$slots?'available':'full';$message=$slots?'Há horários disponíveis para a duração selecionada.':'Sem horários livres para a duração selecionada, considerando ocupações e intervalos.';
        if(!$windows){
            $state='no_schedule';$message='Sem jornada configurada para esta data.';
            foreach($calendar->rules($clinic,$date) as $rule){
                if($rule['professional_id']!==null && (int)$rule['professional_id']!==$professionalId)continue;
                if($rule['kind']==='holiday'){$state='holiday';$message='Feriado: não há período de atendimento disponível nesta data.';break;}
                if($rule['kind']==='closed'){$state='blocked';$message='Agenda bloqueada nesta data.';}
            }
        }
        if($date<$this->todayDate() && $windows){$state='past';$message='Data passada. Nenhum horário futuro é oferecido.';}
        $selected=null;$reason=null;$canOverride=false;
        if($time!==null && $time!==''){
            $calendar::time($time);$at=$date.' '.$time.':00';
            try{$this->assertAvailability($professionalId,$at,$duration);$selected=true;}
            catch(InvalidArgumentException $e){$selected=false;$reason=$e->getMessage();}
            if($selected && ($conflict=$this->findConflict($clinic,$professionalId,$at,$duration,null,false))!==null){
                $selected=false;$canOverride=($conflict['resource_type']??'')!=='hold';
                $reason=$canOverride?'Horário ocupado ou em intervalo entre consultas. Escolha outro horário ou confirme um encaixe autorizado.':'Horário reservado temporariamente no autoagendamento.';
            }
        }
        return ['state'=>$state,'message'=>$message,'times'=>array_column($slots,'time'),'selected_available'=>$selected,'selected_reason'=>$reason,'can_override_conflict'=>$canOverride];
    }

    /**
     * NI2-849 (UX-005): sugere horário para retorno (+30 dias no mesmo horário da consulta concluída).
     * Se cair fora do expediente, em bloqueio/feriado ou em conflito de agenda, avança automaticamente
     * para o horário válido mais próximo e explica o motivo.
     *
     * @return array{scheduled_at:string, requested_at:string, adjusted:bool, reason:?string, alternatives:list<string>}
     */
    public function suggestFollowUpSlot(int $professionalId, string $baseScheduledAt, int $duration = 60, int $daysAhead = 30): array
    {
        $duration = max(15, min(240, $duration ?: 60));
        $tz = new \DateTimeZone(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
        try {
            $baseDt = new DateTimeImmutable($baseScheduledAt !== '' ? $baseScheduledAt : 'now', $tz);
        } catch (\Throwable) {
            $baseDt = new DateTimeImmutable('now', $tz);
        }
        $nowDt = new DateTimeImmutable('now', $tz);
        if ($baseDt < $nowDt) {
            $baseDt = $nowDt->setTime((int)$baseDt->format('H'), (int)$baseDt->format('i'));
        }
        $targetDt = $baseDt->modify("+{$daysAhead} days");
        $targetDate = $targetDt->format('Y-m-d');
        $prefTime = $targetDt->format('H:i');
        if ($prefTime === '00:00') {
            $prefTime = '09:00';
        }
        $requestedIso = $targetDate . 'T' . $prefTime;

        try {
            $initial = $this->availabilityPreview($professionalId, $targetDate, $duration, $prefTime);
            if ($initial['selected_available'] === true) {
                $alts = [];
                foreach ($initial['times'] ?? [] as $t) {
                    if ($t !== $prefTime) {
                        $alts[] = $targetDate . 'T' . $t;
                        if (count($alts) >= 3) {
                            break;
                        }
                    }
                }
                return [
                    'scheduled_at' => $requestedIso,
                    'requested_at' => $requestedIso,
                    'adjusted' => false,
                    'reason' => null,
                    'alternatives' => $alts,
                ];
            }

            $reason = $initial['selected_reason'] ?? $initial['message'] ?? 'Horário indisponível na data inicial (+30 dias).';
            $chosen = null;
            $alternatives = [];

            for ($offset = 0; $offset <= 21; $offset++) {
                $candDate = $targetDt->modify("+{$offset} days")->format('Y-m-d');
                $candPrev = $this->availabilityPreview($professionalId, $candDate, $duration, $prefTime);
                if ($candPrev['selected_available'] === true) {
                    $slotIso = $candDate . 'T' . $prefTime;
                    if ($chosen === null) {
                        $chosen = $slotIso;
                    } elseif (count($alternatives) < 3) {
                        $alternatives[] = $slotIso;
                    }
                }
                foreach ($candPrev['times'] ?? [] as $t) {
                    $slotIso = $candDate . 'T' . $t;
                    if ($chosen === null) {
                        $chosen = $slotIso;
                    } elseif ($slotIso !== $chosen && !in_array($slotIso, $alternatives, true) && count($alternatives) < 3) {
                        $alternatives[] = $slotIso;
                    }
                }
                if ($chosen !== null && count($alternatives) >= 3) {
                    break;
                }
            }

            return [
                'scheduled_at' => $chosen ?? $requestedIso,
                'requested_at' => $requestedIso,
                'adjusted' => $chosen !== null && $chosen !== $requestedIso,
                'reason' => $reason,
                'alternatives' => $alternatives,
            ];
        } catch (\Throwable) {
            return [
                'scheduled_at' => $requestedIso,
                'requested_at' => $requestedIso,
                'adjusted' => false,
                'reason' => null,
                'alternatives' => [],
            ];
        }
    }

    /**
     * Saved preferences for the professional in the current clinic.
     * NI2-584: leitura pura. Contas antigas (só working_days) NÃO são convertidas aqui: o horário sugerido
     * (08–18) só vale depois que a nutricionista confirma em Configurações.
     */
    public function practicePreferences(int $professionalId): array
    {
        $stmt=$this->db->prepare('SELECT op.practice_preferences FROM onboarding_progress op JOIN professionals p ON p.user_id=op.user_id WHERE op.clinic_id=? AND p.id=?');
        $stmt->execute([TenantContext::getClinicId(),$professionalId]);return json_decode((string)($stmt->fetchColumn()?:'{}'),true)?:[];
    }
    private function assertAvailability(int $professionalId, string $scheduledAt, int $duration): void
    {
        $prefs = $this->practicePreferences($professionalId);
        $calendar = new \ClyoNutri\Domain\Booking\BookingCalendarService($this->db);
        if (!$calendar->allows(TenantContext::getClinicId(), $professionalId, $scheduledAt, $duration, $prefs['weekly_availability'] ?? [], array_key_exists('weekly_availability', $prefs))) {
            throw new InvalidArgumentException($calendar->blockingReason(TenantContext::getClinicId(), $professionalId, $scheduledAt, $duration) ?? 'Esse horário não respeita a jornada ou os intervalos entre consultas. O encaixe vale só dentro da jornada; para atender fora dela, ajuste a disponibilidade em Configurações.');
        }
    }

    /** Current clinic week, Monday inclusive through next Monday exclusive. */
    public function getWeekStats(?string $date = null): array
    {
        $day = $this->calendar->day($date ?? $this->todayDate());
        $anchor = new DateTimeImmutable($day['start']);
        $start = $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' days');
        $end = $start->modify('+7 days');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM appointments
            WHERE clinic_id = :clinic_id AND scheduled_at >= :start AND scheduled_at < :end
            AND status <> 'cancelled'");
        $stmt->execute(['clinic_id'=>TenantContext::getClinicId(), 'start'=>$start->format('Y-m-d H:i:s'), 'end'=>$end->format('Y-m-d H:i:s')]);
        return ['total'=>(int)$stmt->fetchColumn(), 'start'=>$start->format('d/m'), 'end'=>$end->modify('-1 day')->format('d/m')];
    }

    /**
     * Detecção de conflito de horário entre consultas no mesmo profissional.
     */
    public function findConflict(
        int $clinicId,
        int $professionalId,
        string $scheduledAt,
        int $durationMinutes,
        ?int $excludeAppointmentId = null,
        bool $lockForMutation = true
    ): ?array {
        $newStart = strtotime($scheduledAt);
        $newEnd = $newStart + ($durationMinutes * 60);

        $calendar = new \ClyoNutri\Domain\Booking\BookingCalendarService($this->db);
        $policy = $calendar->policy($clinicId);
        $padding = ((int)$policy['buffer_before_minutes'] + (int)$policy['buffer_after_minutes']) * 60;

        $endExpression = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'DATE_ADD(a.scheduled_at, INTERVAL a.duration_minutes MINUTE)'
            : "datetime(a.scheduled_at, '+' || a.duration_minutes || ' minutes')";

        $sql = "
            SELECT a.id, a.scheduled_at, a.duration_minutes, a.status, p.name AS patient_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id
              AND a.professional_id = :prof_id
              AND a.scheduled_at < :new_end
              AND {$endExpression} > :new_start
              AND a.status NOT IN ('cancelled', 'no_show')
        ";
        $params = [
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'new_start' => date('Y-m-d H:i:s', $newStart - $padding),
            'new_end' => date('Y-m-d H:i:s', $newEnd + $padding),
        ];

        if ($excludeAppointmentId !== null) {
            $sql .= " AND a.id != :exclude_id";
            $params['exclude_id'] = $excludeAppointmentId;
        }

        if ($lockForMutation && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $cand) {
            if ($calendar->overlaps($clinicId, $scheduledAt, $durationMinutes, $cand['scheduled_at'], (int)$cand['duration_minutes'], $policy)) {
                return $cand;
            }
        }

        $hold = $this->db->prepare("SELECT id,scheduled_at,duration_minutes FROM booking_holds WHERE clinic_id=? AND professional_id=? AND status='held' AND expires_at_utc>? AND scheduled_at>=? AND scheduled_at<?");
        $hold->execute([$clinicId, $professionalId, gmdate('Y-m-d H:i:s'), date('Y-m-d H:i:s', $newStart - 86400), date('Y-m-d H:i:s', $newEnd + $padding)]);
        foreach ($hold->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if ($calendar->overlaps($clinicId, $scheduledAt, $durationMinutes, $candidate['scheduled_at'], (int)$candidate['duration_minutes'])) {
                return $candidate + ['patient_name' => 'reserva temporária', 'resource_type' => 'hold'];
            }
        }

        return null;
    }

    /**
     * Verifica se o paciente já tem consulta agendada na mesma data (NI2-591).
     */
    public function findPatientAppointmentOnDate(
        int $clinicId,
        int $patientId,
        string $date,
        ?int $excludeAppointmentId = null
    ): ?array {
        $dayStart = $date . ' 00:00:00';
        $dayEnd = $date . ' 23:59:59';

        $sql = "
            SELECT a.id, a.scheduled_at, a.duration_minutes, a.status, p.name AS patient_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id
              AND a.patient_id = :patient_id
              AND a.scheduled_at >= :day_start
              AND a.scheduled_at <= :day_end
              AND a.status NOT IN ('cancelled', 'no_show')
        ";
        $params = [
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
        ];

        if ($excludeAppointmentId !== null) {
            $sql .= " AND a.id != :exclude_id";
            $params['exclude_id'] = $excludeAppointmentId;
        }

        $sql .= " ORDER BY a.scheduled_at ASC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Normaliza e valida data e hora para ISO Y-m-d H:i:s.
     */
    private function normalizeDateTime(string $dateTimeStr): string
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $dateTimeStr);
            $errors = DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $dt->format($format) === $dateTimeStr) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException("Data e horário de consulta inválidos: '{$dateTimeStr}'. Use o formato 'YYYY-MM-DD HH:MM'.");
    }

    /**
     * Grava histórico na tabela appointment_status_history.
     */
    private function recordStatusHistory(
        int $clinicId,
        int $appointmentId,
        ?string $fromStatus,
        string $toStatus,
        ?int $userId,
        ?string $notes,
        ?int $patientId = null
    ): void {
        if ((TenantContext::getUser()['role']??'')==='patient') { $patientId=(int)TenantContext::getUserId();$userId=null; }
        // NI2-137: autoria da paciente (coluna da migração 47; sem ela, o histórico segue sem a coluna).
        $withPatient = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'appointment_status_history', 'changed_by_patient_id');
        $stmt = $this->db->prepare("
            INSERT INTO appointment_status_history (
                clinic_id, appointment_id, from_status, to_status,
                changed_by_user_id, notes, created_at" . ($withPatient ? ', changed_by_patient_id' : '') . "
            ) VALUES (
                :clinic_id, :app_id, :from_status, :to_status,
                :user_id, :notes, :created_at" . ($withPatient ? ', :patient_id' : '') . "
            )
        ");
        $params = [
            'clinic_id' => $clinicId,
            'app_id' => $appointmentId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $userId,
            'notes' => $notes,
            // UX-07 (v14): horário no fuso da aplicação (o mesmo de scheduled_at), também no SQLite.
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if ($withPatient) {
            $params['patient_id'] = $patientId;
        }
        $stmt->execute($params);
    }
}
