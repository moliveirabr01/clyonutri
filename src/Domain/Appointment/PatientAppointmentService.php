<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Appointment;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Core\I18n\LabelMap;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Booking\BookingService;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * NI2-137: próxima consulta da paciente e o que ela pode fazer com ela (confirmar, cancelar, pedir remarcação).
 *
 * - Toda mudança de status passa pelo motor da agenda (AppointmentService): trava, máquina de estados,
 *   ganchos de teleconsulta e histórico com a paciente como autora.
 * - O que é permitido vem da clínica (booking_settings, migração 47) e é validado aqui, no servidor;
 *   o app só mostra os botões que `allowed_actions` libera.
 * - Remarcação é um pedido: a paciente escolhe um horário livre e o consultório aceita ou recusa na agenda.
 */
final class PatientAppointmentService
{
    public const REQUEST_PENDING = 'pending';
    public const REQUEST_ACCEPTED = 'accepted';
    public const REQUEST_DECLINED = 'declined';
    public const REQUEST_CANCELLED = 'cancelled';
    public const REQUEST_EXPIRED = 'expired';

    /** Valores de video_status no card (o tipo do app em mobile/src/services/api.ts tem de ser igual). */
    public const VIDEO_STATUSES = ['presencial', 'aguardando_horario', 'aguardando_sala', 'sala_disponivel'];

    private const REASON_MAX = 300;
    private const RESCHEDULE_SCAN_DAYS = 30;
    private const RESCHEDULE_MAX_DAYS_WITH_SLOTS = 10;

    private PDO $db;
    private ?DateTimeImmutable $fixedNow;
    private DateTimeZone $tz;

    public function __construct(?PDO $db = null, ?DateTimeImmutable $now = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->fixedNow = $now;
        $this->tz = new DateTimeZone(date_default_timezone_get());
    }

    private function now(): DateTimeImmutable
    {
        return ($this->fixedNow ?? new DateTimeImmutable('now'))->setTimezone($this->tz);
    }

    private function nowSql(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    private function local(string $sqlDateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($sqlDateTime, $this->tz);
    }

    private function booking(): BookingService
    {
        return new BookingService($this->db, $this->fixedNow);
    }

    private function appointments(): AppointmentService
    {
        return new AppointmentService($this->db, $this->fixedNow);
    }

    /** Canal da ação, para auditoria: app (token Bearer) ou portal web (cookie HttpOnly da sessão web). */
    private function channel(): string
    {
        return str_starts_with((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'Bearer ') ? 'app' : 'portal_web';
    }

    private function channelLabel(): string
    {
        return $this->channel() === 'portal_web' ? 'portal web' : 'app';
    }

    public function timezoneLabel(): string
    {
        return $this->tz->getName() === 'America/Sao_Paulo' ? 'horário de Brasília' : $this->tz->getName();
    }

    private function hasRequestsTable(): bool
    {
        return SchemaInfo::hasTable($this->db, 'appointment_change_requests');
    }

    // =========================================================================
    // Leitura: card da próxima consulta
    // =========================================================================

    /**
     * Próxima consulta (agendada ou confirmada que ainda não terminou), com lista fechada de campos.
     */
    public function nextAppointment(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT a.id, a.patient_id, a.professional_id, a.scheduled_at, a.duration_minutes, a.status, a.type,
                   u.name AS professional_name
            FROM appointments a
            LEFT JOIN professionals p ON p.id = a.professional_id
            LEFT JOIN users u ON u.id = p.user_id
            WHERE a.patient_id = :patient_id AND a.clinic_id = :clinic_id AND a.status IN ('scheduled', 'confirmed')
              AND a.scheduled_at >= :floor
            ORDER BY a.scheduled_at ASC LIMIT 50
        ");
        $now = $this->now();
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId, 'floor' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);
        $graceRaw = getenv('TELECONSULT_JOIN_GRACE_MINUTES');
        $grace = is_numeric($graceRaw) ? max(0, min(240, (int)$graceRaw)) : 30;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            try {
                $start = $this->local((string)$candidate['scheduled_at']);
            } catch (\Exception) {
                continue;
            }
            $end = $start->modify('+' . (max(15, (int)($candidate['duration_minutes'] ?? 60)) + $grace) . ' minutes');
            if ($end >= $now) {
                return $this->present($candidate, $clinicId, $patientId);
            }
        }
        return null;
    }

    private function present(array $row, int $clinicId, int $patientId): array
    {
        $settings = $this->booking()->getSettings($clinicId);
        $start = $this->local((string)$row['scheduled_at']);
        $duration = max(15, (int)($row['duration_minutes'] ?? 60));
        $isOnline = TeleconsultationService::isOnlineType($row['type'] ?? null);
        $request = $this->latestRequest((int)$row['id']);
        $pending = $request !== null && $request['status'] === self::REQUEST_PENDING ? $request : null;

        [$meetingUrl, $videoOpensAt, $videoStatus] = $isOnline
            ? $this->videoAccess((int)$row['id'], $clinicId, $patientId, $start)
            : [null, null, 'presencial'];

        $clinic = $this->clinicInfo($clinicId);
        return [
            'id' => (int)$row['id'],
            // Hora local do consultório (compatibilidade); o app usa scheduled_at_iso, que tem o fuso.
            'scheduled_at' => $start->format('Y-m-d H:i:s'),
            'scheduled_at_iso' => $start->format(DATE_ATOM),
            'ends_at_iso' => $start->modify('+' . $duration . ' minutes')->format(DATE_ATOM),
            'timezone' => $this->tz->getName(),
            'timezone_label' => $this->timezoneLabel(),
            'duration_minutes' => $duration,
            'status' => (string)$row['status'],
            'status_label' => LabelMap::appointmentStatus((string)$row['status']),
            'modality' => $isOnline ? 'online' : 'presencial',
            'modality_label' => $isOnline ? 'Teleconsulta (online)' : 'Presencial',
            'is_online' => $isOnline,
            'professional_name' => $row['professional_name'] ?? null,
            'clinic_name' => $clinic['name'],
            'clinic_phone' => $clinic['phone'],
            'location' => !$isOnline && $settings['patient_show_address'] ? $clinic['address'] : null,
            'meeting_url' => $meetingUrl,
            'video_opens_at_iso' => $videoOpensAt?->format(DATE_ATOM),
            'video_status' => $videoStatus,
            'allowed_actions' => $this->allowedActions($row, $settings, $pending),
            'change_request' => $request !== null ? $this->presentRequest($request) : null,
        ];
    }

    /** @return array{name: ?string, phone: ?string, address: ?string} */
    private function clinicInfo(int $clinicId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM clinics WHERE id = :id');
        $stmt->execute(['id' => $clinicId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $street = trim((string)($c['address_street'] ?? ''));
        $address = null;
        if ($street !== '') {
            $line1 = $street . (trim((string)($c['address_number'] ?? '')) !== '' ? ', ' . trim((string)$c['address_number']) : '')
                . (trim((string)($c['address_complement'] ?? '')) !== '' ? ' - ' . trim((string)$c['address_complement']) : '');
            $cityState = trim(trim((string)($c['address_city'] ?? '')) . (trim((string)($c['address_state'] ?? '')) !== '' ? '/' . trim((string)$c['address_state']) : ''), '/');
            $parts = array_filter([$line1, trim((string)($c['address_neighborhood'] ?? '')), $cityState], static fn($p) => $p !== '');
            $address = implode(' · ', $parts);
        }
        return [
            'name' => isset($c['name']) ? (string)$c['name'] : null,
            'phone' => trim((string)($c['phone'] ?? '')) !== '' ? (string)$c['phone'] : null,
            'address' => $address,
        ];
    }

    /**
     * Link da sala só dentro da janela de entrada (NI2-137 M3). Antes disso o app mostra quando a sala abre.
     * @return array{0: ?string, 1: ?DateTimeImmutable, 2: string}
     */
    private function videoAccess(int $appointmentId, int $clinicId, int $patientId, DateTimeImmutable $start): array
    {
        $early = (int)(is_numeric(getenv('TELECONSULT_JOIN_EARLY_MINUTES')) ? max(0, min(120, (int)getenv('TELECONSULT_JOIN_EARLY_MINUTES'))) : 15);
        $opensAt = $start->modify("-{$early} minutes");
        $session = null;
        if (SchemaInfo::hasTable($this->db, 'teleconsultation_sessions')) {
            $stmt = $this->db->prepare('SELECT status, join_opens_at_utc FROM teleconsultation_sessions WHERE appointment_id = :a AND clinic_id = :c');
            $stmt->execute(['a' => $appointmentId, 'c' => $clinicId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($session !== null && !empty($session['join_opens_at_utc'])) {
            $opensAt = (new DateTimeImmutable((string)$session['join_opens_at_utc'], new DateTimeZone('UTC')))->setTimezone($this->tz);
        }
        if ($this->now() < $opensAt) {
            return [null, $opensAt, 'aguardando_horario'];
        }
        $url = null;
        try {
            $ticket = (new TeleconsultationService($this->db, null, null, $this->fixedNow))->issuePortalTicket($patientId, $appointmentId, $clinicId);
            if ($ticket !== null) {
                $base = rtrim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')), '/');
                $url = $base . '/teleconsulta/sala-espera/' . $ticket;
            }
        } catch (\Throwable) {
            $url = null; // sem teleconsulta disponível; nunca um link inventado
        }
        return [$url, $opensAt, $url !== null ? 'sala_disponivel' : 'aguardando_sala'];
    }

    // =========================================================================
    // Política: o que a paciente pode fazer agora
    // =========================================================================

    /**
     * Motivo da recusa da ação (código e mensagem) ou null quando permitida.
     * @return array{code: string, message: string}|null
     */
    private function denial(string $action, array $appt, array $settings, ?array $pendingRequest): ?array
    {
        $status = (string)$appt['status'];
        $start = $this->local((string)$appt['scheduled_at']);
        $now = $this->now();
        $contact = 'Fale com o consultório.';

        if ($action === 'confirm') {
            if (!$settings['patient_can_confirm']) {
                return ['code' => 'ACTION_NOT_ALLOWED', 'message' => 'O consultório confirma esta consulta diretamente com você.'];
            }
            if ($status === AppointmentService::STATUS_CONFIRMED) {
                return ['code' => 'INVALID_STATE', 'message' => 'Presença já confirmada.'];
            }
            if ($status !== AppointmentService::STATUS_SCHEDULED) {
                return ['code' => 'INVALID_STATE', 'message' => 'Esta consulta está como "' . LabelMap::appointmentStatus($status) . '" e não pode ser confirmada.'];
            }
            if ($start <= $now) {
                return ['code' => 'INVALID_STATE', 'message' => 'O horário desta consulta já passou.'];
            }
            if ($pendingRequest !== null) {
                return ['code' => 'REQUEST_PENDING', 'message' => 'Você pediu outro horário. Aguarde a resposta do consultório.'];
            }
            return null;
        }

        $isCancel = $action === 'cancel';
        if ($isCancel ? !$settings['patient_can_cancel'] : !$settings['patient_can_request_reschedule']) {
            return ['code' => 'ACTION_NOT_ALLOWED', 'message' => ($isCancel ? 'Para cancelar, ' : 'Para mudar o horário, ') . lcfirst($contact)];
        }
        if (!in_array($status, [AppointmentService::STATUS_SCHEDULED, AppointmentService::STATUS_CONFIRMED], true)) {
            return ['code' => 'INVALID_STATE', 'message' => 'Esta consulta está como "' . LabelMap::appointmentStatus($status) . '" e não pode ser ' . ($isCancel ? 'cancelada.' : 'remarcada.')];
        }
        $hours = BookingService::patientMinAdvanceHours($settings, $isCancel ? 'cancel' : 'reschedule');
        if ($start->modify("-{$hours} hours") < $now) {
            return ['code' => 'POLICY_VIOLATION', 'message' => 'Pelo app, ' . ($isCancel ? 'o cancelamento' : 'o pedido de remarcação') . " vale até {$hours} " . ($hours === 1 ? 'hora' : 'horas') . ' antes da consulta. ' . $contact];
        }
        if (!$isCancel && $pendingRequest !== null) {
            return ['code' => 'REQUEST_PENDING', 'message' => 'Já existe um pedido de remarcação aguardando resposta do consultório.'];
        }
        if (!$isCancel && !$this->hasRequestsTable()) {
            return ['code' => 'ACTION_NOT_ALLOWED', 'message' => 'Para mudar o horário, ' . lcfirst($contact)];
        }
        return null;
    }

    private function allowedActions(array $appt, array $settings, ?array $pendingRequest): array
    {
        $out = [];
        foreach (['confirm', 'cancel', 'reschedule'] as $action) {
            $denial = $this->denial($action, $appt, $settings, $pendingRequest);
            $entry = ['allowed' => $denial === null, 'reason' => $denial['message'] ?? null];
            if ($action !== 'confirm') {
                $hours = BookingService::patientMinAdvanceHours($settings, $action);
                $entry['deadline_iso'] = $this->local((string)$appt['scheduled_at'])->modify("-{$hours} hours")->format(DATE_ATOM);
            }
            $out[$action] = $entry;
        }
        return $out;
    }

    private function assertAllowed(string $action, array $appt, array $settings, ?array $pendingRequest): void
    {
        $denial = $this->denial($action, $appt, $settings, $pendingRequest);
        if ($denial === null) {
            return;
        }
        $status = match ($denial['code']) {
            'ACTION_NOT_ALLOWED' => 403,
            'POLICY_VIOLATION' => 422,
            default => 409,
        };
        $extra = $denial['code'] === 'INVALID_STATE' ? ['current_status' => (string)$appt['status']] : [];
        throw new PatientAppointmentActionException($denial['message'], $denial['code'], $status, $extra);
    }

    // =========================================================================
    // Ações da paciente
    // =========================================================================

    public function confirm(int $patientId, int $appointmentId): array
    {
        $clinicId = TenantContext::getClinicId();
        $settings = $this->booking()->getSettings($clinicId);
        $result = $this->appointments()->transitionByPatient(
            $appointmentId,
            $patientId,
            AppointmentService::STATUS_CONFIRMED,
            'Presença confirmada pela paciente (' . $this->channelLabel() . ').',
            function (array $row) use ($settings): void {
                $this->assertAllowed('confirm', $row, $settings, $this->pendingRequestFor((int)$row['id']));
            }
        );
        $this->audit('APPOINTMENT_CONFIRMED_BY_PATIENT', $appointmentId, $patientId, [
            'from_status' => $result['from_status'],
            'to_status' => $result['to_status'],
            'scheduled_at' => $result['appointment']['scheduled_at'],
        ]);
        return [
            'success' => true,
            'appointment_id' => $appointmentId,
            'status' => AppointmentService::STATUS_CONFIRMED,
            'message' => 'Presença confirmada.',
        ];
    }

    public function cancel(int $patientId, int $appointmentId, string $reason): array
    {
        $reason = $this->cleanReason($reason);
        $clinicId = TenantContext::getClinicId();
        $settings = $this->booking()->getSettings($clinicId);
        $result = $this->appointments()->transitionByPatient(
            $appointmentId,
            $patientId,
            AppointmentService::STATUS_CANCELLED,
            'Cancelada pela paciente (' . $this->channelLabel() . ').' . ($reason !== '' ? ' Motivo: ' . $reason : ''),
            function (array $row) use ($settings): void {
                $this->assertAllowed('cancel', $row, $settings, null);
            }
        );
        // Pedido de remarcação em aberto deixa de fazer sentido.
        if ($this->hasRequestsTable()) {
            $this->db->prepare("UPDATE appointment_change_requests SET status = 'cancelled', decision_note = :n, updated_at = :now
                WHERE appointment_id = :a AND clinic_id = :c AND status = 'pending'")
                ->execute(['n' => 'Consulta cancelada pela paciente.', 'now' => $this->nowSql(), 'a' => $appointmentId, 'c' => $clinicId]);
        }
        $this->audit('APPOINTMENT_CANCELLED_BY_PATIENT', $appointmentId, $patientId, [
            'from_status' => $result['from_status'],
            'to_status' => $result['to_status'],
            'scheduled_at' => $result['appointment']['scheduled_at'],
            'reason_given' => $reason !== '',
        ]);
        return [
            'success' => true,
            'appointment_id' => $appointmentId,
            'status' => AppointmentService::STATUS_CANCELLED,
            'message' => 'Consulta cancelada. O consultório foi avisado pela agenda.',
        ];
    }

    /**
     * Horários livres para o pedido de remarcação: disponibilidade semanal do profissional, sem conflito com
     * outras consultas ou reservas, respeitando a antecedência de remarcação. Não depende da página pública.
     */
    public function rescheduleOptions(int $patientId, int $appointmentId): array
    {
        $clinicId = TenantContext::getClinicId();
        $appt = $this->ownedAppointment($patientId, $appointmentId, $clinicId);
        $settings = $this->booking()->getSettings($clinicId);
        $this->assertAllowed('reschedule', $appt, $settings, $this->pendingRequestFor($appointmentId));

        $duration = max(15, (int)($appt['duration_minutes'] ?? 60));
        $current = $this->local((string)$appt['scheduled_at'])->format('Y-m-d H:i:s');
        $today = new DateTimeImmutable($this->now()->format('Y-m-d') . ' 00:00:00', $this->tz);
        $scanDays = min(self::RESCHEDULE_SCAN_DAYS, max(1, (int)$settings['max_future_days']));
        $days = [];
        for ($i = 0; $i <= $scanDays && count($days) < self::RESCHEDULE_MAX_DAYS_WITH_SLOTS; $i++) {
            $date = $today->modify("+{$i} days")->format('Y-m-d');
            $slots = [];
            foreach ($this->booking()->getAvailableSlots($clinicId, (int)$appt['professional_id'], $date, $duration, 'presential', $appointmentId, true) as $slot) {
                if ($slot['scheduled_at'] === $current) {
                    continue;
                }
                $at = $this->local($slot['scheduled_at']);
                $slots[] = ['scheduled_at' => $slot['scheduled_at'], 'scheduled_at_iso' => $at->format(DATE_ATOM), 'time_label' => $at->format('H:i')];
            }
            if ($slots !== []) {
                $days[] = ['date' => $date, 'slots' => $slots];
            }
        }
        return [
            'appointment_id' => $appointmentId,
            'timezone' => $this->tz->getName(),
            'timezone_label' => $this->timezoneLabel(),
            'duration_minutes' => $duration,
            'days' => $days,
        ];
    }

    public function requestReschedule(int $patientId, int $appointmentId, string $newScheduledAt, string $reason): array
    {
        $reason = $this->cleanReason($reason);
        $newScheduledAt = trim($newScheduledAt);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $newScheduledAt)) {
            throw new PatientAppointmentActionException('Escolha um dos horários livres da lista.', 'VALIDATION_ERROR', 422);
        }
        try {
            $requested = new DateTimeImmutable(str_replace('T', ' ', $newScheduledAt), $this->tz);
        } catch (\Exception) {
            throw new PatientAppointmentActionException('Escolha um dos horários livres da lista.', 'VALIDATION_ERROR', 422);
        }
        $requestedSql = $requested->format('Y-m-d H:i:s');
        $clinicId = TenantContext::getClinicId();

        $requestId = $this->transaction(function () use ($patientId, $appointmentId, $clinicId, $requestedSql, $reason): int {
            $appt = $this->lockOwnedAppointment($patientId, $appointmentId, $clinicId);
            $settings = $this->booking()->getSettings($clinicId);
            $this->assertAllowed('reschedule', $appt, $settings, $this->pendingRequestFor($appointmentId));
            if ($requestedSql === $this->local((string)$appt['scheduled_at'])->format('Y-m-d H:i:s')) {
                throw new PatientAppointmentActionException('Este já é o horário da sua consulta. Escolha outro.', 'VALIDATION_ERROR', 422);
            }
            $duration = max(15, (int)($appt['duration_minutes'] ?? 60));
            $offered = array_column($this->booking()->getAvailableSlots($clinicId, (int)$appt['professional_id'], substr($requestedSql, 0, 10), $duration, 'presential', $appointmentId, true), 'scheduled_at');
            if (!in_array($requestedSql, $offered, true)) {
                throw new PatientAppointmentActionException('Este horário não está mais livre. Escolha outro.', 'SLOT_CONFLICT', 409);
            }
            $now = $this->nowSql();
            $this->db->prepare('INSERT INTO appointment_change_requests
                (clinic_id, appointment_id, patient_id, professional_id, request_type, status, from_scheduled_at, requested_scheduled_at, duration_minutes, reason, created_at, updated_at)
                VALUES (:c, :a, :p, :pr, \'reschedule\', \'pending\', :from, :to, :d, :r, :now, :now2)')
                ->execute([
                    'c' => $clinicId, 'a' => $appointmentId, 'p' => $patientId, 'pr' => (int)$appt['professional_id'],
                    'from' => $this->local((string)$appt['scheduled_at'])->format('Y-m-d H:i:s'), 'to' => $requestedSql, 'd' => $duration,
                    'r' => $reason !== '' ? $reason : null, 'now' => $now, 'now2' => $now,
                ]);
            $id = (int)$this->db->lastInsertId();
            $this->audit('APPOINTMENT_RESCHEDULE_REQUESTED', $appointmentId, $patientId, [
                'request_id' => $id,
                'from_scheduled_at' => $this->local((string)$appt['scheduled_at'])->format('Y-m-d H:i:s'),
                'to_scheduled_at' => $requestedSql,
                'reason_given' => $reason !== '',
            ]);
            return $id;
        });

        return [
            'success' => true,
            'request' => $this->presentRequest($this->findRequest($requestId, $clinicId)),
            'message' => 'Pedido enviado. Sua consulta continua no horário atual até o consultório responder.',
        ];
    }

    public function withdrawRequest(int $patientId, int $requestId): array
    {
        $clinicId = TenantContext::getClinicId();
        $request = $this->hasRequestsTable() ? $this->findRequest($requestId, $clinicId) : null;
        if ($request === null || (int)$request['patient_id'] !== $patientId) {
            throw new PatientAppointmentActionException('Pedido não encontrado.', 'REQUEST_NOT_FOUND', 404);
        }
        $stmt = $this->db->prepare("UPDATE appointment_change_requests SET status = 'cancelled', decision_note = :n, updated_at = :now
            WHERE id = :id AND clinic_id = :c AND status = 'pending'");
        $stmt->execute(['n' => 'A paciente desistiu do pedido.', 'now' => $this->nowSql(), 'id' => $requestId, 'c' => $clinicId]);
        if ($stmt->rowCount() !== 1) {
            throw new PatientAppointmentActionException('Este pedido já foi respondido ou encerrado.', 'INVALID_STATE', 409, ['current_status' => (string)$request['status']]);
        }
        $this->audit('APPOINTMENT_RESCHEDULE_REQUEST_WITHDRAWN', (int)$request['appointment_id'], $patientId, ['request_id' => $requestId]);
        return ['success' => true, 'request' => $this->presentRequest($this->findRequest($requestId, $clinicId)), 'message' => 'Pedido de remarcação retirado.'];
    }

    // =========================================================================
    // Consultório: fila de pedidos de remarcação
    // =========================================================================

    /** Pedidos pendentes da clínica (os vencidos são encerrados como "expirado" antes de listar). */
    public function pendingRequestsForClinic(): array
    {
        if (!$this->hasRequestsTable()) {
            return [];
        }
        $clinicId = TenantContext::getClinicId();
        $this->expireStale($clinicId);
        $stmt = $this->db->prepare("
            SELECT r.*, pt.name AS patient_name, u.name AS professional_name, a.status AS appointment_status, a.scheduled_at AS appointment_scheduled_at, a.type AS appointment_type
            FROM appointment_change_requests r
            JOIN appointments a ON a.id = r.appointment_id AND a.clinic_id = r.clinic_id
            JOIN patients pt ON pt.id = r.patient_id
            LEFT JOIN professionals pr ON pr.id = r.professional_id
            LEFT JOIN users u ON u.id = pr.user_id
            WHERE r.clinic_id = :c AND r.status = 'pending'
            ORDER BY r.requested_scheduled_at ASC, r.id ASC
        ");
        $stmt->execute(['c' => $clinicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pedidos respondidos nos últimos 14 dias (para a trilha da fila). */
    public function recentDecisionsForClinic(): array
    {
        if (!$this->hasRequestsTable()) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT r.*, pt.name AS patient_name, du.name AS decided_by_name
            FROM appointment_change_requests r
            JOIN patients pt ON pt.id = r.patient_id
            LEFT JOIN users du ON du.id = r.decided_by_user_id
            WHERE r.clinic_id = :c AND r.status <> 'pending' AND r.updated_at >= :since
            ORDER BY r.updated_at DESC, r.id DESC LIMIT 30
        ");
        $stmt->execute(['c' => TenantContext::getClinicId(), 'since' => $this->now()->modify('-14 days')->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countPendingForClinic(): int
    {
        if (!$this->hasRequestsTable()) {
            return 0;
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM appointment_change_requests WHERE clinic_id = :c AND status = 'pending' AND requested_scheduled_at > :now");
        $stmt->execute(['c' => TenantContext::getClinicId(), 'now' => $this->nowSql()]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Aceitar: a consulta vai para o novo horário pelo mesmo caminho do reagendamento da agenda
     * (disponibilidade, conflito, convite de teleconsulta revogado e janela recalculada).
     */
    public function approveRequest(int $requestId, ?int $userId): array
    {
        $clinicId = TenantContext::getClinicId();
        return $this->transaction(function () use ($requestId, $userId, $clinicId): array {
            $request = $this->lockRequest($requestId, $clinicId);
            if ($request['status'] !== self::REQUEST_PENDING) {
                throw new InvalidArgumentException('Este pedido já foi respondido ou encerrado.');
            }
            $requestedAt = $this->local((string)$request['requested_scheduled_at']);
            if ($requestedAt <= $this->now()) {
                throw new InvalidArgumentException('O horário pedido já passou. Recuse o pedido e combine outro horário com a paciente.');
            }
            $appt = $this->appointments()->getAppointment((int)$request['appointment_id']);
            if ($appt === null || !in_array($appt['status'], [AppointmentService::STATUS_SCHEDULED, AppointmentService::STATUS_CONFIRMED], true)) {
                throw new InvalidArgumentException('A consulta não está mais agendada. Recuse o pedido.');
            }
            if ($this->local((string)$appt['scheduled_at'])->format('Y-m-d H:i:s') !== $this->local((string)$request['from_scheduled_at'])->format('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('A consulta já mudou de horário depois do pedido. Recuse o pedido e combine com a paciente.');
            }
            $updated = $this->appointments()->rescheduleFromPatientRequest(
                (int)$request['appointment_id'],
                $requestedAt->format('Y-m-d H:i:s'),
                $userId,
                'Pedido de remarcação da paciente aceito' . (trim((string)$request['reason']) !== '' ? ' (motivo da paciente: ' . trim((string)$request['reason']) . ')' : '')
            );
            $this->db->prepare("UPDATE appointment_change_requests SET status = 'accepted', decided_by_user_id = :u, decided_at = :now, updated_at = :now2
                WHERE id = :id AND clinic_id = :c AND status = 'pending'")
                ->execute(['u' => $userId, 'now' => $this->nowSql(), 'now2' => $this->nowSql(), 'id' => $requestId, 'c' => $clinicId]);
            (new AuditLogger($this->db))->log('APPOINTMENT_RESCHEDULE_REQUEST_ACCEPTED', 'appointment', (string)$request['appointment_id'], [
                'request_id' => $requestId,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'from_scheduled_at' => $request['from_scheduled_at'],
                'to_scheduled_at' => $request['requested_scheduled_at'],
                'status_after' => $updated['status'] ?? null,
            ]);
            return $updated;
        });
    }

    public function declineRequest(int $requestId, ?int $userId, string $note): array
    {
        $clinicId = TenantContext::getClinicId();
        $note = $this->cleanReason($note);
        return $this->transaction(function () use ($requestId, $userId, $clinicId, $note): array {
            $request = $this->lockRequest($requestId, $clinicId);
            if ($request['status'] !== self::REQUEST_PENDING) {
                throw new InvalidArgumentException('Este pedido já foi respondido ou encerrado.');
            }
            $this->db->prepare("UPDATE appointment_change_requests SET status = 'declined', decided_by_user_id = :u, decided_at = :now, decision_note = :n, updated_at = :now2
                WHERE id = :id AND clinic_id = :c AND status = 'pending'")
                ->execute(['u' => $userId, 'now' => $this->nowSql(), 'now2' => $this->nowSql(), 'n' => $note !== '' ? $note : null, 'id' => $requestId, 'c' => $clinicId]);
            (new AuditLogger($this->db))->log('APPOINTMENT_RESCHEDULE_REQUEST_DECLINED', 'appointment', (string)$request['appointment_id'], [
                'request_id' => $requestId,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'requested_scheduled_at' => $request['requested_scheduled_at'],
                'note_given' => $note !== '',
            ]);
            return $this->findRequest($requestId, $clinicId);
        });
    }

    // =========================================================================
    // Apoio
    // =========================================================================

    private function cleanReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new PatientAppointmentActionException('O motivo pode ter até ' . self::REASON_MAX . ' caracteres.', 'VALIDATION_ERROR', 422);
        }
        return $reason;
    }

    private function ownedAppointment(int $patientId, int $appointmentId, int $clinicId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id = :id AND clinic_id = :c AND patient_id = :p');
        $stmt->execute(['id' => $appointmentId, 'c' => $clinicId, 'p' => $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw PatientAppointmentActionException::notFound();
        }
        return $row;
    }

    private function lockOwnedAppointment(int $patientId, int $appointmentId, int $clinicId): array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id = :id AND clinic_id = :c AND patient_id = :p FOR UPDATE');
            $stmt->execute(['id' => $appointmentId, 'c' => $clinicId, 'p' => $patientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw PatientAppointmentActionException::notFound();
            }
            return $row;
        }
        $this->db->prepare('UPDATE appointments SET id = id WHERE id = :id AND clinic_id = :c')->execute(['id' => $appointmentId, 'c' => $clinicId]);
        return $this->ownedAppointment($patientId, $appointmentId, $clinicId);
    }

    private function lockRequest(int $requestId, int $clinicId): array
    {
        if (!$this->hasRequestsTable()) {
            throw new InvalidArgumentException('Pedido não encontrado.');
        }
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->db->prepare('SELECT * FROM appointment_change_requests WHERE id = :id AND clinic_id = :c FOR UPDATE');
            $stmt->execute(['id' => $requestId, 'c' => $clinicId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $this->db->prepare('UPDATE appointment_change_requests SET id = id WHERE id = :id AND clinic_id = :c')->execute(['id' => $requestId, 'c' => $clinicId]);
            $row = $this->findRequest($requestId, $clinicId);
        }
        if (!$row) {
            throw new InvalidArgumentException('Pedido não encontrado.');
        }
        return $row;
    }

    private function findRequest(int $requestId, int $clinicId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM appointment_change_requests WHERE id = :id AND clinic_id = :c');
        $stmt->execute(['id' => $requestId, 'c' => $clinicId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function pendingRequestFor(int $appointmentId): ?array
    {
        $latest = $this->latestRequest($appointmentId);
        return $latest !== null && $latest['status'] === self::REQUEST_PENDING ? $latest : null;
    }

    /** Último pedido da consulta (pendente, ou respondido nos últimos 7 dias, para a paciente ver a resposta). */
    private function latestRequest(int $appointmentId): ?array
    {
        if (!$this->hasRequestsTable()) {
            return null;
        }
        $clinicId = TenantContext::getClinicId();
        $this->expireStale($clinicId, $appointmentId);
        $stmt = $this->db->prepare('SELECT * FROM appointment_change_requests WHERE appointment_id = :a AND clinic_id = :c ORDER BY id DESC LIMIT 1');
        $stmt->execute(['a' => $appointmentId, 'c' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row === null) {
            return null;
        }
        if ($row['status'] !== self::REQUEST_PENDING && $this->local((string)$row['updated_at']) < $this->now()->modify('-7 days')) {
            return null;
        }
        return $row;
    }

    /** Pedido pendente cujo horário pedido (ou o da consulta) já passou não pode mais ser aceito. */
    private function expireStale(int $clinicId, ?int $appointmentId = null): void
    {
        $sql = "UPDATE appointment_change_requests SET status = 'expired', decision_note = 'O horário pedido passou sem resposta.', updated_at = :now
            WHERE clinic_id = :c AND status = 'pending' AND requested_scheduled_at <= :now2";
        $params = ['now' => $this->nowSql(), 'now2' => $this->nowSql(), 'c' => $clinicId];
        if ($appointmentId !== null) {
            $sql .= ' AND appointment_id = :a';
            $params['a'] = $appointmentId;
        }
        $this->db->prepare($sql)->execute($params);
    }

    private function presentRequest(array $r): array
    {
        $labels = [
            self::REQUEST_PENDING => 'Aguardando o consultório',
            self::REQUEST_ACCEPTED => 'Aceito',
            self::REQUEST_DECLINED => 'Recusado',
            self::REQUEST_CANCELLED => 'Encerrado',
            self::REQUEST_EXPIRED => 'Expirado',
        ];
        return [
            'id' => (int)$r['id'],
            'appointment_id' => (int)$r['appointment_id'],
            'status' => (string)$r['status'],
            'status_label' => $labels[$r['status']] ?? (string)$r['status'],
            'from_scheduled_at_iso' => $this->local((string)$r['from_scheduled_at'])->format(DATE_ATOM),
            'requested_scheduled_at_iso' => $this->local((string)$r['requested_scheduled_at'])->format(DATE_ATOM),
            'reason' => $r['reason'] !== null ? (string)$r['reason'] : null,
            'decision_note' => $r['decision_note'] !== null ? (string)$r['decision_note'] : null,
            'created_at_iso' => $this->local((string)$r['created_at'])->format(DATE_ATOM),
            'decided_at_iso' => !empty($r['decided_at']) ? $this->local((string)$r['decided_at'])->format(DATE_ATOM) : null,
        ];
    }

    private function audit(string $action, int $appointmentId, int $patientId, array $details): void
    {
        (new AuditLogger($this->db))->log($action, 'appointment', (string)$appointmentId, [
            'actor_type' => 'patient',
            'actor_id' => $patientId,
            'channel' => $this->channel(),
        ] + $details);
    }

    private function transaction(callable $fn): mixed
    {
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }
        try {
            $result = $fn();
            if ($owns) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
