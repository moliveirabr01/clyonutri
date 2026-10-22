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
     * Edita dados de um agendamento existente (data, horário, duração, notas, tipo).
     */
    public function edit(int $appointmentId, array $data, bool $allowConflict = false, ?int $userId = null): array
    {
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, TenantContext::getClinicId(), $this->db);
        return $this->atomic(fn() => $this->editAtomically($appointmentId, $data, $allowConflict, $userId));
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
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, TenantContext::getClinicId(), $this->db);
        return $this->atomic(fn() => $this->updateStatusAtomically($appointmentId, $newStatus, $notes, $userId));
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

        return $success;
    }

    /**
     * Conclui a consulta médica integrando com prontuário clínico e hash SHA-256 (NI2-84).
     */
    public function completeConsultation(
        int $appointmentId,
        string $evolutionSummary,
        ?array $clinicalDetails = null,
        ?int $userId = null
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
            ], $clinicalDetails ?? []);

            $record = $clinicalService->appendRecord(
                (int)$appointment['patient_id'],
                (int)$appointment['professional_id'],
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

    /** Saved preferences for the professional in the current clinic. */
    public function practicePreferences(int $professionalId): array
    {
        $stmt=$this->db->prepare('SELECT op.practice_preferences FROM onboarding_progress op JOIN professionals p ON p.user_id=op.user_id WHERE op.clinic_id=? AND p.id=?');
        $stmt->execute([TenantContext::getClinicId(),$professionalId]);return json_decode((string)($stmt->fetchColumn()?:'{}'),true)?:[];
    }
    private function assertAvailability(int $professionalId,string $scheduledAt,int $duration): void
    {
        $prefs=$this->practicePreferences($professionalId);
        if(!array_key_exists('weekly_availability',$prefs))return;
        $start=new DateTimeImmutable($scheduledAt);$end=$start->modify('+'.$duration.' minutes');
        $day=['seg','ter','qua','qui','sex','sab','dom'][(int)$start->format('N')-1];$row=$prefs['weekly_availability'][$day]??[];
        $time=$start->format('H:i:s');$endTime=$end->format('H:i:s');
        if(empty($row['enabled'])||$start->format('Y-m-d')!==$end->format('Y-m-d')||$time<(($row['start']??'').':00')||$endTime>(($row['end']??'').':00'))throw new InvalidArgumentException('Consulta fora da disponibilidade semanal do profissional. Confira os horários em Configurações.');
        if(!empty($row['break_start'])&&$time<($row['break_end'].':00')&&$endTime>($row['break_start'].':00'))throw new InvalidArgumentException('A consulta coincide com a pausa do profissional. Escolha outro horário.');
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
    private function findConflict(
        int $clinicId,
        int $professionalId,
        string $scheduledAt,
        int $durationMinutes,
        ?int $excludeAppointmentId = null
    ): ?array {
        $newStart = strtotime($scheduledAt);
        $newEnd = $newStart + ($durationMinutes * 60);

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
            'new_start' => $scheduledAt,
            'new_end' => date('Y-m-d H:i:s', $newEnd),
        ];

        if ($excludeAppointmentId !== null) {
            $sql .= " AND a.id != :exclude_id";
            $params['exclude_id'] = $excludeAppointmentId;
        }

        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $cand) {
            $candStart = strtotime($cand['scheduled_at']);
            $candEnd = $candStart + ((int)$cand['duration_minutes'] * 60);

            // Há sobreposição se: candStart < newEnd && candEnd > newStart
            if ($candStart < $newEnd && $candEnd > $newStart) {
                return $cand;
            }
        }

        return null;
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
