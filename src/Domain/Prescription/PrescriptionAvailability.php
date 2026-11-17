<?php

declare(strict_types=1);
namespace ClyoNutri\Domain\Prescription;

use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/** Same validated clinic catalog powers availability and the editor. No global or cross-clinic fallback. */
final class PrescriptionAvailability
{
    private const CATALOG = " FROM rx_ingredients i
        JOIN rx_ingredient_rules r ON r.ingredient_id=i.id AND r.clinic_id=i.clinic_id
        JOIN rx_policy_versions p ON p.id=r.policy_id AND p.clinic_id=i.clinic_id
        WHERE i.clinic_id=? AND i.enabled=1 AND r.decision='allow' AND p.enabled=1
          AND p.effective_from<=? AND p.effective_until>=?
          AND i.category IN ('supplement','magistral_supplement','phytotherapy')";

    public function __construct(private PDO $db) {}

    public function available(int $clinic): bool
    {
        if ($clinic <= 0 || !$this->installed()) return false;
        $st = $this->db->prepare('SELECT 1' . self::CATALOG . ' LIMIT 1');
        $st->execute([$clinic, gmdate('Y-m-d'), gmdate('Y-m-d')]);
        return $st->fetchColumn() !== false;
    }

    public function catalog(int $clinic): array
    {
        if ($clinic <= 0 || !$this->installed()) return [];
        $st = $this->db->prepare('SELECT DISTINCT i.id,i.name,i.category,r.constraints_json' . self::CATALOG . ' ORDER BY i.name LIMIT 500');
        $st->execute([$clinic, gmdate('Y-m-d'), gmdate('Y-m-d')]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function installed(): bool
    {
        return SchemaInfo::hasTable($this->db, 'rx_ingredients')
            && SchemaInfo::hasTable($this->db, 'rx_ingredient_rules')
            && SchemaInfo::hasTable($this->db, 'rx_policy_versions');
    }
}
