<?php
declare(strict_types=1);
namespace ClyoNutri\Application\View;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\ClinicalProtocol\TransversalSummary;
use PDO;

final class AdaptiveSummary
{
    public static function render(PDO $db, int $patient, ?array $s = null): string
    {
        $s ??= (new TransversalSummary($db))->read(TenantContext::getClinicId(), $patient);
        if (!$s) return '';
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ob_start();
        require __DIR__ . '/../../../views/patients/_transversal_summary.php';
        return (string)ob_get_clean();
    }
}
