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
    public function schedule(
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
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $parsedDate = $this->normalizeDateTime($scheduledAt);
        $duration = max(15, min(240, $duration));
        $this->assertAvailability($professionalId,$parsedDate,$duration);

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
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

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

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
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Edita dados de um agendamento existente (data, horário, duração, notas, tipo).
     */
    public function edit(
        int $appointmentId,
        array $data,
        bool $allowConflict = false,
        ?int $userId = null
    ): array {
        $clinicId = TenantContext::getClinicId();
        $current = TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        // v11: TenantGuard só devolve id/clinic/patient/professional/status; sem o resto, editar zerava type/notes
        // (ex.: teleconsulta virava 'first_visit'). Completa com a linha atual.
        $beforeSt = $this->db->prepare("SELECT scheduled_at, professional_id, patient_id, type, duration_minutes, notes FROM appointments WHERE id = :id AND clinic_id = :c");
        $beforeSt->execute(['id' => $appointmentId, 'c' => $clinicId]);
        $before = $beforeSt->fetch(PDO::FETCH_ASSOC) ?: null;
        $current = array_merge($before ?? [], $current);

        $patientId = isset($data['patient_id']) ? (int)$data['patient_id'] : (int)$current['patient_id'];
        $professionalId = isset($data['professional_id']) ? (int)$data['professional_id'] : (int)$current['professional_id'];
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $scheduledAt = isset($data['scheduled_at']) ? $this->normalizeDateTime($data['scheduled_at']) : $current['scheduled_at'];
        $duration = isset($data['duration_minutes']) ? max(15, min(240, (int)$data['duration_minutes'])) : (int)($current['duration_minutes'] ?? 60);
        if($scheduledAt!==$current['scheduled_at']||$duration!==(int)$current['duration_minutes']||$professionalId!==(int)$current['professional_id'])$this->assertAvailability($professionalId,$scheduledAt,$duration);
        $type = $data['type'] ?? ($current['type'] ?? 'first_visit');
        $notes = array_key_exists('notes', $data) ? $data['notes'] : ($current['notes'] ?? null);

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
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
                WHERE id = :id AND clinic_id = :clinic_id
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
                "Consulta editada / reagendada para {$scheduledAt}"
            );

            // NI2-221 F04: reagendar, trocar paciente/profissional ou mudar a modalidade invalida o convite da teleconsulta.
            if ($before !== null && (
                (string)$before['scheduled_at'] !== (string)$scheduledAt
                || (int)$before['professional_id'] !== $professionalId
                || (int)$before['patient_id'] !== $patientId
                || (string)$before['type'] !== (string)$type
                || (int)$before['duration_minutes'] !== $duration
            )) {
                (new \ClyoNutri\Domain\Telehealth\TeleconsultationService($this->db))
                    ->revokeForAppointment($appointmentId, $clinicId, 'appointment_rescheduled');
            }

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $this->getAppointment($appointmentId);
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atualiza o status da consulta segundo a máquina de estados estrita (NI2-82).
     */
    public function updateStatus(
        int $appointmentId,
        string $newStatus,
        ?string $notes = null,
        ?int $userId = null
    ): bool {
        $clinicId = TenantContext::getClinicId();
        $current = TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);

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
            WHERE id = :id AND clinic_id = :clinic_id
        ");

        $success = $stmt->execute([
            'status' => $newStatus,
            'id' => $appointmentId,
            'clinic_id' => $clinicId,
        ]);

        if ($success && in_array($newStatus, [self::STATUS_CANCELLED, self::STATUS_NO_SHOW, self::STATUS_COMPLETED], true)) {
            // NI2-221 F04: cancelar / falta / concluir revoga o convite da teleconsulta (cancelar também cancela a sessão).
            $reason = ['cancelled' => 'appointment_cancelled', 'no_show' => 'appointment_no_show', 'completed' => 'appointment_completed'][$newStatus];
            (new \ClyoNutri\Domain\Telehealth\TeleconsultationService($this->db))->revokeForAppointment($appointmentId, $clinicId, $reason);
        }

        if ($success) {
            $this->recordStatusHistory(
                $clinicId,
                $appointmentId,
                $currentStatus,
                $newStatus,
                $userId ?? TenantContext::getUserId(),
                $notes
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

        // Se estiver em 'scheduled' ou 'confirmed', avança primeiro para in_progress se necessário
        if ($appointment['status'] === self::STATUS_SCHEDULED || $appointment['status'] === self::STATUS_CONFIRMED) {
            $this->updateStatus($appointmentId, self::STATUS_IN_PROGRESS, 'Início do atendimento clínico', $userId);
        }

        // Atualiza status final para completed
        $this->updateStatus($appointmentId, self::STATUS_COMPLETED, 'Atendimento clínico concluído com registro no prontuário', $userId);

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

        return [
            'appointment_id' => $appointmentId,
            'status' => self::STATUS_COMPLETED,
            'clinical_record_id' => $record['id'],
            'record_hash' => $record['record_hash'],
            'version' => $record['version'],
        ];
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

    /**
     * Retorna o histórico de auditoria de transições de status da consulta (NI2-82).
     */
    public function getStatusHistory(int $appointmentId): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT h.*, u.name AS changed_by_name
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

        // Buscar consultas do mesmo profissional no mesmo dia para verificação exata
        $targetDate = date('Y-m-d', $newStart);

        $sql = "
            SELECT a.id, a.scheduled_at, a.duration_minutes, a.status, p.name AS patient_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id
              AND a.professional_id = :prof_id
              AND date(a.scheduled_at) = :target_date
              AND a.status NOT IN ('cancelled', 'no_show')
        ";
        $params = [
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'target_date' => $targetDate,
        ];

        if ($excludeAppointmentId !== null) {
            $sql .= " AND a.id != :exclude_id";
            $params['exclude_id'] = $excludeAppointmentId;
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
        try {
            $dt = new DateTimeImmutable($dateTimeStr);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Data e horário de consulta inválidos: '{$dateTimeStr}'. Use o formato 'YYYY-MM-DD HH:MM'.");
        }
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
        ?string $notes
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO appointment_status_history (
                    clinic_id, appointment_id, from_status, to_status,
                    changed_by_user_id, notes, created_at
                ) VALUES (
                    :clinic_id, :app_id, :from_status, :to_status,
                    :user_id, :notes, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                'clinic_id' => $clinicId,
                'app_id' => $appointmentId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'user_id' => $userId,
                'notes' => $notes,
            ]);
        } catch (\Throwable $e) {
            // Em caso de falha no registro de histórico, não interrompe se for tabela não criada em dev antigo
        }
    }
}
