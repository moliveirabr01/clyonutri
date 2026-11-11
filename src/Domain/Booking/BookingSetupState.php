<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Booking;

use PDO;

/**
 * NI2-610 / NI2-612: estado do agendamento online de um profissional, numa só regra, para mostrar UM aviso por
 * pendência (o mais específico) no painel, na agenda e em Configurações > Agenda.
 *
 * Ordem (a primeira que se aplica vale):
 *   disabled          — agendamento online desligado: nada a avisar;
 *   hours_unconfirmed — conta antiga com só os dias marcados (NI2-584): os horários precisam ser confirmados;
 *   no_hours          — nenhum dia de atendimento ativo na disponibilidade semanal;
 *   no_slots          — há horários, mas nenhum livre nos próximos 14 dias;
 *   ok.
 * Só lê dados.
 */
final class BookingSetupState
{
    public const DISABLED = 'disabled';
    public const HOURS_UNCONFIRMED = 'hours_unconfirmed';
    public const NO_HOURS = 'no_hours';
    public const NO_SLOTS = 'no_slots';
    public const OK = 'ok';

    public function __construct(private PDO $db)
    {
    }

    /** Estado a partir das preferências já lidas (usado também pela tela de Configurações). */
    public static function classify(bool $enabled, array $preferences, callable $hasSlotsIn14Days): string
    {
        if (!$enabled) {
            return self::DISABLED;
        }
        $weekly = $preferences['weekly_availability'] ?? [];
        if (empty($weekly) && !empty($preferences['working_days'])) {
            return self::HOURS_UNCONFIRMED;
        }
        $active = is_array($weekly) ? array_filter($weekly, static fn($r) => is_array($r) && !empty($r['enabled'])) : [];
        if ($active === []) {
            return self::NO_HOURS;
        }
        return $hasSlotsIn14Days() ? self::OK : self::NO_SLOTS;
    }

    public function forProfessional(int $clinicId, int $professionalId): string
    {
        if ($clinicId <= 0 || $professionalId <= 0) {
            return self::DISABLED;
        }
        $booking = new BookingService($this->db);
        $settings = $booking->getSettings($clinicId);
        $st = $this->db->prepare('SELECT op.practice_preferences FROM onboarding_progress op JOIN professionals p ON p.user_id = op.user_id WHERE op.clinic_id = ? AND p.id = ? LIMIT 1');
        $st->execute([$clinicId, $professionalId]);
        $prefs = json_decode((string)($st->fetchColumn() ?: '{}'), true) ?: [];
        return self::classify(!empty($settings['is_enabled']), $prefs,
            static fn() => $booking->hasAvailableSlotsInNextDays($clinicId, $professionalId, 14));
    }

    public static function label(string $state): string
    {
        return [self::DISABLED=>'Desativado',self::HOURS_UNCONFIRMED=>'Aguardando confirmação dos horários',self::NO_HOURS=>'Sem jornada configurada',self::NO_SLOTS=>'Ativo, sem horários livres próximos',self::OK=>'Operacional'][$state]??'Não foi possível conferir';
    }

    /** Texto único do aviso para o estado, ou null quando não há pendência. */
    public static function message(string $state): ?string
    {
        return match ($state) {
            self::HOURS_UNCONFIRMED => 'O agendamento online está ligado, mas os horários de atendimento ainda não foram confirmados. Até lá, o link não oferece horários.',
            self::NO_HOURS => 'O agendamento online está ligado, mas não há dias de atendimento configurados. O link não oferece horários.',
            self::NO_SLOTS => 'O agendamento online está ligado, mas não há nenhum horário livre nos próximos 14 dias.',
            default => null,
        };
    }

    public static function actionLabel(string $state): string
    {
        return $state === self::NO_SLOTS ? 'Revisar horários' : 'Ativar agendamento online';
    }

    public const ACTION_URL = '/configuracoes?tab=agenda&secao=horarios#disponibilidade';
}
