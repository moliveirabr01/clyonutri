<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class MealPlanService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function createPlan(int $patientId, int $professionalId, string $title, array $meals = []): array
    {
        $clinicId = TenantContext::getClinicId();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO meal_plans (clinic_id, patient_id, professional_id, title, status)
                VALUES (:clinic_id, :patient_id, :professional_id, :title, 'draft')
            ");
            $stmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
                'title' => trim($title),
            ]);
            $planId = (int)$this->db->lastInsertId();

            $totalKcal = 0.0;
            $order = 1;

            foreach ($meals as $m) {
                $mStmt = $this->db->prepare("
                    INSERT INTO meal_plan_meals (meal_plan_id, name, time_of_day, ordering)
                    VALUES (:plan_id, :name, :time_of_day, :ordering)
                ");
                $mStmt->execute([
                    'plan_id' => $planId,
                    'name' => $m['name'],
                    'time_of_day' => $m['time_of_day'] ?? null,
                    'ordering' => $order++,
                ]);
                $mealId = (int)$this->db->lastInsertId();

                if (!empty($m['items'])) {
                    $itemOrder = 1;
                    foreach ($m['items'] as $item) {
                        $iStmt = $this->db->prepare("
                            INSERT INTO meal_plan_items (meal_id, food_id, amount, measure_unit, ordering)
                            VALUES (:meal_id, :food_id, :amount, :measure_unit, :ordering)
                        ");
                        $iStmt->execute([
                            'meal_id' => $mealId,
                            'food_id' => $item['food_id'],
                            'amount' => $item['amount'],
                            'measure_unit' => $item['measure_unit'] ?? 'g',
                            'ordering' => $itemOrder++,
                        ]);
                        $itemId = (int)$this->db->lastInsertId();

                        // Calcular calorias proporcionais
                        $fStmt = $this->db->prepare("SELECT energy_kcal, serving_size_g FROM foods WHERE id = :id");
                        $fStmt->execute(['id' => $item['food_id']]);
                        $food = $fStmt->fetch();
                        if ($food && (float)$food['serving_size_g'] > 0) {
                            $itemKcal = ((float)$item['amount'] / (float)$food['serving_size_g']) * (float)$food['energy_kcal'];
                            $totalKcal += $itemKcal;
                        }

                        // Substituições opcionais
                        if (!empty($item['substitutions'])) {
                            foreach ($item['substitutions'] as $sub) {
                                $sStmt = $this->db->prepare("
                                    INSERT INTO meal_plan_substitutions (item_id, food_id, amount, measure_unit, notes)
                                    VALUES (:item_id, :food_id, :amount, :measure_unit, :notes)
                                ");
                                $sStmt->execute([
                                    'item_id' => $itemId,
                                    'food_id' => $sub['food_id'],
                                    'amount' => $sub['amount'],
                                    'measure_unit' => $sub['measure_unit'] ?? 'g',
                                    'notes' => $sub['notes'] ?? null,
                                ]);
                            }
                        }
                    }
                }
            }

            // Atualizar energia total calculada
            $upStmt = $this->db->prepare("UPDATE meal_plans SET daily_energy_kcal = :kcal WHERE id = :id");
            $upStmt->execute(['kcal' => round($totalKcal, 2), 'id' => $planId]);

            $this->db->commit();

            return [
                'plan_id' => $planId,
                'title' => $title,
                'status' => 'draft',
                'daily_energy_kcal' => round($totalKcal, 2),
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function publishPlan(int $planId): bool
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            UPDATE meal_plans
            SET status = 'published', published_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        return $stmt->execute(['id' => $planId, 'clinic_id' => $clinicId]);
    }
}
