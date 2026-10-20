<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Booking;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class BookingService
{
    private PDO $db;
    private ?DateTimeImmutable $fixedNow;
    private DateTimeZone $timezone;

    public function __construct(?PDO $db = null, ?DateTimeImmutable $now = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->fixedNow = $now;
        $tzName = (string)(getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? date_default_timezone_get() ?: 'America/Sao_Paulo'));
        $this->timezone = new DateTimeZone($tzName);
    }

    public function now(): DateTimeImmutable
    {
        return ($this->fixedNow ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
    }

    /**
     * Retorna configurações de autoagendamento da clínica (ou padrões do sistema).
     */
    public function getSettings(int $clinicId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM booking_settings WHERE clinic_id = :clinic_id LIMIT 1");
        $stmt->execute(['clinic_id' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'clinic_id' => (int)$row['clinic_id'],
                'is_enabled' => (bool)$row['is_enabled'],
                'min_advance_hours' => (int)$row['min_advance_hours'],
                'max_future_days' => (int)$row['max_future_days'],
                'hold_ttl_minutes' => (int)$row['hold_ttl_minutes'],
                'cancel_min_advance_hours' => (int)$row['cancel_min_advance_hours'],
                'reschedule_min_advance_hours' => (int)$row['reschedule_min_advance_hours'],
                'require_cpf' => (bool)$row['require_cpf'],
                'allow_telehealth' => (bool)$row['allow_telehealth'],
                'allow_presential' => (bool)$row['allow_presential'],
            ];
        }

        // Configuração padrão
        return [
            'clinic_id' => $clinicId,
            'is_enabled' => true,
            'min_advance_hours' => 2,
            'max_future_days' => 60,
            'hold_ttl_minutes' => 15,
            'cancel_min_advance_hours' => 4,
            'reschedule_min_advance_hours' => 4,
            'require_cpf' => false,
            'allow_telehealth' => true,
            'allow_presential' => true,
        ];
    }

    /**
     * Atualiza configurações de agendamento de uma clínica.
     */
    public function updateSettings(int $clinicId, array $data): array
    {
        $current = $this->getSettings($clinicId);
        $merged = array_merge($current, $data);

        $stmt = $this->db->prepare("
            INSERT INTO booking_settings (
                clinic_id, is_enabled, min_advance_hours, max_future_days,
                hold_ttl_minutes, cancel_min_advance_hours, reschedule_min_advance_hours,
                require_cpf, allow_telehealth, allow_presential, created_at, updated_at
            ) VALUES (
                :clinic_id, :is_enabled, :min_advance, :max_future,
                :hold_ttl, :cancel_min, :resched_min,
                :require_cpf, :allow_tele, :allow_pres, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON CONFLICT(clinic_id) DO UPDATE SET
                is_enabled = excluded.is_enabled,
                min_advance_hours = excluded.min_advance_hours,
                max_future_days = excluded.max_future_days,
                hold_ttl_minutes = excluded.hold_ttl_minutes,
                cancel_min_advance_hours = excluded.cancel_min_advance_hours,
                reschedule_min_advance_hours = excluded.reschedule_min_advance_hours,
                require_cpf = excluded.require_cpf,
                allow_telehealth = excluded.allow_telehealth,
                allow_presential = excluded.allow_presential,
                updated_at = CURRENT_TIMESTAMP
        ");

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $this->db->prepare("
                INSERT INTO booking_settings (
                    clinic_id, is_enabled, min_advance_hours, max_future_days,
                    hold_ttl_minutes, cancel_min_advance_hours, reschedule_min_advance_hours,
                    require_cpf, allow_telehealth, allow_presential, created_at, updated_at
                ) VALUES (
                    :clinic_id, :is_enabled, :min_advance, :max_future,
                    :hold_ttl, :cancel_min, :resched_min,
                    :require_cpf, :allow_tele, :allow_pres, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON DUPLICATE KEY UPDATE
                    is_enabled = VALUES(is_enabled),
                    min_advance_hours = VALUES(min_advance_hours),
                    max_future_days = VALUES(max_future_days),
                    hold_ttl_minutes = VALUES(hold_ttl_minutes),
                    cancel_min_advance_hours = VALUES(cancel_min_advance_hours),
                    reschedule_min_advance_hours = VALUES(reschedule_min_advance_hours),
                    require_cpf = VALUES(require_cpf),
                    allow_telehealth = VALUES(allow_telehealth),
                    allow_presential = VALUES(allow_presential),
                    updated_at = CURRENT_TIMESTAMP
            ");
        }

        $stmt->execute([
            'clinic_id' => $clinicId,
            'is_enabled' => $merged['is_enabled'] ? 1 : 0,
            'min_advance' => (int)$merged['min_advance_hours'],
            'max_future' => (int)$merged['max_future_days'],
            'hold_ttl' => (int)$merged['hold_ttl_minutes'],
            'cancel_min' => (int)$merged['cancel_min_advance_hours'],
            'resched_min' => (int)$merged['reschedule_min_advance_hours'],
            'require_cpf' => $merged['require_cpf'] ? 1 : 0,
            'allow_tele' => $merged['allow_telehealth'] ? 1 : 0,
            'allow_pres' => $merged['allow_presential'] ? 1 : 0,
        ]);

        return $this->getSettings($clinicId);
    }

    /**
     * Calcula horários disponíveis para agendamento.
     * Considera jornada de trabalho do profissional, pausas, consultas existentes e reservas ativas (holds).
     */
    public function getAvailableSlots(
        int $clinicId,
        int $professionalId,
        string $date,
        int $durationMinutes = 60,
        string $modality = 'presential'
    ): array {
        $settings = $this->getSettings($clinicId);
        if (!$settings['is_enabled']) {
            return [];
        }

        if ($modality === 'telehealth' && !$settings['allow_telehealth']) {
            return [];
        }
        if ($modality === 'presential' && !$settings['allow_presential']) {
            return [];
        }

        $durationMinutes = max(15, min(240, $durationMinutes));
        $now = $this->now();

        // Validar data
        try {
            $requestedDate = new DateTimeImmutable($date . ' 00:00:00', $this->timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Data inválida. Use o formato AAAA-MM-DD.");
        }

        // Não permitir datas anteriores a hoje ou além do limite futuro
        $today = new DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00', $this->timezone);
        if ($requestedDate < $today) {
            return [];
        }

        $maxFuture = $today->modify('+' . $settings['max_future_days'] . ' days');
        if ($requestedDate > $maxFuture) {
            return [];
        }

        // Buscar disponibilidade semanal do profissional
        $prefs = $this->getProfessionalPreferences($clinicId, $professionalId);
        $weeklyAvail = $prefs['weekly_availability'] ?? null;
        if (empty($weeklyAvail) || !is_array($weeklyAvail)) {
            // Se não houver configuração explícita, usa padrão seg-sex das 08:00 às 18:00 com almoço 12-13
            $weeklyAvail = [
                'seg' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'ter' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'qua' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'qui' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'sex' => ['enabled' => 1, 'start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                'sab' => ['enabled' => 0, 'start' => '08:00', 'end' => '12:00'],
                'dom' => ['enabled' => 0, 'start' => '08:00', 'end' => '12:00'],
            ];
        }

        $dayIndex = (int)$requestedDate->format('N') - 1; // 0=seg, 6=dom
        $dayKeys = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
        $dayKey = $dayKeys[$dayIndex] ?? 'seg';
        $dayConfig = $weeklyAvail[$dayKey] ?? [];

        if (empty($dayConfig['enabled'])) {
            return []; // Profissional não atende neste dia da semana
        }

        $startStr = $dayConfig['start'] ?? '08:00';
        $endStr = $dayConfig['end'] ?? '18:00';
        $breakStartStr = !empty($dayConfig['break_start']) ? $dayConfig['break_start'] : null;
        $breakEndStr = !empty($dayConfig['break_end']) ? $dayConfig['break_end'] : null;

        $workStart = new DateTimeImmutable($date . ' ' . $startStr . ':00', $this->timezone);
        $workEnd = new DateTimeImmutable($date . ' ' . $endStr . ':00', $this->timezone);

        $breakStart = $breakStartStr ? new DateTimeImmutable($date . ' ' . $breakStartStr . ':00', $this->timezone) : null;
        $breakEnd = $breakEndStr ? new DateTimeImmutable($date . ' ' . $breakEndStr . ':00', $this->timezone) : null;

        // Buscar consultas existentes no dia
        $apptsStmt = $this->db->prepare("
            SELECT scheduled_at, duration_minutes FROM appointments
            WHERE clinic_id = :clinic_id AND professional_id = :prof_id
              AND scheduled_at >= :start_of_day AND scheduled_at < :end_of_day
              AND status <> 'cancelled'
        ");
        $apptsStmt->execute([
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'start_of_day' => $date . ' 00:00:00',
            'end_of_day' => $date . ' 23:59:59',
        ]);
        $existingAppts = $apptsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar reservas temporárias ativas no dia
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());
        $holdsStmt = $this->db->prepare("
            SELECT scheduled_at, duration_minutes FROM booking_holds
            WHERE clinic_id = :clinic_id AND professional_id = :prof_id
              AND scheduled_at >= :start_of_day AND scheduled_at < :end_of_day
              AND status = 'held' AND expires_at_utc > :now_utc
        ");
        $holdsStmt->execute([
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'start_of_day' => $date . ' 00:00:00',
            'end_of_day' => $date . ' 23:59:59',
            'now_utc' => $nowUtcStr,
        ]);
        $existingHolds = $holdsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Limite mínimo de antecedência
        $minAdvanceLimit = $now->modify('+' . $settings['min_advance_hours'] . ' hours');

        // Gerar slots candidatos a cada 30 minutos (ou a cada duration)
        $slotStep = ($durationMinutes >= 60 && ($durationMinutes % 60 === 0)) ? 60 : 30;
        $slots = [];
        $cursor = $workStart;

        while ($cursor < $workEnd) {
            $slotStart = $cursor;
            $slotEnd = $cursor->modify('+' . $durationMinutes . ' minutes');

            if ($slotEnd > $workEnd) {
                break;
            }

            $isAvailable = true;

            // 1. Respeita antecedência mínima
            if ($slotStart < $minAdvanceLimit) {
                $isAvailable = false;
            }

            // 2. Não colide com pausa/almoço
            if ($isAvailable && $breakStart && $breakEnd) {
                if ($slotStart < $breakEnd && $slotEnd > $breakStart) {
                    $isAvailable = false;
                }
            }

            // 3. Não colide com consultas existentes
            if ($isAvailable) {
                foreach ($existingAppts as $appt) {
                    $apptStart = new DateTimeImmutable((string)$appt['scheduled_at'], $this->timezone);
                    $apptDuration = max(15, (int)($appt['duration_minutes'] ?? 60));
                    $apptEnd = $apptStart->modify('+' . $apptDuration . ' minutes');

                    if ($slotStart < $apptEnd && $slotEnd > $apptStart) {
                        $isAvailable = false;
                        break;
                    }
                }
            }

            // 4. Não colide com reservas temporárias ativas
            if ($isAvailable) {
                foreach ($existingHolds as $hold) {
                    $holdStart = new DateTimeImmutable((string)$hold['scheduled_at'], $this->timezone);
                    $holdDuration = max(15, (int)($hold['duration_minutes'] ?? 60));
                    $holdEnd = $holdStart->modify('+' . $holdDuration . ' minutes');

                    if ($slotStart < $holdEnd && $slotEnd > $holdStart) {
                        $isAvailable = false;
                        break;
                    }
                }
            }

            if ($isAvailable) {
                $slots[] = [
                    'time' => $slotStart->format('H:i'),
                    'scheduled_at' => $slotStart->format('Y-m-d H:i:s'),
                    'available' => true,
                ];
            }

            $cursor = $cursor->modify('+' . $slotStep . ' minutes');
        }

        return $slots;
    }

    /**
     * Cria reserva temporária com TTL (Hold).
     * Proteção ACID com validação de concorrência anti-overbooking.
     */
    public function createHold(
        int $clinicId,
        int $professionalId,
        string $scheduledAt,
        int $durationMinutes = 60,
        string $serviceType = 'first_visit',
        string $modality = 'presential',
        ?string $idempotencyKey = null,
        array $patientData = []
    ): array {
        $settings = $this->getSettings($clinicId);
        if (!$settings['is_enabled']) {
            throw new RuntimeException("O autoagendamento está temporariamente desabilitado para esta clínica.");
        }

        $durationMinutes = max(15, min(240, $durationMinutes));
        $now = $this->now();
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());

        // Idempotência: se já existe hold com esta chave e ainda está ativo, devolve
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $stmt = $this->db->prepare("
                SELECT * FROM booking_holds
                WHERE clinic_id = :clinic_id AND idempotency_key = :key
                  AND status = 'held' AND expires_at_utc > :now_utc
                LIMIT 1
            ");
            $stmt->execute(['clinic_id' => $clinicId, 'key' => $idempotencyKey, 'now_utc' => $nowUtcStr]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $exp = new DateTimeImmutable((string)$existing['expires_at_utc'], new DateTimeZone('UTC'));
                return [
                    'id' => (int)$existing['id'],
                    'hold_token' => (string)$existing['hold_token'],
                    'status' => 'held',
                    'scheduled_at' => (string)$existing['scheduled_at'],
                    'duration_minutes' => (int)$existing['duration_minutes'],
                    'expires_at_utc' => (string)$existing['expires_at_utc'],
                    'ttl_seconds_remaining' => max(0, $exp->getTimestamp() - $now->getTimestamp()),
                    'idempotent_replay' => true,
                ];
            }
        }

        try {
            $slotStart = new DateTimeImmutable($scheduledAt, $this->timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Formato de data e hora inválido.");
        }

        $slotEnd = $slotStart->modify('+' . $durationMinutes . ' minutes');

        // Validar antecedência mínima
        $minAdvanceLimit = $now->modify('+' . $settings['min_advance_hours'] . ' hours');
        if ($slotStart < $minAdvanceLimit) {
            throw new InvalidArgumentException("Agendamentos devem ser solicitados com no mínimo {$settings['min_advance_hours']} horas de antecedência.");
        }

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            // Lock de leitura no MySQL para evitar race conditions
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $this->db->prepare("SELECT id FROM professionals WHERE id = :id FOR UPDATE");
                $lock->execute(['id' => $professionalId]);
            }
            TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

            // 1. Checar colisão com appointments ativos
            $conflictAppt = $this->findAppointmentConflict($clinicId, $professionalId, $slotStart, $slotEnd);
            if ($conflictAppt !== null) {
                throw new BookingConflictException(
                    "O horário solicitado (" . $slotStart->format('d/m/Y H:i') . ") não está mais disponível.",
                    $conflictAppt
                );
            }

            // 2. Checar colisão com outros holds ativos
            $conflictHold = $this->findActiveHoldConflict($clinicId, $professionalId, $slotStart, $slotEnd, $nowUtcStr);
            if ($conflictHold !== null) {
                throw new BookingConflictException(
                    "O horário solicitado (" . $slotStart->format('d/m/Y H:i') . ") está reservado temporariamente por outro paciente. Tente novamente em instantes.",
                    $conflictHold
                );
            }

            // Gerar token seguro de 64 caracteres hex
            $holdToken = bin2hex(random_bytes(32));
            $ttlMinutes = max(5, min(60, $settings['hold_ttl_minutes']));
            $expiresAtTimestamp = $now->getTimestamp() + ($ttlMinutes * 60);
            $expiresAtUtc = gmdate('Y-m-d H:i:s', $expiresAtTimestamp);

            $stmt = $this->db->prepare("
                INSERT INTO booking_holds (
                    clinic_id, professional_id, patient_id, hold_token, idempotency_key,
                    scheduled_at, duration_minutes, service_type, modality, status,
                    expires_at_utc, patient_name, patient_email, patient_phone, patient_cpf,
                    patient_notes, payment_status, created_at, updated_at
                ) VALUES (
                    :clinic_id, :prof_id, :patient_id, :hold_token, :idempotency_key,
                    :scheduled_at, :duration, :service_type, :modality, 'held',
                    :expires_at_utc, :name, :email, :phone, :cpf,
                    :notes, 'not_required', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");

            $stmt->execute([
                'clinic_id' => $clinicId,
                'prof_id' => $professionalId,
                'patient_id' => !empty($patientData['patient_id']) ? (int)$patientData['patient_id'] : null,
                'hold_token' => $holdToken,
                'idempotency_key' => $idempotencyKey ?: null,
                'scheduled_at' => $slotStart->format('Y-m-d H:i:s'),
                'duration' => $durationMinutes,
                'service_type' => $serviceType,
                'modality' => $modality,
                'expires_at_utc' => $expiresAtUtc,
                'name' => $patientData['name'] ?? null,
                'email' => $patientData['email'] ?? null,
                'phone' => $patientData['phone'] ?? null,
                'cpf' => $patientData['cpf'] ?? null,
                'notes' => $patientData['notes'] ?? null,
            ]);

            $holdId = (int)$this->db->lastInsertId();

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'id' => $holdId,
                'hold_token' => $holdToken,
                'status' => 'held',
                'scheduled_at' => $slotStart->format('Y-m-d H:i:s'),
                'duration_minutes' => $durationMinutes,
                'expires_at_utc' => $expiresAtUtc,
                'ttl_seconds_remaining' => $ttlMinutes * 60,
                'idempotent_replay' => false,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Confirma a reserva temporária e converte atomicamente em agendamento canônico (appointments).
     */
    public function confirmBooking(string $holdToken, array $confirmationData): array
    {
        $now = $this->now();
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM booking_holds WHERE hold_token = :token LIMIT 1");
            $stmt->execute(['token' => $holdToken]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                throw new InvalidArgumentException("Reserva não encontrada ou token inválido.");
            }

            if ($hold['status'] === 'converted') {
                // Já confirmada: idempotência
                return [
                    'success' => true,
                    'already_confirmed' => true,
                    'appointment_id' => (int)$hold['appointment_id'],
                    'scheduled_at' => (string)$hold['scheduled_at'],
                    'status' => 'scheduled',
                ];
            }

            if ($hold['status'] !== 'held') {
                throw new RuntimeException("Esta reserva não pode ser confirmada porque seu status é '{$hold['status']}'.");
            }

            // Verificar se o TTL expirou
            if ($hold['expires_at_utc'] <= $nowUtcStr) {
                $up = $this->db->prepare("UPDATE booking_holds SET status = 'expired', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $up->execute(['id' => $hold['id']]);
                if ($ownsTransaction && $this->db->inTransaction()) {
                    $this->db->commit();
                }
                throw new HoldExpiredException();
            }

            $clinicId = (int)$hold['clinic_id'];
            $professionalId = (int)$hold['professional_id'];
            $scheduledAt = (string)$hold['scheduled_at'];
            $durationMinutes = (int)$hold['duration_minutes'];
            $modality = (string)$hold['modality'];
            $serviceType = (string)$hold['service_type'];

            $slotStart = new DateTimeImmutable($scheduledAt, $this->timezone);
            $slotEnd = $slotStart->modify('+' . $durationMinutes . ' minutes');

            // Re-checar colisão contra appointments
            $conflictAppt = $this->findAppointmentConflict($clinicId, $professionalId, $slotStart, $slotEnd);
            if ($conflictAppt !== null) {
                throw new BookingConflictException("Conflito detectado no fechamento da vaga: o horário foi ocupado.", $conflictAppt);
            }

            // Identificar ou criar o paciente na clínica
            $patientName = trim((string)($confirmationData['name'] ?? ($hold['patient_name'] ?? '')));
            $patientEmail = trim((string)($confirmationData['email'] ?? ($hold['patient_email'] ?? '')));
            $patientPhone = trim((string)($confirmationData['phone'] ?? ($hold['patient_phone'] ?? '')));
            $patientCpf = trim((string)($confirmationData['cpf'] ?? ($hold['patient_cpf'] ?? '')));
            $patientNotes = trim((string)($confirmationData['notes'] ?? ($hold['patient_notes'] ?? '')));

            if ($patientName === '') {
                throw new InvalidArgumentException("O nome do paciente é obrigatório para confirmar o agendamento.");
            }

            $patientId = !empty($hold['patient_id']) ? (int)$hold['patient_id'] : null;

            if ($patientId === null) {
                $patientId = $this->findOrCreatePatient($clinicId, $patientName, $patientEmail, $patientPhone, $patientCpf);
            }

            $apptType = ($modality === 'telehealth' || TeleconsultationService::isOnlineType($serviceType)) ? 'telehealth' : $serviceType;

            // Inserir agendamento canônico na tabela appointments
            $apptStmt = $this->db->prepare("
                INSERT INTO appointments (
                    clinic_id, patient_id, professional_id, scheduled_at,
                    duration_minutes, status, type, notes, created_at, updated_at
                ) VALUES (
                    :clinic_id, :patient_id, :prof_id, :scheduled_at,
                    :duration, 'scheduled', :type, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
            $apptStmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'prof_id' => $professionalId,
                'scheduled_at' => $scheduledAt,
                'duration' => $durationMinutes,
                'type' => $apptType,
                'notes' => $patientNotes !== '' ? $patientNotes : 'Autoagendamento via portal web ClyoNutri',
            ]);
            $appointmentId = (int)$this->db->lastInsertId();

            // Gravar histórico de auditoria
            $histStmt = $this->db->prepare("
                INSERT INTO appointment_status_history (
                    clinic_id, appointment_id, from_status, to_status, notes, created_at
                ) VALUES (
                    :clinic_id, :appt_id, NULL, 'scheduled', 'Autoagendamento self-service pelo paciente (portal/web)', CURRENT_TIMESTAMP
                )
            ");
            $histStmt->execute([
                'clinic_id' => $clinicId,
                'appt_id' => $appointmentId,
            ]);

            // Se for teleconsulta, inicializar a sessão canônica
            $teleSessionId = null;
            if ($modality === 'telehealth' || TeleconsultationService::isOnlineType($apptType)) {
                try {
                    $teleService = new TeleconsultationService($this->db, null, null, $now);
                    $session = $teleService->scheduleSession($appointmentId, $professionalId, $clinicId);
                    $teleSessionId = (int)($session['session_id'] ?? 0);
                } catch (\Throwable $e) {
                    // Log interno; o agendamento não falha se a teleconsulta for preparada em seguida
                }
            }

            // Atualizar hold para 'converted'
            $upHold = $this->db->prepare("
                UPDATE booking_holds
                SET status = 'converted',
                    appointment_id = :appt_id,
                    patient_id = :patient_id,
                    patient_name = :name,
                    patient_email = :email,
                    patient_phone = :phone,
                    patient_cpf = :cpf,
                    patient_notes = :notes,
                    teleconsultation_session_id = :tele_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $upHold->execute([
                'appt_id' => $appointmentId,
                'patient_id' => $patientId,
                'name' => $patientName,
                'email' => $patientEmail,
                'phone' => $patientPhone,
                'cpf' => $patientCpf,
                'notes' => $patientNotes,
                'tele_id' => $teleSessionId,
                'id' => $hold['id'],
            ]);

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'appointment_id' => $appointmentId,
                'patient_id' => $patientId,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $durationMinutes,
                'status' => 'scheduled',
                'modality' => $modality,
                'teleconsultation_ready' => $teleSessionId !== null,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Libera voluntariamente uma reserva temporária antes do TTL.
     */
    public function releaseHold(string $holdToken): bool
    {
        $stmt = $this->db->prepare("
            UPDATE booking_holds
            SET status = 'released', updated_at = CURRENT_TIMESTAMP
            WHERE hold_token = :token AND status = 'held'
        ");
        $stmt->execute(['token' => $holdToken]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancelamento self-service de consulta pelo paciente.
     */
    public function cancelBookingByPatient(int $clinicId, int $patientId, int $appointmentId, string $reason): array
    {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);

        $stmt = $this->db->prepare("SELECT * FROM appointments WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $appointmentId, 'clinic_id' => $clinicId]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$appt || (int)$appt['patient_id'] !== $patientId) {
            throw new \ClyoNutri\Core\Security\TenantSecurityException("Consulta não pertence ao paciente autenticado.");
        }

        if (in_array($appt['status'], ['completed', 'cancelled', 'no_show'], true)) {
            throw new RuntimeException("Esta consulta não pode ser cancelada pois seu status é '{$appt['status']}'.");
        }

        // Validação da política de cancelamento
        $settings = $this->getSettings($clinicId);
        $minAdvanceHours = $settings['cancel_min_advance_hours'];
        $apptStart = new DateTimeImmutable((string)$appt['scheduled_at'], $this->timezone);
        $limit = $this->now()->modify('+' . $minAdvanceHours . ' hours');

        if ($apptStart < $limit) {
            throw new InvalidArgumentException("Cancelamentos self-service devem ser realizados com pelo menos {$minAdvanceHours} horas de antecedência. Entre em contato direto com o consultório.");
        }

        $stmt = $this->db->prepare("
            UPDATE appointments
            SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute(['id' => $appointmentId, 'clinic_id' => $clinicId]);

        // Registrar no histórico de status
        $hist = $this->db->prepare("
            INSERT INTO appointment_status_history (
                clinic_id, appointment_id, from_status, to_status, notes, created_at
            ) VALUES (
                :clinic_id, :appt_id, :from_status, 'cancelled', :notes, CURRENT_TIMESTAMP
            )
        ");
        $hist->execute([
            'clinic_id' => $clinicId,
            'appt_id' => $appointmentId,
            'from_status' => $appt['status'],
            'notes' => 'Cancelado pelo paciente via autoatendimento. Motivo: ' . mb_substr(trim($reason), 0, 300),
        ]);

        return [
            'success' => true,
            'appointment_id' => $appointmentId,
            'status' => 'cancelled',
        ];
    }

    /**
     * Reagendamento self-service de consulta pelo paciente.
     */
    public function rescheduleBookingByPatient(
        int $clinicId,
        int $patientId,
        int $appointmentId,
        string $newScheduledAt,
        string $reason
    ): array {
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);

        $stmt = $this->db->prepare("SELECT * FROM appointments WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $appointmentId, 'clinic_id' => $clinicId]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$appt || (int)$appt['patient_id'] !== $patientId) {
            throw new \ClyoNutri\Core\Security\TenantSecurityException("Consulta não pertence ao paciente autenticado.");
        }

        if (in_array($appt['status'], ['completed', 'cancelled', 'no_show'], true)) {
            throw new RuntimeException("Esta consulta não pode ser reagendada pois seu status é '{$appt['status']}'.");
        }

        $settings = $this->getSettings($clinicId);
        $minAdvanceHours = $settings['reschedule_min_advance_hours'];
        $apptStart = new DateTimeImmutable((string)$appt['scheduled_at'], $this->timezone);
        $limit = $this->now()->modify('+' . $minAdvanceHours . ' hours');

        if ($apptStart < $limit) {
            throw new InvalidArgumentException("Reagendamentos self-service devem ser solicitados com no mínimo {$minAdvanceHours} horas de antecedência.");
        }

        $newStart = new DateTimeImmutable($newScheduledAt, $this->timezone);
        $duration = (int)($appt['duration_minutes'] ?? 60);
        $newEnd = $newStart->modify('+' . $duration . ' minutes');

        // Checar conflitos no novo horário excluindo a consulta atual
        $conflict = $this->findAppointmentConflict($clinicId, (int)$appt['professional_id'], $newStart, $newEnd, $appointmentId);
        if ($conflict !== null) {
            throw new BookingConflictException("O novo horário solicitado (" . $newStart->format('d/m/Y H:i') . ") já está ocupado.", $conflict);
        }

        $stmt = $this->db->prepare("
            UPDATE appointments
            SET scheduled_at = :new_time, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $stmt->execute([
            'new_time' => $newStart->format('Y-m-d H:i:s'),
            'id' => $appointmentId,
            'clinic_id' => $clinicId,
        ]);

        $hist = $this->db->prepare("
            INSERT INTO appointment_status_history (
                clinic_id, appointment_id, from_status, to_status, notes, created_at
            ) VALUES (
                :clinic_id, :appt_id, :from_status, :from_status, :notes, CURRENT_TIMESTAMP
            )
        ");
        $hist->execute([
            'clinic_id' => $clinicId,
            'appt_id' => $appointmentId,
            'from_status' => $appt['status'],
            'notes' => 'Reagendado pelo paciente de ' . date('d/m/Y H:i', strtotime((string)$appt['scheduled_at'])) . ' para ' . $newStart->format('d/m/Y H:i') . '. Motivo: ' . mb_substr(trim($reason), 0, 300),
        ]);

        return [
            'success' => true,
            'appointment_id' => $appointmentId,
            'scheduled_at' => $newStart->format('Y-m-d H:i:s'),
            'status' => $appt['status'],
        ];
    }

    // =========================================================================
    // Métodos Auxiliares Internos
    // =========================================================================

    private function getProfessionalPreferences(int $clinicId, int $professionalId): array
    {
        $stmt = $this->db->prepare("
            SELECT op.practice_preferences FROM onboarding_progress op
            JOIN professionals p ON p.user_id = op.user_id
            WHERE op.clinic_id = :clinic_id AND p.id = :prof_id
            LIMIT 1
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'prof_id' => $professionalId]);
        $json = $stmt->fetchColumn();
        return json_decode((string)($json ?: '{}'), true) ?: [];
    }

    private function findAppointmentConflict(
        int $clinicId,
        int $professionalId,
        DateTimeImmutable $slotStart,
        DateTimeImmutable $slotEnd,
        ?int $excludeAppointmentId = null
    ): ?array {
        $sql = "
            SELECT a.id, a.scheduled_at, a.duration_minutes, p.name AS patient_name
            FROM appointments a
            LEFT JOIN patients p ON p.id = a.patient_id
            WHERE a.clinic_id = :clinic_id AND a.professional_id = :prof_id
              AND a.status <> 'cancelled'
        ";
        $params = ['clinic_id' => $clinicId, 'prof_id' => $professionalId];

        if ($excludeAppointmentId !== null) {
            $sql .= " AND a.id <> :exclude_id";
            $params['exclude_id'] = $excludeAppointmentId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $startTs = $slotStart->getTimestamp();
        $endTs = $slotEnd->getTimestamp();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $apptStartTs = strtotime((string)$row['scheduled_at']);
            $apptEndTs = $apptStartTs + ((int)($row['duration_minutes'] ?? 60) * 60);

            if ($startTs < $apptEndTs && $endTs > $apptStartTs) {
                return $row;
            }
        }

        return null;
    }

    private function findActiveHoldConflict(
        int $clinicId,
        int $professionalId,
        DateTimeImmutable $slotStart,
        DateTimeImmutable $slotEnd,
        string $nowUtcStr
    ): ?array {
        $stmt = $this->db->prepare("
            SELECT id, scheduled_at, duration_minutes, hold_token
            FROM booking_holds
            WHERE clinic_id = :clinic_id AND professional_id = :prof_id
              AND status = 'held' AND expires_at_utc > :now_utc
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'now_utc' => $nowUtcStr,
        ]);

        $startTs = $slotStart->getTimestamp();
        $endTs = $slotEnd->getTimestamp();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $holdStartTs = strtotime((string)$row['scheduled_at']);
            $holdEndTs = $holdStartTs + ((int)($row['duration_minutes'] ?? 60) * 60);

            if ($startTs < $holdEndTs && $endTs > $holdStartTs) {
                return $row;
            }
        }

        return null;
    }

    private function findOrCreatePatient(
        int $clinicId,
        string $name,
        string $email,
        string $phone,
        string $cpf
    ): int {
        // Tentar localizar paciente existente na mesma clínica por email ou telefone
        if ($email !== '') {
            $stmt = $this->db->prepare("SELECT id FROM patients WHERE clinic_id = :clinic_id AND LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute(['clinic_id' => $clinicId, 'email' => $email]);
            $id = $stmt->fetchColumn();
            if ($id) return (int)$id;
        }

        if ($phone !== '') {
            $stmt = $this->db->prepare("SELECT id FROM patients WHERE clinic_id = :clinic_id AND phone = :phone LIMIT 1");
            $stmt->execute(['clinic_id' => $clinicId, 'phone' => $phone]);
            $id = $stmt->fetchColumn();
            if ($id) return (int)$id;
        }

        $cleanCpf = preg_replace('/\D/', '', $cpf);
        $patientNotes = $cleanCpf !== '' ? 'CPF: ' . $cleanCpf : null;
        $uuid = bin2hex(random_bytes(16));

        // Criar novo paciente
        $insert = $this->db->prepare("
            INSERT INTO patients (clinic_id, uuid, name, email, phone, status, notes, created_at, updated_at)
            VALUES (:clinic_id, :uuid, :name, :email, :phone, 'active', :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insert->execute([
            'clinic_id' => $clinicId,
            'uuid' => $uuid,
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'notes' => $patientNotes,
        ]);

        return (int)$this->db->lastInsertId();
    }
}
