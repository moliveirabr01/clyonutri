<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Api;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Engagement\EngagementService;
use PDO;

class PatientApiController
{
    private PDO $db;
    private EngagementService $engagementService;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->engagementService = new EngagementService($this->db);
    }

    /**
     * Retorna o plano alimentar ativo para o portal/app do paciente
     */
    public function getDailyMealPlan(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT * FROM meal_plans
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND status = 'published'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $plan = $stmt->fetch();

        if (!$plan) {
            return null;
        }

        // Refeições e Itens
        $mStmt = $this->db->prepare("
            SELECT * FROM meal_plan_meals WHERE meal_plan_id = :plan_id ORDER BY ordering ASC
        ");
        $mStmt->execute(['plan_id' => $plan['id']]);
        $meals = $mStmt->fetchAll();

        foreach ($meals as &$m) {
            $m['time'] = $m['time_of_day'] ?? ($m['time'] ?? '');
            $iStmt = $this->db->prepare("
                SELECT i.*, f.name AS food_name, f.energy_kcal, f.protein_g, f.carbohydrate_g, f.lipid_g
                FROM meal_plan_items i
                JOIN foods f ON f.id = i.food_id
                WHERE i.meal_id = :meal_id
                ORDER BY i.ordering ASC
            ");
            $iStmt->execute(['meal_id' => $m['id']]);
            $items = $iStmt->fetchAll();

            foreach ($items as &$it) {
                $it['quantity'] = $it['amount'] ?? ($it['quantity'] ?? 0);
                $it['unit'] = $it['measure_unit'] ?? ($it['unit'] ?? 'g');
                $subStmt = $this->db->prepare("
                    SELECT s.*, f.name AS food_name
                    FROM meal_plan_substitutions s
                    JOIN foods f ON f.id = s.food_id
                    WHERE s.item_id = :item_id
                ");
                $subStmt->execute(['item_id' => $it['id']]);
                $it['substitutions'] = $subStmt->fetchAll();
            }
            $m['items'] = $items;
        }

        $plan['meals'] = $meals;
        return $plan;
    }

    public function logHydration(int $patientId, int $amountMl): array
    {
        return $this->engagementService->logHydration($patientId, $amountMl);
    }

    public function submitCheckin(int $patientId, array $data): array
    {
        return $this->engagementService->recordCheckin($patientId, $data);
    }

    public function getPrescribedWaterGoal(int $patientId): ?int
    {
        return $this->portal()->getPrescribedWaterGoal($patientId);
    }

    public function portal(): \ClyoNutri\Domain\PatientPortal\PatientPortalService
    {
        return new \ClyoNutri\Domain\PatientPortal\PatientPortalService($this->db);
    }
}

