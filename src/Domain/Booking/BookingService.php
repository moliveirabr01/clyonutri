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
        $this->timezone = BookingCalendarService::tz();
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
        $stmt = $this->db->prepare("SELECT * FROM booking_settings WHERE clinic_id = :clinic_id LIMIT 1" . ($this->db->inTransaction() && $this->isMysql() ? ' FOR UPDATE' : ''));
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
                // NI2-137 (migração 47). Sem a coluna, vale o padrão: confirmar ligado, cancelar e remarcar desligados.
                'patient_can_confirm' => (bool)($row['patient_can_confirm'] ?? true),
                'patient_can_cancel' => (bool)($row['patient_can_cancel'] ?? false),
                'patient_can_request_reschedule' => (bool)($row['patient_can_request_reschedule'] ?? false),
                'patient_show_address' => (bool)($row['patient_show_address'] ?? true),
                // NI2-596 (migração 77): endereço na página pública do link, desligado por padrão.
                'public_show_address' => (bool)($row['public_show_address'] ?? false),
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
            'patient_can_confirm' => true,
            'patient_can_cancel' => false,
            'patient_can_request_reschedule' => false,
            'patient_show_address' => true,
            'public_show_address' => false,
        ];
    }

    /** NI2-137: antecedência mínima efetiva para a paciente cancelar ou pedir remarcação (nunca menos de 1 hora). */
    public static function patientMinAdvanceHours(array $settings, string $action): int
    {
        $field = $action === 'cancel' ? 'cancel_min_advance_hours' : 'reschedule_min_advance_hours';
        return max(1, (int)($settings[$field] ?? 4));
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
            // NI2-137 (B3): pelo app, cancelar ou pedir remarcação exige pelo menos 1 hora de antecedência.
            'cancel_min_advance_hours' => [1, 168], 'reschedule_min_advance_hours' => [1, 168],
        ] as $field => [$min, $max]) {
            if (!is_numeric($merged[$field]) || (int)$merged[$field] < $min || (int)$merged[$field] > $max) {
                throw new InvalidArgumentException("Valor inválido em {$field}: use de {$min} a {$max}.");
            }
        }
        foreach (['is_enabled', 'require_cpf', 'allow_telehealth', 'allow_presential', 'patient_can_confirm', 'patient_can_cancel', 'patient_can_request_reschedule', 'patient_show_address', 'public_show_address'] as $flag) {
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

        // NI2-137: ações da paciente no app (colunas da migração 47).
        if (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'booking_settings', 'patient_can_confirm')) {
            $this->db->prepare('
                UPDATE booking_settings SET patient_can_confirm = :pc, patient_can_cancel = :px,
                    patient_can_request_reschedule = :pr, patient_show_address = :pa
                WHERE clinic_id = :clinic_id
            ')->execute([
                'pc' => $merged['patient_can_confirm'] ? 1 : 0,
                'px' => $merged['patient_can_cancel'] ? 1 : 0,
                'pr' => $merged['patient_can_request_reschedule'] ? 1 : 0,
                'pa' => $merged['patient_show_address'] ? 1 : 0,
                'clinic_id' => $clinicId,
            ]);
        }

        if (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'booking_settings', 'public_show_address')) {
            $this->db->prepare('UPDATE booking_settings SET public_show_address = :ps WHERE clinic_id = :clinic_id')
                ->execute(['ps' => $merged['public_show_address'] ? 1 : 0, 'clinic_id' => $clinicId]);
        }

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
        ?int $excludeAppointmentId = null,
        bool $forPatientReschedule = false,
        ?int $excludeHoldId = null,
        bool $forInternalScheduling = false
    ): array {
        $settings = $this->getSettings($clinicId);
        if (!$this->professionalInClinic($professionalId, $clinicId)) {
            return [];
        }
        if ($forInternalScheduling) {
            $settings['min_advance_hours'] = 0;
            $settings['is_enabled'] = true;
            $settings['allow_presential'] = true;
            $settings['allow_telehealth'] = true;
        } elseif ($forPatientReschedule) {
            // NI2-137: o pedido de remarcação de quem já é paciente não depende da página pública de
            // autoagendamento (nem das modalidades oferecidas nela); a antecedência é a de remarcação.
            $settings['min_advance_hours'] = self::patientMinAdvanceHours($settings, 'reschedule');
        } else {
            if (!$settings['is_enabled']) {
                return [];
            }
            if ($modality === 'telehealth' && !$settings['allow_telehealth']) {
                return [];
            }
            if ($modality === 'presential' && !$settings['allow_presential']) {
                return [];
            }
        }

        $durationMinutes = max(15, min(240, $durationMinutes));
        $now = $this->now();

        // Validar data
        try {
            $requestedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
            if (!$requestedDate || $requestedDate->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Data inválida. Use AAAA-MM-DD.');
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Data inválida. Use o formato AAAA-MM-DD.");
        }

        // Não permitir datas anteriores a hoje ou além do limite futuro
        $today = new DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00', $this->timezone);
        if ($requestedDate < $today) {
            return [];
        }

        $maxFuture = $today->modify('+' . $settings['max_future_days'] . ' days');
        if (!$forInternalScheduling && $requestedDate > $maxFuture) {
            return [];
        }

        // Disponibilidade semanal configurada pelo profissional em Configurações. Sem configuração,
        // não há horário a oferecer: a página pública não inventa jornada (NI2-433, "horários
        // realmente disponíveis").
        $prefs = $this->getProfessionalPreferences($clinicId, $professionalId);
        $weeklyAvail = $prefs['weekly_availability'] ?? [];
        $calendar = new BookingCalendarService($this->db);
        $windows = $calendar->windows($clinicId, $professionalId, $date, is_array($weeklyAvail) ? $weeklyAvail : []);
        if ($windows === []) return [];
        $policy = $calendar->policy($clinicId);
        $workStart = (new DateTimeImmutable('@' . $windows[0][0]))->setTimezone($this->timezone)->modify('+' . (int)$policy['buffer_before_minutes'] . ' minutes');
        $workEnd = (new DateTimeImmutable('@' . $windows[count($windows)-1][1]))->setTimezone($this->timezone);

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
            'end_of_day' => $requestedDate->modify('+2 days')->format('Y-m-d H:i:s'),
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
              AND (:exclude_hold IS NULL OR id <> :exclude_hold_id)
        ");
        $holdsStmt->execute([
            'clinic_id' => $clinicId,
            'prof_id' => $professionalId,
            'start_window' => $requestedDate->modify('-1 day')->format('Y-m-d H:i:s'),
            'end_of_day' => $requestedDate->modify('+2 days')->format('Y-m-d H:i:s'),
            'now_utc' => $nowUtcStr,
            'exclude_hold' => $excludeHoldId,
            'exclude_hold_id' => $excludeHoldId,
        ]);
        $existingHolds = $holdsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Limite mínimo de antecedência
        $minAdvanceLimit = $now->modify('+' . $settings['min_advance_hours'] . ' hours');

        // v54.39: horários candidatos no passo da duração da consulta, começando no início de cada
        // janela (expediente e retorno da pausa). Ex.: 08:00–18:00, pausa 12:00–13:00, 60 min ->
        // 08, 09, 10, 11, 13, 14, 15, 16, 17. Antes o passo era fixo em 15 minutos.
        $slotStep = $durationMinutes;
        $slots = [];
        $candidates = [];
        foreach ($windows as [$windowStart, $windowEnd]) {
            $c = (new DateTimeImmutable('@' . ((int)$windowStart + 60 * (int)$policy['buffer_before_minutes'])))->setTimezone($this->timezone);
            while ($c < $workEnd && $c->getTimestamp() < (int)$windowEnd) { $candidates[$c->getTimestamp()] = $c; $c = $c->modify('+' . $slotStep . ' minutes'); }
        }
        ksort($candidates);

        foreach ($candidates as $cursor) {
            $slotStart = $cursor;
            $slotEnd = $cursor->modify('+' . $durationMinutes . ' minutes');

            if ($slotEnd > $workEnd) {
                continue;
            }

            $isAvailable = true;

            // 1. Respeita antecedência mínima
            if ($slotStart < $minAdvanceLimit) {
                $isAvailable = false;
            }

            // The same calendar policy is enforced by internal appointments and hold conversion.
            if ($isAvailable) {
                $isAvailable=false;
                foreach($windows as [$windowStart,$windowEnd]) {
                    if($slotStart->getTimestamp()-60*(int)$policy['buffer_before_minutes'] >= $windowStart
                        && $slotEnd->getTimestamp()+60*(int)$policy['buffer_after_minutes'] <= $windowEnd){$isAvailable=true;break;}
                }
            }

            // 3. Não colide com consultas existentes
            if ($isAvailable) {
                foreach ($existingAppts as $appt) {
                    $apptStart = new DateTimeImmutable((string)$appt['scheduled_at'], $this->timezone);
                    $apptDuration = max(15, (int)($appt['duration_minutes'] ?? 60));
                    $apptEnd = $apptStart->modify('+' . $apptDuration . ' minutes');

                    if ($calendar->overlaps($clinicId, $slotStart->format('Y-m-d H:i:s'), $durationMinutes, $appt['scheduled_at'], $apptDuration, $policy)) {
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

                    if ($calendar->overlaps($clinicId, $slotStart->format('Y-m-d H:i:s'), $durationMinutes, $hold['scheduled_at'], $holdDuration, $policy)) {
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
        }

        return $slots;
    }

    /**
     * Profissional apto a receber autoagendamento: usuário, vínculo e clínica ativos e
     * autoagendamento ligado na clínica. Retorna só dados públicos (nunca e-mail).
     * Suporta busca por ID numérico ou slug amigável (NI2-596).
     */
    /**
     * Profissional apto ao autoagendamento.
     *
     * - Número: uso interno (APIs de horários e reservas, fila de espera). A página pública com número
     *   é tratada em BookingController::publicBookingPage (redireciona para o apelido ou 404).
     * - Texto: apelido único gravado em professionals.booking_slug (NI2-596). Não há mais busca por
     *   nome nem "nutri-{id}": dois profissionais com o mesmo nome nunca se confundem e nenhuma
     *   página pública varre a tabela de profissionais.
     */
    public function publicProfessional(int|string $identifier): ?array
    {
        if (is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) {
            $professionalId = (int)$identifier;
        } else {
            $professionalId = (int)((new PublicBookingSlug($this->db))->resolve((string)$identifier) ?? 0);
        }
        if ($professionalId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT p.id, cm.clinic_id, p.user_id, u.name, c.name AS clinic_name, c.logo_path,
                   c.address_street, c.address_number, c.address_complement, c.address_neighborhood,
                   c.address_city, c.address_state, c.address_zip
            FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id
            JOIN clinics c ON c.id = cm.clinic_id
            WHERE p.id = :ident
              AND u.status = 'active'
              AND c.status = 'active'
              AND cm.status = 'active'
              AND cm.role IN ('owner','admin','nutritionist')
              AND u.role IN ('owner','admin','nutritionist')
            LIMIT 1
        ");
        $stmt->execute(['ident' => $professionalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $settings = $this->getSettings((int)$row['clinic_id']);
        if (!$settings['is_enabled'] || $settings['require_cpf']) {
            return null;
        }

        // Endereço: só com a opção própria "Mostrar endereço no link de agendamento" (desligada por padrão).
        // Quem atende em casa não tem o endereço residencial exposto a qualquer pessoa com o link.
        $clinicAddress = null;
        if (!empty($settings['public_show_address'])) {
            $addrParts = [];
            if (!empty($row['address_street'])) {
                $street = trim((string)$row['address_street']);
                if (!empty($row['address_number'])) {
                    $street .= ', ' . trim((string)$row['address_number']);
                }
                if (!empty($row['address_complement'])) {
                    $street .= ' (' . trim((string)$row['address_complement']) . ')';
                }
                $addrParts[] = $street;
            }
            if (!empty($row['address_neighborhood'])) {
                $addrParts[] = trim((string)$row['address_neighborhood']);
            }
            if (!empty($row['address_city'])) {
                $city = trim((string)$row['address_city']);
                if (!empty($row['address_state'])) {
                    $city .= '/' . trim((string)$row['address_state']);
                }
                $addrParts[] = $city;
            }
            if (!empty($row['address_zip'])) {
                $addrParts[] = 'CEP ' . trim((string)$row['address_zip']);
            }
            $clinicAddress = $addrParts !== [] ? implode(' - ', $addrParts) : null;
        }

        // Valor de referência: só quando a nutricionista salvou um valor (sem valor inventado).
        $defaultPrice = null;
        try {
            $prefStmt = $this->db->prepare("SELECT practice_preferences FROM onboarding_progress WHERE clinic_id = ? AND user_id = ?");
            $prefStmt->execute([(int)$row['clinic_id'], (int)$row['user_id']]);
            $prefRaw = $prefStmt->fetchColumn();
            if ($prefRaw) {
                $prefs = json_decode((string)$prefRaw, true) ?: [];
                if (isset($prefs['default_price']) && is_numeric($prefs['default_price']) && (float)$prefs['default_price'] > 0) {
                    $defaultPrice = (float)$prefs['default_price'];
                }
            }
        } catch (\Throwable $e) {
            $prefs = [];
            $defaultPrice = null;
        }

        return [
            'id' => (int)$row['id'],
            'clinic_id' => (int)$row['clinic_id'],
            'name' => (string)$row['name'],
            'clinic_name' => (string)$row['clinic_name'],
            'logo_path' => $row['logo_path'] ?? null,
            'duration_minutes' => $this->bookingDuration((int)$row['clinic_id'], (int)$row['id']),
            'clinic_address' => $clinicAddress,
            'default_price' => $defaultPrice,
            'slug' => (new PublicBookingSlug($this->db))->current((int)$row['id']),
        ];
    }

    /**
     * Lista pública de nutricionistas que habilitaram o link online e o autoatendimento (is_enabled = 1 e require_cpf = 0).
     * Retorna apenas dados públicos seguros para vitrine e agendamento.
     */
    public function listPublicDirectory(?string $query = null, ?string $topic = null): array
    {
        $stmt = $this->db->query("
            SELECT p.id, p.user_id, p.crn, p.crn_region, p.specialty, p.bio,
                   u.name, u.phone AS user_phone,
                   cm.clinic_id,
                   c.name AS clinic_name, c.phone AS clinic_phone, c.logo_path,
                   c.address_city, c.address_state,
                   bs.allow_telehealth, bs.allow_presential, bs.public_show_address
            FROM professionals p
            JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id
            JOIN clinics c ON c.id = cm.clinic_id
            JOIN booking_settings bs ON bs.clinic_id = c.id
            WHERE u.status = 'active'
              AND c.status = 'active'
              AND cm.status = 'active'
              AND cm.role IN ('owner', 'admin', 'nutritionist')
              AND u.role IN ('owner', 'admin', 'nutritionist')
              AND bs.is_enabled = 1
              AND bs.require_cpf = 0
              AND (bs.allow_telehealth = 1 OR bs.allow_presential = 1)
            ORDER BY p.id ASC
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($rows === []) {
            return [];
        }

        $slugger = new PublicBookingSlug($this->db);
        $focusLabels = [
            'emagrecimento' => 'Emagrecimento',
            'weight_loss' => 'Emagrecimento',
            'hipertrofia' => 'Hipertrofia e Esporte',
            'sports' => 'Nutrição Esportiva',
            'esportiva' => 'Nutrição Esportiva',
            'reeducacao' => 'Reeducação Alimentar',
            'reeducacao_alimentar' => 'Reeducação Alimentar',
            'clinica' => 'Nutrição Clínica',
            'clinical' => 'Nutrição Clínica',
            'materno_infantil' => 'Materno-Infantil',
            'saude_mulher' => 'Saúde da Mulher',
            'diabetes' => 'Diabetes e Metabólica',
            'intestinal' => 'Saúde Intestinal',
            'vegetariana' => 'Vegetariana e Vegana',
            'longevidade' => 'Longevidade e Prevenção',
        ];
        $dowShort = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
        $todayStr = $this->now()->format('Y-m-d');
        $norm = static function (string $s): string {
            $s = mb_strtolower(trim($s), 'UTF-8');
            $map = [
                'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
                'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
            ];
            return strtr($s, $map);
        };
        $qNorm = $query !== null && trim($query) !== '' ? $norm($query) : null;
        $tNorm = $topic !== null && trim($topic) !== '' ? $norm($topic) : null;

        $seenProfs = [];
        $items = [];
        foreach ($rows as $row) {
            $profId = (int)$row['id'];
            if (isset($seenProfs[$profId])) {
                continue;
            }
            $seenProfs[$profId] = true;
            $clinicId = (int)$row['clinic_id'];
            $userId = (int)$row['user_id'];
            $name = trim((string)$row['name']);

            // Format CRN cleanly without duplicating "CRN"
            $rawCrn = trim((string)($row['crn'] ?? ''));
            $rawRegion = trim((string)($row['crn_region'] ?? ''));
            $crnDisplay = '';
            if ($rawCrn !== '') {
                if (stripos($rawCrn, 'CRN') === 0) {
                    $crnDisplay = $rawCrn;
                } elseif ($rawRegion !== '') {
                    $crnDisplay = (stripos($rawRegion, 'CRN') === 0 ? $rawRegion : 'CRN-' . $rawRegion) . ' ' . $rawCrn;
                } else {
                    $crnDisplay = 'CRN ' . $rawCrn;
                }
            } elseif ($rawRegion !== '') {
                $crnDisplay = stripos($rawRegion, 'CRN') === 0 ? $rawRegion : 'CRN-' . $rawRegion;
            }

            // Read onboarding preferences & focus objectives
            $prefs = [];
            $defaultPrice = null;
            $focusTags = [];
            $contextualNotes = '';
            try {
                $opStmt = $this->db->prepare("SELECT focus_objectives, practice_preferences, contextual_notes FROM onboarding_progress WHERE clinic_id = ? AND user_id = ? LIMIT 1");
                $opStmt->execute([$clinicId, $userId]);
                $opRow = $opStmt->fetch(PDO::FETCH_ASSOC);
                if ($opRow) {
                    $prefs = json_decode((string)($opRow['practice_preferences'] ?? '{}'), true) ?: [];
                    if (isset($prefs['default_price']) && is_numeric($prefs['default_price']) && (float)$prefs['default_price'] > 0) {
                        $defaultPrice = (float)$prefs['default_price'];
                    }
                    $focusRaw = json_decode((string)($opRow['focus_objectives'] ?? '[]'), true);
                    if (is_array($focusRaw)) {
                        foreach ($focusRaw as $fKey) {
                            if (!is_string($fKey) || trim($fKey) === '') continue;
                            $fClean = strtolower(trim($fKey));
                            $focusTags[] = $focusLabels[$fClean] ?? ucfirst(str_replace(['_', '-'], ' ', trim($fKey)));
                        }
                    }
                    $contextualNotes = trim((string)($opRow['contextual_notes'] ?? ''));
                }
            } catch (\Throwable $e) {
                error_log('[ClyoNutri][NI2-892] contexto opcional do agendamento indisponível: ' . get_class($e) . ' ' . (string)$e->getCode());
            }

            // v54.59: especialidade/temas só aparecem no diretório público após confirmação
            // explícita do perfil (regra da v54.52); sem etiquetas genéricas inventadas.
            $confirmed = \ClyoNutri\Domain\Settings\PublicProfileConfirmation::isConfirmed($row, $prefs ?? []);
            $tags = [];
            $specialty = $confirmed ? trim((string)($row['specialty'] ?? '')) : '';
            if ($specialty !== '') {
                foreach (preg_split('/[,;\/]+|\s+e\s+/iu', $specialty) ?: [] as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $tags[] = $part;
                    }
                }
            }
            if ($confirmed) {
                foreach ($focusTags as $ft) {
                    $tags[] = $ft;
                }
            }
            if (!empty($row['allow_telehealth'])) {
                $tags[] = 'Teleconsulta';
            }
            if (!empty($row['allow_presential'])) {
                $tags[] = 'Presencial';
            }
            $uniqueTags = [];
            foreach ($tags as $tg) {
                $k = $norm($tg);
                if ($k !== '' && !isset($uniqueTags[$k])) {
                    $uniqueTags[$k] = $tg;
                }
            }
            $tags = array_values($uniqueTags);

            $bio = $confirmed ? trim((string)($row['bio'] ?? '')) : '';

            // Filter by search query and topic
            $haystack = $norm(implode(' ', [$name, $crnDisplay, (string)$row['clinic_name'], $specialty, $bio, implode(' ', $tags), (string)($row['address_city'] ?? ''), (string)($row['address_state'] ?? '')]));
            if ($qNorm !== null && !str_contains($haystack, $qNorm)) {
                continue;
            }
            if ($tNorm !== null && !str_contains($haystack, $tNorm)) {
                continue;
            }

            $duration = $this->bookingDuration($clinicId, $profId);
            $slug = $slugger->ensure($profId, $name);
            $bookingUrl = '/agendar/' . rawurlencode($slug !== null && $slug !== '' ? $slug : (string)$profId);

            // Next available slot within 14 days
            $nextSlotLabel = null;
            $modalityForSlot = !empty($row['allow_telehealth']) ? 'telehealth' : 'presential';
            $todayObj = new DateTimeImmutable($todayStr, $this->timezone);
            for ($dayOffset = 0; $dayOffset < 14; $dayOffset++) {
                $dObj = $todayObj->modify("+{$dayOffset} days");
                $dStr = $dObj->format('Y-m-d');
                try {
                    $daySlots = $this->getAvailableSlots($clinicId, $profId, $dStr, $duration, $modalityForSlot);
                } catch (\Throwable) {
                    $daySlots = [];
                }
                if (!empty($daySlots[0]['time'])) {
                    $slotTime = (string)$daySlots[0]['time'];
                    $shortTime = str_ends_with($slotTime, ':00') ? substr($slotTime, 0, 2) . 'h' : str_replace(':', 'h', $slotTime);
                    $shortTime = ltrim($shortTime, '0');
                    if (str_starts_with($shortTime, 'h')) {
                        $shortTime = '0' . $shortTime;
                    }
                    if ($dayOffset === 0) {
                        $nextSlotLabel = "Próximo: Hoje, {$shortTime}";
                    } elseif ($dayOffset === 1) {
                        $nextSlotLabel = "Próximo: Amanhã, {$shortTime}";
                    } elseif ($dayOffset < 7) {
                        $nextSlotLabel = 'Próximo: ' . $dowShort[(int)$dObj->format('w')] . ', ' . $shortTime;
                    } else {
                        $nextSlotLabel = 'Próximo: ' . $dObj->format('d/m') . ', ' . $shortTime;
                    }
                    break;
                }
            }

            // Phone / WhatsApp URL
            $rawPhone = trim((string)($row['user_phone'] ?: ($row['clinic_phone'] ?? '')));
            $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';
            $whatsappUrl = null;
            if (strlen($digits) >= 10 && strlen($digits) <= 13) {
                $waNumber = strlen($digits) <= 11 ? '55' . $digits : $digits;
                $waText = rawurlencode("Olá {$name}, encontrei seu perfil no Clyo Nutri e gostaria de tirar uma dúvida sobre consulta.");
                $whatsappUrl = "https://wa.me/{$waNumber}?text={$waText}";
            }

            // Initials for avatar
            $cleanWords = array_values(array_filter(
                preg_split('/\s+/u', preg_replace('/^(dr\.?|dra\.?|nutri\.?)\s+/iu', '', $name) ?? $name) ?: [],
                static fn($w) => mb_strlen($w, 'UTF-8') > 1
            ));
            $initials = 'CN';
            if (count($cleanWords) >= 2) {
                $initials = mb_strtoupper(mb_substr($cleanWords[0], 0, 1, 'UTF-8') . mb_substr($cleanWords[count($cleanWords) - 1], 0, 1, 'UTF-8'), 'UTF-8');
            } elseif (count($cleanWords) === 1) {
                $initials = mb_strtoupper(mb_substr($cleanWords[0], 0, 2, 'UTF-8'), 'UTF-8');
            }

            $cityState = '';
            if (!empty($row['public_show_address']) && !empty($row['address_city'])) {
                $cityState = trim((string)$row['address_city']) . (!empty($row['address_state']) ? '/' . trim((string)$row['address_state']) : '');
            }

            $items[] = [
                'id' => $profId,
                'clinic_id' => $clinicId,
                'name' => $name,
                'initials' => $initials,
                'clinic_name' => (string)$row['clinic_name'],
                'crn_display' => $crnDisplay,
                'city_state' => $cityState,
                'logo_path' => $row['logo_path'] ?? null,
                'specialty' => $specialty,
                'tags' => $tags,
                'bio' => $bio,
                'duration_minutes' => $duration,
                'default_price' => $defaultPrice,
                'next_slot_label' => $nextSlotLabel,
                'allow_telehealth' => (bool)$row['allow_telehealth'],
                'allow_presential' => (bool)$row['allow_presential'],
                'booking_url' => $bookingUrl,
                'whatsapp_url' => $whatsappUrl,
            ];
        }

        return $items;
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
            throw new InvalidArgumentException('Chave de idempotência inválida. Use até 64 caracteres: letras, números, ponto, dois-pontos, hífen ou sublinhado.');
        }

        // A duração é a do profissional; o valor enviado pelo cliente é ignorado.
        $durationMinutes = $prof['duration_minutes'];
        $now = $this->now();
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());

        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $scheduledAt)) {
            throw new InvalidArgumentException('Formato de data e hora inválido. Use AAAA-MM-DD HH:MM.');
        }
        try {
            $slotStart = new DateTimeImmutable(str_replace('T', ' ', $scheduledAt), $this->timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Formato de data e hora inválido.');
        }
        if ($slotStart->format('Y-m-d H:i:s') !== str_replace('T', ' ', $scheduledAt).(strlen($scheduledAt)===16?':00':'')) throw new InvalidArgumentException('Data e hora inexistentes.');
        $slotEnd = $slotStart->modify('+' . $durationMinutes . ' minutes');

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $this->lockBookingSettings($clinicId);
            $this->lockProfessional($professionalId);
            TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
            $this->assertBookingEligible($clinicId, $professionalId, $modality);
            if ($idempotencyKey !== null) {
                $replay = $this->db->prepare("SELECT * FROM booking_holds WHERE clinic_id = ? AND idempotency_key = ? AND status = 'held' AND expires_at_utc > ? ORDER BY id LIMIT 1" . ($this->isMysql() ? ' FOR UPDATE' : ''));
                $replay->execute([$clinicId, $idempotencyKey, $nowUtcStr]);
                $existing = $replay->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    if ((int)$existing['professional_id'] !== $professionalId
                        || $existing['scheduled_at'] !== $slotStart->format('Y-m-d H:i:s')
                        || (int)$existing['duration_minutes'] !== $durationMinutes
                        || $existing['service_type'] !== $normalizedType || $existing['modality'] !== $modality) {
                        throw new BookingConflictException('Esta chave de solicitação já foi usada para outra reserva. Gere uma nova chave ao trocar os dados.');
                    }
                    $exp = new DateTimeImmutable($existing['expires_at_utc'], new DateTimeZone('UTC'));
                    if ($ownsTransaction) { $this->db->commit(); }
                    return [
                        'id' => (int)$existing['id'], 'hold_token' => $existing['hold_token'], 'status' => 'held',
                        'scheduled_at' => $existing['scheduled_at'], 'duration_minutes' => (int)$existing['duration_minutes'],
                        'expires_at_utc' => $existing['expires_at_utc'],
                        'ttl_seconds_remaining' => max(0, $exp->getTimestamp() - $now->getTimestamp()), 'idempotent_replay' => true,
                    ];
                }
            }
            // Check availability only after replay: this request's own hold occupies the slot.
            $offered = array_column($this->getAvailableSlots($clinicId, $professionalId, $slotStart->format('Y-m-d'), $durationMinutes, $modality), 'scheduled_at');
            if (!in_array($slotStart->format('Y-m-d H:i:s'), $offered, true)) {
                throw new BookingConflictException('O horário ' . $slotStart->format('d/m/Y H:i') . ' não está disponível. Escolha outro horário da lista.');
            }

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
            (new BookingFlowMetrics($this->db))->count($clinicId,'hold_created');
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
        return $this->completeBooking($holdToken, $confirmationData);
    }

    public function authenticatedPatient(): array
    {
        $actor = \ClyoNutri\Core\Auth\TenantContext::getUser();
        $clinicId = \ClyoNutri\Core\Auth\TenantContext::getClinicId();
        if (($actor['role'] ?? '') !== 'patient') {
            throw new BookingUnavailableException();
        }
        $st = $this->db->prepare("SELECT p.id, p.clinic_id, p.name, p.email, p.phone FROM patients p JOIN clinics c ON c.id = p.clinic_id WHERE p.id = ? AND p.clinic_id = ? AND p.status = 'active' AND c.status = 'active'");
        $st->execute([(int)($actor['id'] ?? 0), $clinicId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: throw new BookingUnavailableException();
    }

    public function confirmBookingForPatient(string $holdToken, array $confirmationData): array
    {
        $patient = $this->authenticatedPatient();
        // HTTP patient_id/name/contact can never choose or overwrite the authenticated identity.
        $confirmationData = array_merge($confirmationData, [
            'name' => $patient['name'], 'email' => $patient['email'] ?? '', 'phone' => $patient['phone'] ?? '',
        ]);
        return $this->completeBooking($holdToken, $confirmationData, $patient);
    }

    private function completeBooking(string $holdToken, array $confirmationData, ?array $authenticatedPatient = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $holdToken)) {
            throw new InvalidArgumentException('Reserva não encontrada ou token inválido.');
        }
        $patient = $this->validatedPatientData($confirmationData);
        $termId = $this->acceptedClinicalCareTerm($confirmationData);
        $now = $this->now();
        $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());

        $peek = $this->db->prepare('SELECT professional_id, clinic_id FROM booking_holds WHERE hold_token = :token LIMIT 1');
        $peek->execute(['token' => $holdToken]);
        $holdIdentity = $peek->fetch(PDO::FETCH_ASSOC);
        $professionalId = $holdIdentity['professional_id'] ?? null;
        if ($professionalId === null) {
            throw new InvalidArgumentException('Reserva não encontrada ou token inválido.');
        }

        $ownsTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            // Reservas públicas travam a configuração da clínica e depois o profissional; a agenda interna usa a mesma trava de profissional.
            $this->lockBookingSettings((int)$holdIdentity['clinic_id']);
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

            $now = $this->now();
            $nowUtcStr = gmdate('Y-m-d H:i:s', $now->getTimestamp());

            if ($authenticatedPatient !== null && ((int)$hold['clinic_id'] !== (int)$authenticatedPatient['clinic_id']
                || ($hold['status'] === 'converted' && (int)$hold['patient_id'] !== (int)$authenticatedPatient['id']))) {
                throw new BookingUnavailableException();
            }

            if ($hold['status'] === 'held' && !empty($hold['patient_id'])
                && ($authenticatedPatient === null || (int)$hold['patient_id'] !== (int)$authenticatedPatient['id'])) {
                throw new BookingUnavailableException();
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

            $this->assertBookingEligible((int)$hold['clinic_id'], (int)$professionalId, (string)$hold['modality']);
            $offered = array_column($this->getAvailableSlots((int)$hold['clinic_id'], (int)$professionalId,
                substr($hold['scheduled_at'], 0, 10), (int)$hold['duration_minutes'], (string)$hold['modality'], null, false, (int)$hold['id']), 'scheduled_at');
            if (!in_array($hold['scheduled_at'], $offered, true)) {
                throw new BookingConflictException('As regras de disponibilidade mudaram. Escolha um horário disponível novamente.');
            }

            if ($authenticatedPatient !== null) {
                $identity = $this->db->prepare("SELECT id FROM patients WHERE id = ? AND clinic_id = ? AND status = 'active'" . ($this->isMysql() ? ' FOR UPDATE' : ''));
                $identity->execute([(int)$authenticatedPatient['id'], (int)$hold['clinic_id']]);
                if (!$identity->fetchColumn()) { throw new BookingUnavailableException(); }
            }

            if (!empty($hold['patient_id']) && empty((new BookingCalendarService($this->db))->policy((int)$hold['clinic_id'])['waitlist_enabled'])) throw new BookingUnavailableException('A lista de espera está pausada.');

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

            $patientId = $authenticatedPatient !== null ? (int)$authenticatedPatient['id'] : $this->findOrCreatePatient($clinicId, $patient['name'], $patient['email'], $patient['phone']);
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
            // NI2-138: consulta marcada pela paciente com o profissional também abre o vínculo do chat.
            (new \ClyoNutri\Domain\Chat\PatientLinkService($this->db))->ensureFromCare($clinicId, (int)$patientId, (int)$professionalId, 'self_booking');

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

            if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'booking_waitlist')) {
                $this->db->prepare("UPDATE booking_waitlist SET status='fulfilled', active_key=NULL, updated_at=? WHERE clinic_id=? AND patient_id=? AND hold_id=? AND status='offered'")
                    ->execute([$now->format('Y-m-d H:i:s'),$clinicId,$patientId,$hold['id']]);
            }

            (new \ClyoNutri\Domain\Notification\AppointmentConfirmationPublisher($this->db))->publish($clinicId, $appointmentId);

            (new BookingFlowMetrics($this->db))->count($clinicId,'booking_confirmed');
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

    // =========================================================================
    // Métodos Auxiliares Internos
    // =========================================================================

    /**
     * Verifica se há horários livres nos próximos N dias para a modalidade presencial ou online (NI2-584).
     */
    public function hasAvailableSlotsInNextDays(int $clinicId, int $professionalId, int $days = 14, bool $forInternalScheduling = false): bool
    {
        $settings = $this->getSettings($clinicId);
        if (!$forInternalScheduling && empty($settings['is_enabled'])) {
            return false;
        }
        $today = new DateTimeImmutable('today', $this->timezone);
        $duration = 60;
        $prefs = $this->getProfessionalPreferences($clinicId, $professionalId);
        if (!empty($prefs['default_duration_minutes']) && is_numeric($prefs['default_duration_minutes'])) {
            $duration = (int)$prefs['default_duration_minutes'];
        }
        $modality = !empty($settings['allow_presential']) || $forInternalScheduling ? 'presential' : 'telehealth';
        for ($i = 0; $i < $days; $i++) { // para no primeiro dia com horário
            $checkDate = $today->modify("+{$i} days")->format('Y-m-d');
            $slots = $this->getAvailableSlots($clinicId, $professionalId, $checkDate, $duration, $modality, null, false, null, $forInternalScheduling);
            if (!empty($slots)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Preferências salvas (leitura pura). NI2-584: conta antiga só com working_days continua sem horários
     * públicos até a nutricionista confirmar a disponibilidade em Configurações (nada é gravado ao ler).
     */
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

    private function lockBookingSettings(int $clinicId): void
    {
        if ($this->isMysql()) {
            // Serializes idempotency within a clinic, including requests to different professionals.
            $this->db->prepare('SELECT clinic_id FROM booking_settings WHERE clinic_id = ? FOR UPDATE')->execute([$clinicId]);
        }
    }

    private function assertBookingEligible(int $clinicId, int $professionalId, string $modality): void
    {
        $sql = "SELECT p.id FROM professionals p JOIN users u ON u.id = p.user_id
            JOIN clinic_members cm ON cm.user_id = p.user_id JOIN clinics c ON c.id = cm.clinic_id
            WHERE p.id = ? AND cm.clinic_id = ? AND cm.status = 'active' AND c.status = 'active'
              AND u.status = 'active' AND cm.role IN ('owner','admin','nutritionist') AND u.role IN ('owner','admin','nutritionist')";
        $st = $this->db->prepare($sql . ($this->isMysql() ? ' FOR UPDATE' : ''));
        $st->execute([$professionalId, $clinicId]);
        if (!$st->fetchColumn()) { throw new BookingUnavailableException(); }
        $settings = $this->getSettings($clinicId);
        if (!$settings['is_enabled'] || $settings['require_cpf'] || !in_array($modality, self::MODALITIES, true)
            || ($modality === 'telehealth' && !$settings['allow_telehealth'])
            || ($modality === 'presential' && !$settings['allow_presential'])) {
            throw new BookingUnavailableException();
        }
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
            'slot_end' => $slotEnd->modify('+' . array_sum(array_intersect_key((new BookingCalendarService($this->db))->policy($clinicId), array_flip(['buffer_before_minutes','buffer_after_minutes']))) . ' minutes')->format('Y-m-d H:i:s'),
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
            if ((new BookingCalendarService($this->db))->overlaps($clinicId, $slotStart->format('Y-m-d H:i:s'), (int)(($endTs-$startTs)/60), (string)$row['scheduled_at'], max(15, (int)($row['duration_minutes'] ?? 60)))) {
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
            'slot_end' => $slotEnd->modify('+' . array_sum(array_intersect_key((new BookingCalendarService($this->db))->policy($clinicId), array_flip(['buffer_before_minutes','buffer_after_minutes']))) . ' minutes')->format('Y-m-d H:i:s'),
        ]);
        $startTs = $slotStart->getTimestamp();
        $endTs = $slotEnd->getTimestamp();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $holdStartTs = $this->ts((string)$row['scheduled_at']);
            $holdEndTs = $holdStartTs + (max(15, (int)($row['duration_minutes'] ?? 60)) * 60);
            if ((new BookingCalendarService($this->db))->overlaps($clinicId, $slotStart->format('Y-m-d H:i:s'), (int)(($endTs-$startTs)/60), (string)$row['scheduled_at'], max(15, (int)($row['duration_minutes'] ?? 60)))) {
                return $row;
            }
        }
        return null;
    }

    /** Autoagendamento anônimo nunca comprova a identidade de um paciente existente. */
    private function findOrCreatePatient(int $clinicId, string $name, string $email, string $phone): int
    {
        $stmt = $this->db->prepare("SELECT id FROM patients WHERE clinic_id = :clinic_id AND ((:email_present <> '' AND LOWER(TRIM(email)) = :email) OR (:phone_present <> '' AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),' ',''),'-',''),'(',''),')',''),'+',''),'.','') = :phone)) LIMIT 1");
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        $stmt->execute(['clinic_id' => $clinicId, 'email_present' => $email, 'email' => strtolower($email), 'phone_present' => $phoneDigits, 'phone' => $phoneDigits]);
        if ($stmt->fetchColumn() !== false) {
            throw new \InvalidArgumentException('Não foi possível concluir o autoagendamento. Entre no Portal do Paciente para confirmar sua identidade e concluir o agendamento.');
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
