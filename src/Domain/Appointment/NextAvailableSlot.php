<?php

declare(strict_types=1);
namespace ClyoNutri\Domain\Appointment;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Booking\BookingCalendarService;
use DateTimeImmutable;
use PDO;

/** Batch calendar reads for this search only. Booking/conflict validation remains canonical and live. */
final class NextAvailableSlot
{
    public function __construct(private PDO $db, private ?DateTimeImmutable $now = null) {}

    public function find(int $professional, int $duration, ?string $fromDate = null): ?string
    {
        \ClyoNutri\Core\Security\TenantGuard::assertProfessionalBelongsToClinic($professional, TenantContext::getClinicId(), $this->db);
        $calendar = new BookingCalendarService($this->db);
        $service = new AppointmentService($this->db, $this->now);
        $now = ($this->now ?? new DateTimeImmutable('now', $calendar::tz()))->setTimezone($calendar::tz());
        $today = $now->format('Y-m-d');
        $base = $fromDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fromDate) && $fromDate >= $today
            ? new DateTimeImmutable($fromDate, $calendar::tz()) : $now;
        $clinic = TenantContext::getClinicId();
        $preferences = $service->practicePreferences($professional);
        $rules = $calendar->agendaRules($clinic, $base->format('Y-m-d'), $base->modify('+59 days')->format('Y-m-d'));
        for ($i = 0; $i < 60; ++$i) {
            $date = $base->modify("+$i days")->format('Y-m-d');
            // A closed day cannot yield a slot. Avoid rereading settings, identity and rules for it.
            if (!$calendar->windows($clinic, $professional, $date, $preferences['weekly_availability'] ?? [], true, $rules[$date] ?? [])) continue;
            $preview = $service->availabilityPreview($professional, $date, max(15, min(240, $duration ?: 60)));
            foreach ($preview['times'] ?? [] as $time) {
                if ($date === $today && $time <= $now->format('H:i')) continue;
                return $date . 'T' . $time;
            }
        }
        return null;
    }
}
