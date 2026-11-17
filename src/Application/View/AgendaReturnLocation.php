<?php

declare(strict_types=1);
namespace ClyoNutri\Application\View;

final class AgendaReturnLocation
{
    public static function validDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value) || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))) return false;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    public static function make(array $input, ?string $fallback = null, array $feedback = []): string
    {
        $view=is_string($input['view']??null) && in_array($input['view'],['day','week','month'],true)?$input['view']:'day';
        $date=self::validDate($input['date']??null)?$input['date']:substr((string)$fallback,0,10);
        if(!self::validDate($date))$date=(new \DateTimeImmutable('now',new \DateTimeZone(getenv('APP_TIMEZONE')?:date_default_timezone_get())))->format('Y-m-d');
        return '/agenda?'.http_build_query(['view'=>$view,'date'=>$date]+$feedback,'','&',PHP_QUERY_RFC3986);
    }
}
