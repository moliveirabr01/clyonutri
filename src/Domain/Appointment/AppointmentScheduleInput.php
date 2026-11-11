<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Appointment;
use ClyoNutri\Domain\Booking\BookingCalendarService;
use InvalidArgumentException;

/** Keeps native date and time controls independent without relying on client-side composition. */
final class AppointmentScheduleInput
{
    public static function fromArray(array $input): string
    {
        if(array_key_exists('scheduled_date',$input) || array_key_exists('scheduled_time',$input)){
            if(!is_string($input['scheduled_date']??null) || !is_string($input['scheduled_time']??null))throw new InvalidArgumentException('Informe a data e o horário da consulta.');
            $date=BookingCalendarService::date(trim($input['scheduled_date']));$time=BookingCalendarService::time(trim($input['scheduled_time']));
            return $date.'T'.$time;
        }
        if(!is_string($input['scheduled_at']??null) || trim($input['scheduled_at'])==='')throw new InvalidArgumentException('Informe a data e o horário da consulta.');
        return trim($input['scheduled_at']);
    }
    public static function recoveryDate(array $input): string
    {
        $date=is_string($input['scheduled_date']??null)?$input['scheduled_date']:(is_string($input['scheduled_at']??null)?substr($input['scheduled_at'],0,10):'');
        try{return BookingCalendarService::date($date);}catch(InvalidArgumentException){return (new \DateTimeImmutable('now',BookingCalendarService::tz()))->format('Y-m-d');}
    }

}
