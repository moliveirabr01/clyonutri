<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Core\Database\Connection;
use DateTimeImmutable;
use PDO;

/**
 * Métricas de operação do Consultório Online (NI2-432), calculadas só a partir
 * de estados e eventos de auditoria — nenhum conteúdo clínico, nome ou e-mail.
 *
 * Sem metas embutidas: os números servem para definir metas depois do piloto.
 * Custo: WebRTC P2P não tem custo por minuto; a estimativa de participante-minuto
 * existe para comparar com um provedor pago, se um dia for contratado.
 */
class TeleconsultationMetricsService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /** @param ?int $clinicId null = plataforma inteira (uso administrativo) */
    public function summary(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, ?int $clinicId = null): array
    {
        $params = ['from' => $fromUtc->format('Y-m-d H:i:s'), 'to' => $toUtc->format('Y-m-d H:i:s')];
        $tenant = '';
        if ($clinicId !== null) {
            $tenant = ' AND s.clinic_id = :clinic';
            $params['clinic'] = $clinicId;
        }

        $stmt = $this->db->prepare("SELECT s.id, s.status, s.provider FROM teleconsultation_sessions s WHERE s.created_at >= :from AND s.created_at < :to{$tenant}");
        $stmt->execute($params);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(static fn($r) => (int)$r['id'], $sessions);

        $byStatus = [];
        foreach ($sessions as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
        }

        $events = [];
        if ($ids !== []) {
            $in = implode(',', $ids); // inteiros vindos do banco
            $events = $this->db->query("SELECT session_id, event_type, actor_type, created_at FROM teleconsultation_events WHERE session_id IN ({$in}) ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
        }

        $perSession = [];
        foreach ($events as $ev) {
            $perSession[(int)$ev['session_id']][] = $ev;
        }

        $enteredWaiting = 0; $admitted = 0; $connected = 0;
        $waitSeconds = []; $connectSeconds = []; $callSeconds = [];
        $reconnects = 0; $failures = 0; $gaveUp = 0; $providerBlocked = 0;
        $emailsSent = 0; $emailsFailed = 0; $screenShares = 0;

        foreach ($perSession as $list) {
            $first = [];
            foreach ($list as $ev) {
                $first[$ev['event_type']] ??= strtotime($ev['created_at'] . ' UTC');
                match ($ev['event_type']) {
                    'media_reconnecting' => $reconnects++,
                    'media_failed' => $failures++,
                    'media_gave_up' => $gaveUp++,
                    'provider_blocked' => $providerBlocked++,
                    'invite_email_sent', 'reminder_email_sent' => $emailsSent++,
                    'invite_email_failed', 'reminder_email_failed' => $emailsFailed++,
                    'screen_share_started' => $screenShares++,
                    default => null,
                };
            }
            if (isset($first['waiting_room_entered'])) {
                $enteredWaiting++;
            }
            if (isset($first['patient_admitted'])) {
                $admitted++;
                if (isset($first['waiting_room_entered'])) {
                    $waitSeconds[] = $first['patient_admitted'] - $first['waiting_room_entered'];
                }
            }
            if (isset($first['media_connected'])) {
                $connected++;
                if (isset($first['patient_admitted'])) {
                    $connectSeconds[] = max(0, $first['media_connected'] - $first['patient_admitted']);
                }
                if (isset($first['call_ended'])) {
                    $callSeconds[] = max(0, $first['call_ended'] - $first['media_connected']);
                }
            }
        }

        $participantMinutes = (int)round(array_sum($callSeconds) * 2 / 60);

        return [
            'period_utc' => ['from' => $params['from'], 'to' => $params['to']],
            'scope' => $clinicId === null ? 'platform' : 'clinic',
            'sessions' => count($sessions),
            'sessions_by_status' => $byStatus,
            'entered_waiting_room' => $enteredWaiting,
            'admitted' => $admitted,
            'admission_rate' => $enteredWaiting > 0 ? round($admitted / $enteredWaiting, 3) : null,
            'media_connected' => $connected,
            'connection_rate' => $admitted > 0 ? round($connected / $admitted, 3) : null,
            'median_wait_seconds' => self::median($waitSeconds),
            'median_time_to_connect_seconds' => self::median($connectSeconds),
            'median_call_seconds' => self::median($callSeconds),
            'reconnect_events' => $reconnects,
            'connection_failures' => $failures,
            'gave_up' => $gaveUp,
            'provider_blocked' => $providerBlocked,
            'emails_sent' => $emailsSent,
            'emails_failed' => $emailsFailed,
            'screen_shares' => $screenShares,
            'participant_minutes' => $participantMinutes,
            'estimated_media_cost_brl' => 0.0,
            'cost_note' => 'WebRTC P2P sem custo por minuto. Se houver TURN contratado, o custo é de banda do servidor TURN e não é estimado aqui.',
        ];
    }

    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return (int)($n % 2 ? $values[$mid] : round(($values[$mid - 1] + $values[$mid]) / 2));
    }
}
