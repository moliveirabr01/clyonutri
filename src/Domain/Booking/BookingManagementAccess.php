<?php

declare(strict_types=1);
namespace ClyoNutri\Domain\Booking;
use PDO;
use ClyoNutri\Domain\Settings\ProfessionalProfile;

final class BookingManagementAccess
{
    public static function allowed(PDO $db): bool
    {
        try {
            // Same active user/clinic/member checks as the destination SettingsController.
            $profile=(new ProfessionalProfile($db))->read();
            return in_array($profile['member_role'], ['owner','admin'], true);
        } catch (\DomainException) {return false;}
    }
}
