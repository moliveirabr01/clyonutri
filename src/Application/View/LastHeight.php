<?php

declare(strict_types=1);

namespace ClyoNutri\Application\View;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\I18n\LocalTime;

/**
 * v54.57 (UX-114): no retorno, a altura já medida vem preenchida (editável) e rotulada com a avaliação de origem.
 * Lê só a última avaliação da própria clínica com altura > 0; sem avaliação anterior o campo fica vazio, como antes.
 */
final class LastHeight
{
    /** @return array{cm: string, date: string}|null */
    public static function find(int $patientId): ?array
    {
        try {
            $st = Connection::getInstance()->prepare('SELECT height_cm, assessed_at FROM anthropometric_assessments WHERE clinic_id = ? AND patient_id = ? AND height_cm > 0 ORDER BY assessed_at DESC, id DESC LIMIT 1');
            $st->execute([(int)TenantContext::getClinicId(), $patientId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if (!$row) return null;
        $cm = rtrim(rtrim(number_format((float)$row['height_cm'], 1, '.', ''), '0'), '.');
        return ['cm' => $cm, 'date' => LocalTime::local((string)$row['assessed_at'], 'd/m/Y')];
    }

    /** Atributos extras do campo de altura (valor da última avaliação + descrição da origem). */
    public static function attrs(int $patientId): string
    {
        $h = self::find($patientId);
        return $h ? ' value="' . htmlspecialchars($h['cm'], ENT_QUOTES, 'UTF-8') . '" aria-describedby="ant_height_source" data-prefilled-from="last-assessment"' : '';
    }

    /** Rótulo "Da avaliação de dd/mm/aaaa" logo abaixo do campo (vazio sem avaliação anterior). */
    public static function hint(int $patientId): string
    {
        $h = self::find($patientId);
        return $h ? '<small id="ant_height_source" class="u-block u-text-caption u-color-neutral-gray-500 u-mt-1" data-testid="height-prefill-source">Da avaliação de '
            . htmlspecialchars($h['date'], ENT_QUOTES, 'UTF-8') . '. Confira e altere se mudou.</small>' : '';
    }
}
