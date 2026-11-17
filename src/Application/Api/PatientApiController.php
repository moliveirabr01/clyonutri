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
     * Retorna o plano alimentar ativo para o portal/app do paciente.
     *
     * Fonte: o snapshot imutável da versão publicada vigente (meal_plan_versions), ou seja, exatamente
     * o que o nutricionista revisou e publicou (NI2-79). Renomear um alimento do catálogo depois da
     * publicação não muda o que o paciente vê. Valores nutricionais são os da porção prescrita.
     * Planos publicados sem versão gravada (dados legados/fixtures) caem na leitura das tabelas.
     */
    public function getDailyMealPlan(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId,$clinicId,$this->db);

        $version = (new \ClyoNutri\Domain\MealPlan\MealPlanService($this->db))->getPatientPublishedPlan($patientId);
        if ($version !== null && is_array($version['payload'] ?? null)) {
            return $this->planFromSnapshot($version);
        }

        return $this->planFromLiveTables($patientId, $clinicId);
    }

    private function planFromSnapshot(array $version): array
    {
        $payload = $version['payload'];
        $plan = array_intersect_key($payload, array_flip([
            'id', 'clinic_id', 'patient_id', 'professional_id', 'title', 'notes', 'daily_energy_kcal',
            'protein_g', 'carbohydrate_g', 'lipid_g', 'fiber_g', 'parent_plan_id', 'professional_name',
        ]));
        $plan['id'] = (int)$version['meal_plan_id'];
        $plan['status'] = 'published';
        $plan['version'] = (int)$version['version_number'];
        $plan['version_number'] = (int)$version['version_number'];
        $plan['published_at'] = $this->atom((string)$version['published_at']);

        $meals = [];
        foreach ($payload['meals'] ?? [] as $m) {
            $items = [];
            foreach ($m['items'] ?? [] as $it) {
                $subs = [];
                foreach ($it['substitutions'] ?? [] as $s) {
                    $subs[] = self::pick($s, ['id', 'item_id', 'food_id', 'food_name', 'amount', 'measure_unit', 'notes',
                        'energy_kcal', 'protein_g', 'carbohydrate_g', 'lipid_g', 'fiber_g', 'weight_grams', 'weight_is_estimated']);
                }
                $item = self::pick($it, ['id', 'meal_id', 'food_id', 'food_name', 'amount', 'measure_unit', 'notes', 'ordering',
                    'energy_kcal', 'protein_g', 'carbohydrate_g', 'lipid_g', 'fiber_g', 'weight_grams', 'weight_is_estimated']);
                $item['quantity'] = $it['amount'] ?? 0;
                $item['unit'] = $it['measure_unit'] ?? 'g';
                $item['substitutions'] = $subs;
                $items[] = $item;
            }
            $meal = self::pick($m, ['id', 'meal_plan_id', 'name', 'time_of_day', 'ordering', 'notes']);
            $meal['time'] = $m['time_of_day'] ?? '';
            $meal['items'] = $items;
            $meals[] = $meal;
        }
        $plan['title'] = \ClyoNutri\Domain\MealPlan\PlanTitle::forPatient((string)($plan['title'] ?? ''));
        $plan['meals'] = $meals;
        return $plan;
    }

    /** Leitura legada (plano publicado sem versão gravada). Valores da porção, não por 100 g. */
    private function planFromLiveTables(int $patientId, int $clinicId): ?array
    {
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

        $mStmt = $this->db->prepare("
            SELECT * FROM meal_plan_meals WHERE meal_plan_id = :plan_id ORDER BY ordering ASC
        ");
        $mStmt->execute(['plan_id' => $plan['id']]);
        $meals = $mStmt->fetchAll();

        foreach ($meals as &$m) {
            $m['time'] = $m['time_of_day'] ?? ($m['time'] ?? '');
            $iStmt = $this->db->prepare("
                SELECT i.*, f.name AS food_name
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
            unset($it);
            $m['items'] = $items;
        }
        unset($m);

        if (!empty($plan['published_at'])) {
            $plan['published_at'] = $this->atom((string)$plan['published_at']);
        }
        $plan['title'] = \ClyoNutri\Domain\MealPlan\PlanTitle::forPatient((string)($plan['title'] ?? ''));
        $plan['meals'] = $meals;
        return $plan;
    }

    private function atom(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM);
    }

    private static function pick(array $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $row)) {
                $out[$k] = $row[$k];
            }
        }
        return $out;
    }

    public function logHydration(int $patientId, mixed $amountMl, array $options = []): array
    {
        return $this->engagementService->logHydration($patientId, $amountMl, $options);
    }

    /** NI2-92: histórico paginado por dia (mais recente primeiro). */
    public function getHydrationHistory(int $patientId, mixed $before = null, mixed $days = null): array
    {
        return $this->hydration()->history($patientId, $before, $days);
    }

    /** NI2-92: correção de um registro da própria paciente (até 24 h depois de feito). */
    public function updateHydration(int $patientId, int $entryId, array $changes): array
    {
        return $this->hydration()->update($patientId, $entryId, $changes);
    }

    /** NI2-92: exclusão lógica de um registro da própria paciente (até 24 h depois de feito). */
    public function deleteHydration(int $patientId, int $entryId): array
    {
        return $this->hydration()->delete($patientId, $entryId);
    }

    public function getHydrationTodayTotal(int $patientId): int
    {
        return $this->hydration()->todayTotal($patientId);
    }

    public function hydration(): \ClyoNutri\Domain\Engagement\HydrationService
    {
        return new \ClyoNutri\Domain\Engagement\HydrationService($this->db);
    }

    public function submitCheckin(int $patientId, array $data): array
    {
        return $this->engagementService->recordCheckin($patientId, $data);
    }

    public function getPrescribedWaterGoal(int $patientId): ?int
    {
        return $this->portal()->getPrescribedWaterGoal($patientId);
    }

    /**
     * Retorna lista de compras e receitas do plano alimentar publicado vigente (NI2-116).
     */
    public function getShoppingList(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $days = isset($_GET['days']) ? max(1, min(90, (int)$_GET['days'])) : 7;
        $service = new \ClyoNutri\Domain\ShoppingList\ShoppingListService($this->db);
        return $service->generateForPatient($patientId, $days);
    }

    public function portal(): \ClyoNutri\Domain\PatientPortal\PatientPortalService
    {
        return new \ClyoNutri\Domain\PatientPortal\PatientPortalService($this->db);
    }
}

