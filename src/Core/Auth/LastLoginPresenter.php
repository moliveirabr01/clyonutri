<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Texto do "Último login" no backoffice: tempo relativo + data absoluta (dd/mm/aaaa HH:MM) no fuso da aplicação
 * (APP_TIMEZONE, America/Sao_Paulo). `users.last_login_at` está em UTC.
 */
final class LastLoginPresenter
{
    /**
     * @param ?string $lastLoginUtc    users.last_login_at (UTC) ou null
     * @param ?string $createdAtLocal  users.created_at (hora local do banco) ou, para clínica, clinics.created_at
     * @param ?string $trackingStart   {@see LastLoginService::trackingStart()}
     * @param bool    $available       a coluna existe no banco?
     * @return array{state:string,rel:string,abs:string,iso:string,note:string,tz:string}
     *   state: seen | never (conta criada depois do registro e sem login) | unknown (anterior ao registro) | unavailable
     */
    public static function describe(?string $lastLoginUtc, ?string $createdAtLocal, ?string $trackingStart, bool $available = true, ?int $now = null): array
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $out = ['state' => 'unavailable', 'rel' => '', 'abs' => '', 'iso' => '', 'note' => '', 'tz' => $tz->getName()];

        if (!$available) {
            $out['rel'] = 'Indisponível';
            $out['note'] = 'Aguardando a atualização do banco (migração 59)';
            return $out;
        }

        if ($lastLoginUtc === null || $lastLoginUtc === '') {
            $startsOk = $trackingStart !== null && $trackingStart !== '';
            if ($startsOk && $createdAtLocal !== null && $createdAtLocal >= $trackingStart) {
                $out['state'] = 'never';
                $out['rel'] = 'Nunca acessou';
                return $out;
            }
            $out['state'] = 'unknown';
            $out['rel'] = 'Sem registro';
            $out['note'] = $startsOk ? 'Anterior a ' . date('d/m/Y', (int)strtotime($trackingStart)) : 'Registro ainda não iniciado';
            return $out;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($lastLoginUtc, 0, 19), new DateTimeZone('UTC'));
        if ($dt === false) {
            $out['state'] = 'unknown';
            $out['rel'] = 'Sem registro';
            return $out;
        }
        $local = $dt->setTimezone($tz);
        $out['state'] = 'seen';
        $out['abs'] = $local->format('d/m/Y H:i');
        $out['iso'] = $local->format('c');
        $out['rel'] = self::relative($now ?? time(), $dt->getTimestamp());
        return $out;
    }

    public static function relative(int $now, int $then): string
    {
        $d = $now - $then;
        if ($d < 60) {
            return 'agora há pouco';
        }
        if ($d < 3600) {
            return 'há ' . intdiv($d, 60) . ' min';
        }
        if ($d < 86400) {
            return 'há ' . intdiv($d, 3600) . ' h';
        }
        $days = intdiv($d, 86400);
        if ($days < 30) {
            return 'há ' . $days . ($days === 1 ? ' dia' : ' dias');
        }
        if ($days < 365) {
            $m = intdiv($days, 30);
            return 'há ' . $m . ($m === 1 ? ' mês' : ' meses');
        }
        $y = intdiv($days, 365);
        return 'há ' . $y . ($y === 1 ? ' ano' : ' anos');
    }
}
