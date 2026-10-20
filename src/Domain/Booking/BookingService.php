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
    /** Tipos de atendimento aceitos no autoagendamento (mesmos códigos da agenda interna). */
    public const SERVICE_TYPES = ['first_visit', 'follow_up'];
    public const MODALITIES = ['presential', 'telehealth'];
    /** Status de consulta que NÃO ocupam o horário (mesma regra de AppointmentService::findConflict). */
    private const FREEING_STATUSES = ['cancelled', 'no_show'];

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

        // Configuração padrão. O autoagendamento é OPT-IN: sem configuração explícita da clínica,
        // nenhum profissional fica exposto na página pública (NI2-433).
        return [
            'clinic_id' => $clinicId,
            'is_enabled' => false,
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
        // Limites da política (validados no servidor, não só no formulário).
        foreach ([
            'min_advance_hours' => [0, 168], 'max_future_days' => [1, 365], 'hold_ttl_minutes' => [5, 60],
            'cancel_min_advance_hours' => [0, 168], 'reschedule_min_advance_hours' => [0, 168],
        ] as $field => [$min, $max]) {
            if (!is_numeric($merged[$field]) || (int)$merged[$field] < $min || (int)$merged[$field] > $max) {
                throw new InvalidArgumentException("Valor inválido em {$field}: use de {$min} a {$max}.");
            }
        }
        foreach (['is_enabled', 'require_cpf', 'allow_telehealth', 'allow_presential'] as $flag) {
            $merged[$flag] = filter_var($merged[$flag], FILTER_VALIDATE_BOOL);
        }
        if ($merged['is_enabled'] && !$merged['allow_telehealth'] && !$merged['allow_presential']) {
            throw new InvalidArgumentException('Escolha ao menos uma modalidade (presencial ou teleconsulta).');
        }

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
        string $modality = 'presential',
        ?int $excludeAppointmentId = null
    ): array {
        $settings = $this->getSettings($clinicId);
        if (!$settings['is_enabled']) {
            return [];
        }
        if (!$this->professionalInClinic($professionalId, $clinicId)) {
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

        // Disponibilidade semanal configurada pelo profissional em Configurações. Sem configuração,
        // não há horário a oferecer: a página pública não inventa jornada (NI2-433, "horários
        // realmente disponíveis").
        $prefs = $this->getProfessionalPreferences($clinicId, $professionalId);
        $weeklyAvail = $prefs['weekly_availability'] ?? null;
        if (empty($weeklyAvail) || !is_array($weeklyAvail)) {
            return [];
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
        // Inclui consultas que começam no dia anterior (podem atravessar a meia-noite) e ignora
        // só os status que liberam o horário, como a agenda interna.
        $apptsSql = "
            SELECT scheduled_at, duration_minutes FROM appointments
            WHERE clinic_id = :clinic_id AND professional_id = :prof_id
              AND scheduled_at >= :start_window AND scheduled_at < :end_of_day
              AND status NOT IN ('cancelled', 'no_show')
        ";
        $apptsParams = [
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'start_window' => $requestedDate->modify('-1 day')->format('Y-m-d H:i:s'),
            'end_of_day' => $requestedDate->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
        if ($excludeAppointmentId !== null) {
            $apptsSql .= ' AND id <> :exclude_id';
            $apptsParams['exclude_id'] = $excludeAppointmentId;
        }
        $apptsStmt = $this->db->prepare($apptsSql);
        $apptsStmt->execute($apptsParams);
        $existingAppts = $apptsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Buscar reservas temporárias ativas no dia
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());
        $holdsStmt = $this->db->prepare("
            SELECT scheduled_at, duration_minutes FROM booking_holds
            WHERE clinic_id = :clinic_id AND professional_id = :prof_id
              AND scheduled_at >= :start_window AND scheduled_at < :end_of_day
              AND status = 'held' AND expires_at_utc > :now_utc
        ");
        $holdsStmt->execute([
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'start_window' => $requestedDate->modify('-1 day')->format('Y-m-d H:i:s'),
            'end_of_day' => $requestedDate->modify('+1 day')->format('Y-m-d H:i:s'),
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
     * Profissional apto a receber autoagendamento: usuário, vínculo e clínica ativos e
     * autoagendamento ligado na clínica. Retorna só dados públicos (nunca e-mail).
     */
    public function publicProfessional(int $professionalId): ?array
    {
        if ($professionalId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT p.id, cm.clinic_id, u.name, c.name AS clinic_name, c.logo_path
            FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE p.id = :id AND u.status = 'active' AND c.status = 'active' AND cm.status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['id' => $professionalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$this->getSettings((int)$row['clinic_id'])['is_enabled']) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'clinic_id' => (int)$row['clinic_id'],
            'name' => (string)$row['name'],
            'clinic_name' => (string)$row['clinic_name'],
            'logo_path' => $row['logo_path'] ?? null,
            'duration_minutes' => $this->bookingDuration((int)$row['clinic_id'], (int)$row['id']),
        ];
    }

    /** Duração definida pelo profissional em Configurações (o paciente não escolhe). */
    public function bookingDuration(int $clinicId, int $professionalId): int
    {
        $prefs = $this->getProfessionalPreferences($clinicId, $professionalId);
        $d = (int)($prefs['default_duration_minutes'] ?? 60);
        return max(15, min(240, $d > 0 ? $d : 60));
    }

    public static function normalizeServiceType(string $type): ?string
    {
        $t = strtolower(trim($type));
        if ($t === 'followup' || $t === 'return') {
            $t = 'follow_up';
        }
        return in_array($t, self::SERVICE_TYPES, true) ? $t : null;
    }

    /**
     * Cria reserva temporária com TTL (hold).
     *
     * Regras (NI2-433/434):
     * - só para profissional apto (publicProfessional) e da clínica informada;
     * - o horário precisa ser um dos slots que o motor de disponibilidade oferece agora
     *   (jornada, pausa, antecedência, limite futuro, consultas e holds);
     * - dentro da transação, com o profissional travado (MySQL: FOR UPDATE, a mesma trava da
     *   agenda interna), a colisão é verificada de novo contra consultas e holds ativos.
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
        $prof = $this->publicProfessional($professionalId);
        if ($prof === null || $prof['clinic_id'] !== $clinicId) {
            throw new BookingUnavailableException();
        }
        $settings = $this->getSettings($clinicId);

        $normalizedType = self::normalizeServiceType($serviceType);
        if ($normalizedType === null) {
            throw new InvalidArgumentException('Tipo de atendimento inválido.');
        }
        if (!in_array($modality, self::MODALITIES, true)) {
            throw new InvalidArgumentException('Modalidade inválida.');
        }
        if (($modality === 'telehealth' && !$settings['allow_telehealth']) || ($modality === 'presential' && !$settings['allow_presential'])) {
            throw new InvalidArgumentException('Esta modalidade não está disponível para agendamento online.');
        }
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
        if ($idempotencyKey !== null && ($idempotencyKey === '' || strlen($idempotencyKey) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $idempotencyKey))) {
            $idempotencyKey = null;
        }

        // A duração é a do profissional; o valor enviado pelo cliente é ignorado.
        $durationMinutes = $prof['duration_minutes'];
        $now = $this->now();
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());

        // Idempotência: mesma chave + mesmo horário devolve o hold ativo, sem duplicar.
        if ($idempotencyKey !== null) {
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

        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $scheduledAt)) {
            throw new InvalidArgumentException('Formato de data e hora inválido. Use AAAA-MM-DD HH:MM.');
        }
        try {
            $slotStart = new DateTimeImmutable(str_replace('T', ' ', $scheduledAt), $this->timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Formato de data e hora inválido.');
        }
        $slotEnd = $slotStart->modify('+' . $durationMinutes . ' minutes');

        // O horário precisa ser um dos oferecidos pelo motor de disponibilidade.
        $offered = array_column($this->getAvailableSlots($clinicId, $professionalId, $slotStart->format('Y-m-d'), $durationMinutes, $modality), 'scheduled_at');
        if (!in_array($slotStart->format('Y-m-d H:i:s'), $offered, true)) {
            throw new BookingConflictException('O horário ' . $slotStart->format('d/m/Y H:i') . ' não está disponível. Escolha outro horário da lista.');
        }

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $this->lockProfessional($professionalId);
            TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

            $conflictAppt = $this->findAppointmentConflict($clinicId, $professionalId, $slotStart, $slotEnd);
            if ($conflictAppt !== null) {
                throw new BookingConflictException('O horário solicitado (' . $slotStart->format('d/m/Y H:i') . ') não está mais disponível.');
            }
            $conflictHold = $this->findActiveHoldConflict($clinicId, $professionalId, $slotStart, $slotEnd, $nowUtcStr);
            if ($conflictHold !== null) {
                throw new BookingConflictException('O horário solicitado (' . $slotStart->format('d/m/Y H:i') . ') está reservado temporariamente por outra pessoa. Tente outro horário ou aguarde alguns minutos.');
            }

            $holdToken = bin2hex(random_bytes(32));
            $ttlMinutes = max(5, min(60, $settings['hold_ttl_minutes']));
            $expiresAtUtc = gmdate('Y-m-d H:i:s', $now->getTimestamp() + ($ttlMinutes * 60));

            $stmt = $this->db->prepare("
                INSERT INTO booking_holds (
                    clinic_id, professional_id, patient_id, hold_token, idempotency_key,
                    scheduled_at, duration_minutes, service_type, modality, status,
                    expires_at_utc, payment_status, created_at, updated_at
                ) VALUES (
                    :clinic_id, :prof_id, NULL, :hold_token, :idempotency_key,
                    :scheduled_at, :duration, :service_type, :modality, 'held',
                    :expires_at_utc, 'not_required', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                'clinic_id' => $clinicId,
                'prof_id' => $professionalId,
                'hold_token' => $holdToken,
                'idempotency_key' => $idempotencyKey,
                'scheduled_at' => $slotStart->format('Y-m-d H:i:s'),
                'duration' => $durationMinutes,
                'service_type' => $normalizedType,
                'modality' => $modality,
                'expires_at_utc' => $expiresAtUtc,
            ]);
            $holdId = (int)$this->db->lastInsertId();
            $this->audit($clinicId, 'BOOKING_HOLD_CREATED', (string)$holdId, ['modality' => $modality, 'service_type' => $normalizedType]);

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
     * Confirma a reserva e cria o agendamento canônico (appointments) na mesma transação.
     *
     * Contra concorrência: o profissional é travado (MySQL: FOR UPDATE) e o hold passa de
     * 'held' para 'converted' por UPDATE condicional. Duas confirmações simultâneas do mesmo
     * token criam uma única consulta; a segunda recebe a mesma consulta (idempotente).
     */
    public function confirmBooking(string $holdToken, array $confirmationData): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $holdToken)) {
            throw new InvalidArgumentException('Reserva não encontrada ou token inválido.');
        }
        $patient = $this->validatedPatientData($confirmationData);
        $termId = $this->acceptedClinicalCareTerm($confirmationData);
        $now = $this->now();
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());

        $peek = $this->db->prepare('SELECT professional_id FROM booking_holds WHERE hold_token = :token LIMIT 1');
        $peek->execute(['token' => $holdToken]);
        $professionalId = $peek->fetchColumn();
        if ($professionalId === false) {
            throw new InvalidArgumentException('Reserva não encontrada ou token inválido.');
        }

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            // Mesma ordem de travas da agenda interna e do createHold: profissional primeiro.
            $this->lockProfessional((int)$professionalId);

            $sel = 'SELECT * FROM booking_holds WHERE hold_token = :token LIMIT 1';
            if ($this->isMysql()) {
                $sel .= ' FOR UPDATE';
            }
            $stmt = $this->db->prepare($sel);
            $stmt->execute(['token' => $holdToken]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$hold) {
                throw new InvalidArgumentException('Reserva não encontrada ou token inválido.');
            }

            if ($hold['status'] === 'converted') {
                if ($ownsTransaction && $this->db->inTransaction()) {
                    $this->db->commit();
                }
                return [
                    'success' => true,
                    'already_confirmed' => true,
                    'appointment_id' => (int)$hold['appointment_id'],
                    'scheduled_at' => (string)$hold['scheduled_at'],
                    'status' => 'scheduled',
                ];
            }
            if ($hold['status'] !== 'held') {
                throw new HoldExpiredException('Esta reserva não está mais ativa. Selecione o horário novamente.');
            }
            if ((string)$hold['expires_at_utc'] <= $nowUtcStr) {
                $this->db->prepare("UPDATE booking_holds SET status = 'expired', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'held'")
                    ->execute(['id' => $hold['id']]);
                $this->audit((int)$hold['clinic_id'], 'BOOKING_HOLD_EXPIRED', (string)$hold['id'], null);
                if ($ownsTransaction && $this->db->inTransaction()) {
                    $this->db->commit();
                }
                throw new HoldExpiredException();
            }

            // Toma posse do hold. rowCount 0 = outra requisição chegou antes.
            $claim = $this->db->prepare("UPDATE booking_holds SET status = 'converted', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'held'");
            $claim->execute(['id' => $hold['id']]);
            if ($claim->rowCount() !== 1) {
                throw new BookingConflictException('Esta reserva já está sendo confirmada. Atualize a página.');
            }

            $clinicId = (int)$hold['clinic_id'];
            $scheduledAt = (string)$hold['scheduled_at'];
            $durationMinutes = (int)$hold['duration_minutes'];
            $modality = (string)$hold['modality'];
            $serviceType = (string)$hold['service_type'];
            $slotStart = new DateTimeImmutable($scheduledAt, $this->timezone);
            $slotEnd = $slotStart->modify('+' . $durationMinutes . ' minutes');

            // A agenda interna pode ter ocupado o horário durante o hold.
            if ($this->findAppointmentConflict($clinicId, (int)$professionalId, $slotStart, $slotEnd) !== null) {
                throw new BookingConflictException('O horário foi ocupado enquanto você preenchia os dados. Escolha outro horário.');
            }

            $patientId = $this->findOrCreatePatient($clinicId, $patient['name'], $patient['email'], $patient['phone']);
            // Consentimento para assistência clínica, com a versão do termo que o paciente viu.
            $this->db->prepare("
                INSERT INTO patient_consents (clinic_id, patient_id, consent_type, granted, ip_address, granted_at, term_id, source_channel, user_agent)
                VALUES (:c, :p, 'clinical_care', 1, :ip, :at, :t, 'self_service_booking', :ua)
            ")->execute([
                'c' => $clinicId, 'p' => $patientId, 'ip' => ($_SERVER['REMOTE_ADDR'] ?? null), 'at' => $now->format('Y-m-d H:i:s'),
                't' => $termId, 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]);
            $apptType = $modality === 'telehealth' ? 'telehealth' : $serviceType;

            $this->db->prepare("
                INSERT INTO appointments (
                    clinic_id, patient_id, professional_id, scheduled_at,
                    duration_minutes, status, type, notes, created_at, updated_at
                ) VALUES (
                    :clinic_id, :patient_id, :prof_id, :scheduled_at,
                    :duration, 'scheduled', :type, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ")->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'prof_id' => (int)$professionalId,
                'scheduled_at' => $scheduledAt,
                'duration' => $durationMinutes,
                'type' => $apptType,
                'notes' => $patient['notes'] !== '' ? $patient['notes'] : 'Autoagendamento online pelo paciente',
            ]);
            $appointmentId = (int)$this->db->lastInsertId();

            $this->db->prepare("
                INSERT INTO appointment_status_history (clinic_id, appointment_id, from_status, to_status, notes, created_at)
                VALUES (:clinic_id, :appt_id, NULL, 'scheduled', 'Autoagendamento self-service pelo paciente (portal/web)', CURRENT_TIMESTAMP)
            ")->execute(['clinic_id' => $clinicId, 'appt_id' => $appointmentId]);

            $teleSessionId = null;
            if ($modality === 'telehealth') {
                try {
                    $session = (new TeleconsultationService($this->db, null, null, $now))->scheduleSession($appointmentId, (int)$professionalId, $clinicId);
                    $teleSessionId = (int)($session['session_id'] ?? 0) ?: null;
                } catch (\Throwable $e) {
                    // A sala pode ser preparada depois pela agenda; a consulta continua confirmada.
                    error_log('[ClyoNutri][booking] teleconsulta não preparada para a consulta #' . $appointmentId . ': ' . get_class($e));
                }
            }

            // Dados pessoais vão para o cadastro do paciente; o hold guarda só o vínculo.
            $this->db->prepare("
                UPDATE booking_holds SET appointment_id = :appt_id, patient_id = :patient_id,
                    teleconsultation_session_id = :tele_id, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute(['appt_id' => $appointmentId, 'patient_id' => $patientId, 'tele_id' => $teleSessionId, 'id' => $hold['id']]);

            $this->audit($clinicId, 'BOOKING_CONFIRMED', (string)$appointmentId, ['hold_id' => (int)$hold['id'], 'modality' => $modality, 'service_type' => $serviceType]);

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

    /** Termo vigente de assistência clínica (o mesmo do cadastro feito pelo consultório). */
    public function activeClinicalCareTerm(): ?array
    {
        $row = $this->db->query("SELECT id, version, title FROM consent_terms WHERE purpose_code = 'clinical_care' AND is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Exige aceite explícito do termo vigente; versão diferente (página antiga) é recusada. */
    private function acceptedClinicalCareTerm(array $in): int
    {
        $accepted = filter_var($in['terms_accepted'] ?? false, FILTER_VALIDATE_BOOL);
        $term = $this->activeClinicalCareTerm();
        if ($term === null) {
            throw new RuntimeException('Termo de consentimento indisponível. Agendamento online temporariamente suspenso.');
        }
        if (!$accepted) {
            throw new InvalidArgumentException('Para agendar, é preciso aceitar o termo de uso dos dados para a assistência nutricional.');
        }
        if ((int)($in['consent_term_id'] ?? 0) !== (int)$term['id']) {
            throw new InvalidArgumentException('O termo de consentimento foi atualizado. Recarregue a página e leia a versão vigente.');
        }
        return (int)$term['id'];
    }

    /** Nome obrigatório e ao menos um contato válido; tamanhos limitados (dados mínimos). */
    private function validatedPatientData(array $in): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? '');
        $email = strtolower(trim((string)($in['email'] ?? '')));
        $phone = trim((string)($in['phone'] ?? ''));
        $notes = trim((string)($in['notes'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Informe o nome completo (3 a 150 caracteres).');
        }
        if ($email !== '' && (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('E-mail inválido.');
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($phone !== '' && (strlen($digits) < 10 || strlen($digits) > 15)) {
            throw new InvalidArgumentException('Telefone inválido. Informe DDD e número.');
        }
        if ($email === '' && $phone === '') {
            throw new InvalidArgumentException('Informe e-mail ou celular para receber a confirmação.');
        }
        if (mb_strlen($notes) > 500) {
            throw new InvalidArgumentException('A observação pode ter até 500 caracteres.');
        }
        return ['name' => $name, 'email' => $email, 'phone' => $phone, 'notes' => $notes];
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
            WHERE id = :id AND clinic_id = :clinic_id AND status = :status
        ");
        $stmt->execute(['id' => $appointmentId, 'clinic_id' => $clinicId, 'status' => $appt['status']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('A consulta mudou enquanto o cancelamento era feito. Atualize e tente de novo.');
        }
        $this->audit($clinicId, 'BOOKING_CANCELLED_BY_PATIENT', (string)$appointmentId, null);

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

        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $newScheduledAt)) {
            throw new InvalidArgumentException('Formato de data e hora inválido. Use AAAA-MM-DD HH:MM.');
        }
        $newStart = new DateTimeImmutable(str_replace('T', ' ', $newScheduledAt), $this->timezone);
        $duration = (int)($appt['duration_minutes'] ?? 60);
        $newEnd = $newStart->modify('+' . $duration . ' minutes');
        $professionalId = (int)$appt['professional_id'];
        if ($newStart->format('Y-m-d H:i:s') === (new DateTimeImmutable((string)$appt['scheduled_at'], $this->timezone))->format('Y-m-d H:i:s')) {
            throw new InvalidArgumentException('O novo horário é igual ao atual.');
        }
        $apptModality = TeleconsultationService::isOnlineType((string)($appt['type'] ?? '')) ? 'telehealth' : 'presential';

        // O novo horário precisa estar na disponibilidade real (jornada, pausa, antecedência),
        // desconsiderando a própria consulta.
        $offered = array_column($this->getAvailableSlots($clinicId, $professionalId, $newStart->format('Y-m-d'), $duration, $apptModality, $appointmentId), 'scheduled_at');
        if (!in_array($newStart->format('Y-m-d H:i:s'), $offered, true)) {
            throw new BookingConflictException('O novo horário (' . $newStart->format('d/m/Y H:i') . ') não está disponível.');
        }

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }
        try {
            $this->lockProfessional($professionalId);
            $nowUtcStr = gmdate('Y-m-d H:i:s', $this->now()->getTimestamp());
            if ($this->findAppointmentConflict($clinicId, $professionalId, $newStart, $newEnd, $appointmentId) !== null
                || $this->findActiveHoldConflict($clinicId, $professionalId, $newStart, $newEnd, $nowUtcStr) !== null) {
                throw new BookingConflictException('O novo horário solicitado (' . $newStart->format('d/m/Y H:i') . ') já está ocupado.');
            }

            $stmt = $this->db->prepare("
                UPDATE appointments
                SET scheduled_at = :new_time, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :clinic_id AND status = :status
            ");
            $stmt->execute([
                'new_time' => $newStart->format('Y-m-d H:i:s'),
                'id' => $appointmentId,
                'clinic_id' => $clinicId,
                'status' => $appt['status'],
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new BookingConflictException('A consulta mudou enquanto o reagendamento era feito. Atualize e tente de novo.');
            }

            $hist = $this->db->prepare("
                INSERT INTO appointment_status_history (
                    clinic_id, appointment_id, from_status, to_status, notes, created_at
                ) VALUES (
                    :clinic_id, :appt_id, :from_status, :to_status, :notes, CURRENT_TIMESTAMP
                )
            ");
            $hist->execute([
                'clinic_id' => $clinicId,
                'appt_id' => $appointmentId,
                'from_status' => $appt['status'],
                'to_status' => $appt['status'],
                'notes' => 'Reagendado pelo paciente de ' . (new DateTimeImmutable((string)$appt['scheduled_at'], $this->timezone))->format('d/m/Y H:i') . ' para ' . $newStart->format('d/m/Y H:i') . '. Motivo: ' . mb_substr(trim($reason), 0, 300),
            ]);
            $this->audit($clinicId, 'BOOKING_RESCHEDULED_BY_PATIENT', (string)$appointmentId, null);
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

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

    private function isMysql(): bool
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /** Mesma trava da agenda interna (AppointmentService): serializa marcações do profissional. */
    private function lockProfessional(int $professionalId): void
    {
        if ($this->isMysql()) {
            $this->db->prepare('SELECT id FROM professionals WHERE id = :id FOR UPDATE')->execute(['id' => $professionalId]);
        }
    }

    private function professionalInClinic(int $professionalId, int $clinicId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM professionals p JOIN clinic_members cm ON cm.user_id = p.user_id
            WHERE p.id = :p AND cm.clinic_id = :c AND cm.status = 'active' LIMIT 1
        ");
        $stmt->execute(['p' => $professionalId, 'c' => $clinicId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Auditoria sem dados pessoais (só ids e categorias). */
    private function audit(int $clinicId, string $action, ?string $resourceId, ?array $details): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details)
            VALUES (:clinic_id, NULL, :action, 'booking', :rid, :ip, :ua, :details)
        ")->execute([
            'clinic_id' => $clinicId,
            'action' => $action,
            'rid' => $resourceId,
            'ip' => $ip !== '' ? $ip : null,
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Internal'), 0, 255),
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function ts(string $localDateTime): int
    {
        return (new DateTimeImmutable($localDateTime, $this->timezone))->getTimestamp();
    }

    private function findAppointmentConflict(
        int $clinicId,
        int $professionalId,
        DateTimeImmutable $slotStart,
        DateTimeImmutable $slotEnd,
        ?int $excludeAppointmentId = null
    ): ?array {
        // Janela de um dia antes do início (consultas longas) até o fim do slot.
        $sql = "
            SELECT a.id, a.scheduled_at, a.duration_minutes
            FROM appointments a
            WHERE a.clinic_id = :clinic_id AND a.professional_id = :prof_id
              AND a.scheduled_at >= :window_start AND a.scheduled_at < :slot_end
              AND a.status NOT IN ('cancelled', 'no_show')
        ";
        $params = [
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'window_start' => $slotStart->modify('-1 day')->format('Y-m-d H:i:s'),
            'slot_end' => $slotEnd->format('Y-m-d H:i:s'),
        ];
        if ($excludeAppointmentId !== null) {
            $sql .= ' AND a.id <> :exclude_id';
            $params['exclude_id'] = $excludeAppointmentId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $startTs = $slotStart->getTimestamp();
        $endTs = $slotEnd->getTimestamp();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $apptStartTs = $this->ts((string)$row['scheduled_at']);
            $apptEndTs = $apptStartTs + (max(15, (int)($row['duration_minutes'] ?? 60)) * 60);
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
            SELECT id, scheduled_at, duration_minutes
            FROM booking_holds
            WHERE clinic_id = :clinic_id AND professional_id = :prof_id
              AND status = 'held' AND expires_at_utc > :now_utc
              AND scheduled_at >= :window_start AND scheduled_at < :slot_end
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'now_utc' => $nowUtcStr,
            'window_start' => $slotStart->modify('-1 day')->format('Y-m-d H:i:s'),
            'slot_end' => $slotEnd->format('Y-m-d H:i:s'),
        ]);
        $startTs = $slotStart->getTimestamp();
        $endTs = $slotEnd->getTimestamp();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $holdStartTs = $this->ts((string)$row['scheduled_at']);
            $holdEndTs = $holdStartTs + (max(15, (int)($row['duration_minutes'] ?? 60)) * 60);
            if ($startTs < $holdEndTs && $endTs > $holdStartTs) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Vincula ao paciente existente da clínica só quando e-mail E nome conferem (evita que um
     * visitante anexe a consulta ao prontuário de outra pessoa digitando só o e-mail dela).
     * Caso contrário, cria um cadastro novo, marcado como vindo do autoagendamento.
     */
    private function findOrCreatePatient(int $clinicId, string $name, string $email, string $phone): int
    {
        if ($email !== '') {
            $stmt = $this->db->prepare('SELECT id, name FROM patients WHERE clinic_id = :clinic_id AND LOWER(email) = :email');
            $stmt->execute(['clinic_id' => $clinicId, 'email' => $email]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (self::sameName((string)$row['name'], $name)) {
                    return (int)$row['id'];
                }
            }
        }

        $this->db->prepare("
            INSERT INTO patients (clinic_id, uuid, name, email, phone, status, notes, created_at, updated_at)
            VALUES (:clinic_id, :uuid, :name, :email, :phone, 'active', :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ")->execute([
            'clinic_id' => $clinicId,
            'uuid' => bin2hex(random_bytes(16)),
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'notes' => 'Cadastro criado pelo autoagendamento online. Confirme os dados na primeira consulta.',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public static function sameName(string $a, string $b): bool
    {
        $norm = static function (string $s): string {
            $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? ''));
            $t = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
            return preg_replace('/\p{Mn}+/u', '', (string)$t) ?? $s;
        };
        return $norm($a) !== '' && $norm($a) === $norm($b);
    }
}
