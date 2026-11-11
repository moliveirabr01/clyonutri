<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Appointment;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Booking\BookingService;
use PDO;

/** The weekly UI, preview and public portal all read BookingService's canonical slot engine. */
final class WeeklyAvailability
{
    public function __construct(private PDO $db) {}
    public function forDays(int $professional,array $dates,int $duration): array
    {
        $clinic=TenantContext::getClinicId();TenantGuard::assertProfessionalBelongsToClinic($professional,$clinic,$this->db);
        if(count($dates)>7)throw new \InvalidArgumentException('Consulte até sete dias.');
        $service=new BookingService($this->db);$out=[];
        foreach($dates as $date)$out[$date]=$service->getAvailableSlots($clinic,$professional,(string)$date,$duration,'presential',null,false,null,true);
        return $out;
    }
}
