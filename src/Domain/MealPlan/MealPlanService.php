<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use ClyoNutri\Domain\Nutrition\NutritionCalculator;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class MealPlanService
{
    private PDO $db;
    private NutritionCalculator $calculator;
    private AllergenSafetyValidator $allergenValidator;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->calculator = new NutritionCalculator($this->db);
        $this->allergenValidator = new AllergenSafetyValidator($this->db);
    }

    /**
     * Cria um novo plano alimentar como RASCUNHO (draft) - NI2-76
     */
    public function createPlan(
        int $patientId,
        int $professionalId,
        string $title,
        array|string|null $notesOrMeals = null,
        array $meals = [],
        ?int $parentPlanId = null
    ): array {
        $notes = null;
        if (is_array($notesOrMeals)) {
            $meals = $notesOrMeals;
        } elseif (is_string($notesOrMeals)) {
            $notes = $notesOrMeals;
        }
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

        $cleanTitle = trim($title);
        if ($cleanTitle === '') {
            throw new InvalidArgumentException("O título do plano alimentar é obrigatório.");
        }

        if ($parentPlanId !== null) {
            TenantGuard::assertMealPlanBelongsToClinic($parentPlanId, $clinicId, $this->db);
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO meal_plans (
                    clinic_id, patient_id, professional_id, title, notes,
                    status, version, parent_plan_id, daily_energy_kcal,
                    protein_g, carbohydrate_g, lipid_g, fiber_g, created_at, updated_at
                ) VALUES (
                    :clinic_id, :patient_id, :professional_id, :title, :notes,
                    'draft', 1, :parent_plan_id, 0.0,
                    0.0, 0.0, 0.0, 0.0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
                'title' => $cleanTitle,
                'notes' => $notes !== null ? trim($notes) : null,
                'parent_plan_id' => $parentPlanId,
            ]);
            $planId = (int)$this->db->lastInsertId();

            if (!empty($meals)) {
                $order = 1;
                foreach ($meals as $m) {
                    $mStmt = $this->db->prepare("
                        INSERT INTO meal_plan_meals (meal_plan_id, name, time_of_day, notes, ordering)
                        VALUES (:plan_id, :name, :time_of_day, :notes, :ordering)
                    ");
                    $mStmt->execute([
                        'plan_id' => $planId,
                        'name' => trim($m['name']),
                        'time_of_day' => !empty($m['time_of_day']) ? trim($m['time_of_day']) : null,
                        'notes' => !empty($m['notes']) ? trim($m['notes']) : null,
                        'ordering' => $order++,
                    ]);
                    $mealId = (int)$this->db->lastInsertId();

                    if (!empty($m['items'])) {
                        $itemOrder = 1;
                        foreach ($m['items'] as $item) {
                            $foodId = (int)$item['food_id'];
                            $amount = (float)$item['amount'];
                            $unit = $item['measure_unit'] ?? 'g';
                            $itemNotes = $item['notes'] ?? null;

                            // Buscar alimento para calcular macros
                            $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id");
                            $fStmt->execute(['id' => $foodId]);
                            $food = $fStmt->fetch();

                            $kcal = 0.0;
                            $prot = 0.0;
                            $carb = 0.0;
                            $lip = 0.0;
                            $fib = 0.0;

                            if ($food) {
                                $calc = $this->calculator->calculateForPortion($food, $amount, $unit, $foodId);
                                $kcal = $calc['energy_kcal'];
                                $prot = $calc['protein_g'];
                                $carb = $calc['carbohydrate_g'];
                                $lip = $calc['lipid_g'];
                                $fib = $calc['fiber_g'];
                            }

                            $iStmt = $this->db->prepare("
                                INSERT INTO meal_plan_items (
                                    meal_id, food_id, amount, measure_unit, notes, ordering,
                                    energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g
                                ) VALUES (
                                    :meal_id, :food_id, :amount, :measure_unit, :notes, :ordering,
                                    :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g
                                )
                            ");
                            $iStmt->execute([
                                'meal_id' => $mealId,
                                'food_id' => $foodId,
                                'amount' => $amount,
                                'measure_unit' => $unit,
                                'notes' => $itemNotes,
                                'ordering' => $itemOrder++,
                                'energy_kcal' => $kcal,
                                'protein_g' => $prot,
                                'carbohydrate_g' => $carb,
                                'lipid_g' => $lip,
                                'fiber_g' => $fib,
                            ]);
                            $itemId = (int)$this->db->lastInsertId();

                            if (!empty($item['substitutions'])) {
                                foreach ($item['substitutions'] as $sub) {
                                    $subFoodId = (int)$sub['food_id'];
                                    $subAmount = (float)$sub['amount'];
                                    $subUnit = $sub['measure_unit'] ?? 'g';
                                    $subNotes = $sub['notes'] ?? null;

                                    $sfStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id");
                                    $sfStmt->execute(['id' => $subFoodId]);
                                    $subFood = $sfStmt->fetch();

                                    $sKcal = 0.0;
                                    $sProt = 0.0;
                                    $sCarb = 0.0;
                                    $sLip = 0.0;
                                    $sFib = 0.0;

                                    if ($subFood) {
                                        $sCalc = $this->calculator->calculateForPortion($subFood, $subAmount, $subUnit, $subFoodId);
                                        $sKcal = $sCalc['energy_kcal'];
                                        $sProt = $sCalc['protein_g'];
                                        $sCarb = $sCalc['carbohydrate_g'];
                                        $sLip = $sCalc['lipid_g'];
                                        $sFib = $sCalc['fiber_g'];
                                    }

                                    $sStmt = $this->db->prepare("
                                        INSERT INTO meal_plan_substitutions (
                                            item_id, food_id, amount, measure_unit, notes,
                                            energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g
                                        ) VALUES (
                                            :item_id, :food_id, :amount, :measure_unit, :notes,
                                            :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g
                                        )
                                    ");
                                    $sStmt->execute([
                                        'item_id' => $itemId,
                                        'food_id' => $subFoodId,
                                        'amount' => $subAmount,
                                        'measure_unit' => $subUnit,
                                        'notes' => $subNotes,
                                        'energy_kcal' => $sKcal,
                                        'protein_g' => $sProt,
                                        'carbohydrate_g' => $sCarb,
                                        'lipid_g' => $sLip,
                                        'fiber_g' => $sFib,
                                    ]);
                                }
                            }
                        }
                    }
                }
            }

            // Recalcular totais consolidados
            $totals = $this->calculateAndPersistPlanTotals($planId);

            $this->db->commit();

            return [
                'plan_id' => $planId,
                'title' => $cleanTitle,
                'status' => 'draft',
                'parent_plan_id' => $parentPlanId,
                'totals' => $totals,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Adiciona uma nova refeição ao plano alimentar (NI2-76)
     */
    public function addMeal(int $planId, string $name, ?string $timeOfDay = null, ?string $notes = null): int
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);
        $this->assertPlanIsEditable($planId);

        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException("O nome da refeição é obrigatório.");
        }

        if ($timeOfDay !== null && trim($timeOfDay) !== '') {
            $timeOfDay = trim($timeOfDay);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
                throw new InvalidArgumentException("Horário inválido. Utilize o formato HH:MM (ex: 08:00, 12:30).");
            }
        } else {
            $timeOfDay = null;
        }

        $oStmt = $this->db->prepare("SELECT COALESCE(MAX(ordering), 0) + 1 FROM meal_plan_meals WHERE meal_plan_id = :plan_id");
        $oStmt->execute(['plan_id' => $planId]);
        $nextOrder = (int)$oStmt->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO meal_plan_meals (meal_plan_id, name, time_of_day, notes, ordering)
            VALUES (:plan_id, :name, :time_of_day, :notes, :ordering)
        ");
        $stmt->execute([
            'plan_id' => $planId,
            'name' => $cleanName,
            'time_of_day' => $timeOfDay,
            'notes' => $notes !== null ? trim($notes) : null,
            'ordering' => $nextOrder,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Atualiza dados de uma refeição existente (NI2-76)
     */
    public function updateMeal(int $mealId, string $name, ?string $timeOfDay = null, ?string $notes = null): bool
    {
        $meal = $this->findMealWithTenantCheck($mealId);
        $this->assertPlanIsEditable((int)$meal['meal_plan_id']);

        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException("O nome da refeição é obrigatório.");
        }

        if ($timeOfDay !== null && trim($timeOfDay) !== '') {
            $timeOfDay = trim($timeOfDay);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
                throw new InvalidArgumentException("Horário inválido. Utilize o formato HH:MM (ex: 08:00, 12:30).");
            }
        } else {
            $timeOfDay = null;
        }

        $stmt = $this->db->prepare("
            UPDATE meal_plan_meals
            SET name = :name, time_of_day = :time_of_day, notes = :notes
            WHERE id = :id
        ");
        return $stmt->execute([
            'name' => $cleanName,
            'time_of_day' => $timeOfDay,
            'notes' => $notes !== null ? trim($notes) : null,
            'id' => $mealId,
        ]);
    }

    /**
     * Remove uma refeição do plano e recalcula totais em cascata (NI2-76)
     */
    public function deleteMeal(int $mealId): bool
    {
        $meal = $this->findMealWithTenantCheck($mealId);
        $planId = (int)$meal['meal_plan_id'];
        $this->assertPlanIsEditable($planId);

        $stmt = $this->db->prepare("DELETE FROM meal_plan_meals WHERE id = :id");
        $res = $stmt->execute(['id' => $mealId]);

        $this->calculateAndPersistPlanTotals($planId);
        return $res;
    }

    /**
     * Reordena as refeições de um plano (NI2-76)
     */
    public function reorderMeals(int $planId, array $mealIdsInOrder): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);
        $this->assertPlanIsEditable($planId);

        $order = 1;
        $stmt = $this->db->prepare("UPDATE meal_plan_meals SET ordering = :order WHERE id = :id AND meal_plan_id = :plan_id");
        foreach ($mealIdsInOrder as $mealId) {
            $stmt->execute([
                'order' => $order++,
                'id' => (int)$mealId,
                'plan_id' => $planId,
            ]);
        }
        return true;
    }

    /**
     * Adiciona um alimento à refeição, calculando macros determinísticos (NI2-77)
     */
    public function addItem(
        int $mealId,
        int $foodId,
        float $amount,
        string $measureUnit = 'g',
        ?string $notes = null
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade prescrita deve ser estritamente maior que zero.");
        }

        $meal = $this->findMealWithTenantCheck($mealId);
        $planId = (int)$meal['meal_plan_id'];
        $this->assertPlanIsEditable($planId);

        // Obter alimento da tabela foods
        $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id");
        $fStmt->execute(['id' => $foodId]);
        $food = $fStmt->fetch();
        if (!$food) {
            throw new InvalidArgumentException("Alimento com ID {$foodId} não encontrado no catálogo.");
        }

        // Calcular macronutrientes proporcionais (100g ou medida caseira)
        $nutrition = $this->calculator->calculateForPortion($food, $amount, $measureUnit, $foodId);

        // Próxima ordenação do item na refeição
        $oStmt = $this->db->prepare("SELECT COALESCE(MAX(ordering), 0) + 1 FROM meal_plan_items WHERE meal_id = :meal_id");
        $oStmt->execute(['meal_id' => $mealId]);
        $nextOrder = (int)$oStmt->fetchColumn();

        $stmt = $this->db->prepare("
            INSERT INTO meal_plan_items (
                meal_id, food_id, amount, measure_unit, notes, ordering,
                energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g
            ) VALUES (
                :meal_id, :food_id, :amount, :measure_unit, :notes, :ordering,
                :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g
            )
        ");
        $stmt->execute([
            'meal_id' => $mealId,
            'food_id' => $foodId,
            'amount' => $amount,
            'measure_unit' => trim($measureUnit),
            'notes' => $notes !== null ? trim($notes) : null,
            'ordering' => $nextOrder,
            'energy_kcal' => $nutrition['energy_kcal'],
            'protein_g' => $nutrition['protein_g'],
            'carbohydrate_g' => $nutrition['carbohydrate_g'],
            'lipid_g' => $nutrition['lipid_g'],
            'fiber_g' => $nutrition['fiber_g'],
        ]);
        $itemId = (int)$this->db->lastInsertId();

        // Recalcular totais consolidados do plano em cascata
        $this->calculateAndPersistPlanTotals($planId);

        return $itemId;
    }

    /**
     * Atualiza quantidade ou notas de um item de refeição, recalculando totais (NI2-77)
     */
    public function updateItem(
        int $itemId,
        float $amount,
        string $measureUnit = 'g',
        ?string $notes = null
    ): bool {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade prescrita deve ser maior que zero.");
        }

        $item = $this->findItemWithTenantCheck($itemId);
        $meal = $this->findMealWithTenantCheck((int)$item['meal_id']);
        $planId = (int)$meal['meal_plan_id'];
        $this->assertPlanIsEditable($planId);

        $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id");
        $fStmt->execute(['id' => (int)$item['food_id']]);
        $food = $fStmt->fetch();
        if (!$food) {
            throw new InvalidArgumentException("Alimento associado não encontrado.");
        }

        $nutrition = $this->calculator->calculateForPortion($food, $amount, $measureUnit, (int)$item['food_id']);

        $stmt = $this->db->prepare("
            UPDATE meal_plan_items
            SET amount = :amount, measure_unit = :measure_unit, notes = :notes,
                energy_kcal = :energy_kcal, protein_g = :protein_g, carbohydrate_g = :carbohydrate_g,
                lipid_g = :lipid_g, fiber_g = :fiber_g
            WHERE id = :id
        ");
        $stmt->execute([
            'amount' => $amount,
            'measure_unit' => trim($measureUnit),
            'notes' => $notes !== null ? trim($notes) : null,
            'energy_kcal' => $nutrition['energy_kcal'],
            'protein_g' => $nutrition['protein_g'],
            'carbohydrate_g' => $nutrition['carbohydrate_g'],
            'lipid_g' => $nutrition['lipid_g'],
            'fiber_g' => $nutrition['fiber_g'],
            'id' => $itemId,
        ]);

        $this->calculateAndPersistPlanTotals($planId);
        return true;
    }

    /**
     * Remove um item da refeição e recalcula o plano em cascata (NI2-77)
     */
    public function deleteItem(int $itemId): bool
    {
        $item = $this->findItemWithTenantCheck($itemId);
        $meal = $this->findMealWithTenantCheck((int)$item['meal_id']);
        $planId = (int)$meal['meal_plan_id'];
        $this->assertPlanIsEditable($planId);

        $stmt = $this->db->prepare("DELETE FROM meal_plan_items WHERE id = :id");
        $res = $stmt->execute(['id' => $itemId]);

        $this->calculateAndPersistPlanTotals($planId);
        return $res;
    }

    /**
     * Configura uma alternativa de substituição vinculada a um item (NI2-78)
     */
    public function addSubstitution(
        int $itemId,
        int $foodId,
        float $amount,
        string $measureUnit = 'g',
        ?string $notes = null
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade da substituição deve ser estritamente maior que zero.");
        }

        $item = $this->findItemWithTenantCheck($itemId);
        $meal = $this->findMealWithTenantCheck((int)$item['meal_id']);
        $planId = (int)$meal['meal_plan_id'];
        $this->assertPlanIsEditable($planId);

        $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id");
        $fStmt->execute(['id' => $foodId]);
        $food = $fStmt->fetch();
        if (!$food) {
            throw new InvalidArgumentException("Alimento de substituição com ID {$foodId} não encontrado.");
        }

        // Calcular macros da substituição para consulta e comparação de equivalência
        $nutrition = $this->calculator->calculateForPortion($food, $amount, $measureUnit, $foodId);

        $stmt = $this->db->prepare("
            INSERT INTO meal_plan_substitutions (
                item_id, food_id, amount, measure_unit, notes,
                energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g
            ) VALUES (
                :item_id, :food_id, :amount, :measure_unit, :notes,
                :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g
            )
        ");
        $stmt->execute([
            'item_id' => $itemId,
            'food_id' => $foodId,
            'amount' => $amount,
            'measure_unit' => trim($measureUnit),
            'notes' => $notes !== null ? trim($notes) : null,
            'energy_kcal' => $nutrition['energy_kcal'],
            'protein_g' => $nutrition['protein_g'],
            'carbohydrate_g' => $nutrition['carbohydrate_g'],
            'lipid_g' => $nutrition['lipid_g'],
            'fiber_g' => $nutrition['fiber_g'],
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Remove uma substituição (NI2-78)
     */
    public function deleteSubstitution(int $subId): bool
    {
        $clinicId = TenantContext::getClinicId();
        $sStmt = $this->db->prepare("
            SELECT s.*, mp.id AS meal_plan_id, mp.clinic_id, mp.status
            FROM meal_plan_substitutions s
            JOIN meal_plan_items i ON i.id = s.item_id
            JOIN meal_plan_meals m ON m.id = i.meal_id
            JOIN meal_plans mp ON mp.id = m.meal_plan_id
            WHERE s.id = :id
        ");
        $sStmt->execute(['id' => $subId]);
        $sub = $sStmt->fetch();

        if (!$sub || (int)$sub['clinic_id'] !== $clinicId) {
            throw new InvalidArgumentException("Substituição não encontrada no tenant ativo.");
        }

        if ($sub['status'] !== 'draft') {
            throw new RuntimeException("Planos alimentares publicados ou arquivados são imutáveis.");
        }

        $del = $this->db->prepare("DELETE FROM meal_plan_substitutions WHERE id = :id");
        return $del->execute(['id' => $subId]);
    }

    /**
     * Obtém estrutura hierárquica completa do plano alimentar (NI2-76, NI2-77, NI2-78)
     */
    public function getPlanWithDetails(int $planId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        $pStmt = $this->db->prepare("
            SELECT mp.*, p.name AS patient_name, p.birth_date AS patient_birth_date, u.name AS professional_name
            FROM meal_plans mp
            JOIN patients p ON p.id = mp.patient_id
            JOIN professionals pr ON pr.id = mp.professional_id
            JOIN users u ON u.id = pr.user_id
            WHERE mp.id = :id AND mp.clinic_id = :clinic_id
        ");
        $pStmt->execute(['id' => $planId, 'clinic_id' => $clinicId]);
        $plan = $pStmt->fetch();

        if (!$plan) {
            return null;
        }

        $mStmt = $this->db->prepare("
            SELECT * FROM meal_plan_meals
            WHERE meal_plan_id = :plan_id
            ORDER BY ordering ASC, id ASC
        ");
        $mStmt->execute(['plan_id' => $planId]);
        $meals = $mStmt->fetchAll();

        foreach ($meals as &$meal) {
            $iStmt = $this->db->prepare("
                SELECT i.*, f.name AS food_name, f.category AS food_category, f.allergens
                FROM meal_plan_items i
                JOIN foods f ON f.id = i.food_id
                WHERE i.meal_id = :meal_id
                ORDER BY i.ordering ASC, i.id ASC
            ");
            $iStmt->execute(['meal_id' => $meal['id']]);
            $items = $iStmt->fetchAll();

            $mealKcal = 0.0;
            $mealProt = 0.0;
            $mealCarb = 0.0;
            $mealLip = 0.0;
            $mealFib = 0.0;

            foreach ($items as &$item) {
                $mealKcal += (float)$item['energy_kcal'];
                $mealProt += (float)$item['protein_g'];
                $mealCarb += (float)$item['carbohydrate_g'];
                $mealLip += (float)$item['lipid_g'];
                $mealFib += (float)$item['fiber_g'];

                $sStmt = $this->db->prepare("
                    SELECT s.*, f.name AS food_name, f.category AS food_category, f.allergens
                    FROM meal_plan_substitutions s
                    JOIN foods f ON f.id = s.food_id
                    WHERE s.item_id = :item_id
                    ORDER BY s.id ASC
                ");
                $sStmt->execute(['item_id' => $item['id']]);
                $item['substitutions'] = $sStmt->fetchAll();
            }

            $meal['totals'] = [
                'energy_kcal' => round($mealKcal, 2),
                'protein_g' => round($mealProt, 2),
                'carbohydrate_g' => round($mealCarb, 2),
                'lipid_g' => round($mealLip, 2),
                'fiber_g' => round($mealFib, 2),
            ];
            $meal['items'] = $items;
        }

        $plan['meals'] = $meals;
        return $plan;
    }

    /**
     * Gera prévia de conferência fiel com validação de segurança clínica (NI2-79)
     * Diferencia expressamente consumo simultâneo (refeição principal) de alternativas (substituições).
     */
    public function getPlanPreview(int $planId): array
    {
        $plan = $this->getPlanWithDetails($planId);
        if (!$plan) {
            throw new InvalidArgumentException("Plano alimentar não encontrado.");
        }

        // Validação clínica de alergênicos contra a anamnese do paciente
        $foodIds = [];
        foreach ($plan['meals'] as $m) {
            foreach ($m['items'] as $it) {
                $foodIds[] = (int)$it['food_id'];
                foreach ($it['substitutions'] as $sub) {
                    $foodIds[] = (int)$sub['food_id'];
                }
            }
        }
        $foodIds = array_unique($foodIds);

        $allergenSafety = $this->allergenValidator->validateFoodsForPatient((int)$plan['patient_id'], $foodIds);

        return [
            'plan' => $plan,
            'is_ready_to_publish' => count($plan['meals']) > 0 && (float)$plan['daily_energy_kcal'] > 0,
            'allergen_safety' => $allergenSafety,
            'summary' => [
                'meals_count' => count($plan['meals']),
                'daily_energy_kcal' => (float)$plan['daily_energy_kcal'],
                'protein_g' => (float)$plan['protein_g'],
                'carbohydrate_g' => (float)$plan['carbohydrate_g'],
                'lipid_g' => (float)$plan['lipid_g'],
                'fiber_g' => (float)$plan['fiber_g'],
            ],
        ];
    }

    /**
     * Publica o plano alimentar com snapshot imutável, versionamento e auditoria (NI2-79)
     */
    public function publishPlan(int $planId, bool $allowAllergenOverride = false, bool $returnDetails = false): bool|array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        $preview = $this->getPlanPreview($planId);
        $plan = $preview['plan'];

        if ($plan['status'] === 'published') {
            throw new RuntimeException("Este plano alimentar já está publicado.");
        }

        if (empty($plan['meals'])) {
            throw new InvalidArgumentException("Não é possível publicar um plano alimentar sem nenhuma refeição cadastrada.");
        }

        $hasItems = false;
        foreach ($plan['meals'] as $m) {
            if (!empty($m['items'])) {
                $hasItems = true;
                break;
            }
        }
        if (!$hasItems) {
            throw new InvalidArgumentException("Não é possível publicar um plano alimentar sem nenhum alimento prescrito.");
        }

        // Bloqueio por conflito de alergênicos
        if ($preview['allergen_safety']['has_conflict'] && !$allowAllergenOverride) {
            $conflictNames = array_map(
                fn($c) => $c['food_name'] . " (" . $c['allergen_group'] . ")",
                $preview['allergen_safety']['conflicts']
            );
            throw new InvalidArgumentException(
                "Publicação bloqueada por segurança clínica: conflito de alergênicos detectado [" .
                implode(', ', $conflictNames) . "]. Marque autorização expressa do nutricionista se desejar prosseguir."
            );
        }

        $patientId = (int)$plan['patient_id'];
        $professionalId = (int)$plan['professional_id'];

        $this->db->beginTransaction();
        try {
            // Obter próximo número sequencial de versão do plano para o paciente
            $vStmt = $this->db->prepare("
                SELECT COALESCE(MAX(version_number), 0) + 1
                FROM meal_plan_versions
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ");
            $vStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
            $nextVersionNumber = (int)$vStmt->fetchColumn();

            // Desativar versão publicada anterior em meal_plan_versions
            $supVer = $this->db->prepare("
                UPDATE meal_plan_versions
                SET status = 'superseded'
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND status = 'published'
            ");
            $supVer->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);

            // Desativar outros planos publicados do paciente em meal_plans
            $supPlan = $this->db->prepare("
                UPDATE meal_plans
                SET status = 'superseded'
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND status = 'published' AND id != :plan_id
            ");
            $supPlan->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'plan_id' => $planId,
            ]);

            $publishedAt = date('Y-m-d H:i:s');
            $snapshotPayload = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            // Gravar snapshot imutável em meal_plan_versions
            $insVer = $this->db->prepare("
                INSERT INTO meal_plan_versions (
                    clinic_id, meal_plan_id, patient_id, professional_id,
                    version_number, title, daily_energy_kcal, payload_json,
                    status, published_at, created_at
                ) VALUES (
                    :clinic_id, :meal_plan_id, :patient_id, :professional_id,
                    :version_number, :title, :daily_energy_kcal, :payload_json,
                    'published', :published_at, :created_at
                )
            ");
            $insVer->execute([
                'clinic_id' => $clinicId,
                'meal_plan_id' => $planId,
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
                'version_number' => $nextVersionNumber,
                'title' => $plan['title'],
                'daily_energy_kcal' => (float)$plan['daily_energy_kcal'],
                'payload_json' => $snapshotPayload,
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
            ]);

            // Atualizar o plano para status 'published'
            $upPlan = $this->db->prepare("
                UPDATE meal_plans
                SET status = 'published', published_at = :published_at, version = :version, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $upPlan->execute([
                'published_at' => $publishedAt,
                'version' => $nextVersionNumber,
                'id' => $planId,
                'clinic_id' => $clinicId,
            ]);

            // Registrar trilha de auditoria clínica
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
                VALUES (:clinic_id, 'MEAL_PLAN_PUBLISHED', 'meal_plans', :res_id, :details)
            ");
            $auditStmt->execute([
                'clinic_id' => $clinicId,
                'res_id' => (string)$planId,
                'details' => json_encode([
                    'patient_id' => $patientId,
                    'version_number' => $nextVersionNumber,
                    'daily_energy_kcal' => $plan['daily_energy_kcal'],
                    'allergen_override' => $allowAllergenOverride,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->db->commit();

            if ($returnDetails) {
                return [
                    'plan_id' => $planId,
                    'version' => $nextVersionNumber,
                    'status' => 'published',
                    'published_at' => $publishedAt,
                ];
            }

            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Duplica um plano gerando um novo RASCUNHO sem alterar o plano original (NI2-80)
     * Preserva linhagem através de parent_plan_id.
     */
    public function duplicatePlan(int $planId, ?string $newTitle = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        $orig = $this->getPlanWithDetails($planId);
        if (!$orig) {
            throw new InvalidArgumentException("Plano alimentar de origem não encontrado.");
        }

        $cleanTitle = $newTitle !== null && trim($newTitle) !== ''
            ? trim($newTitle)
            : "[Cópia] " . $orig['title'];

        // Preparar estrutura profunda de refeições, itens e substituições
        $clonedMeals = [];
        foreach ($orig['meals'] as $m) {
            $clonedItems = [];
            foreach ($m['items'] as $it) {
                $clonedSubs = [];
                foreach ($it['substitutions'] as $s) {
                    $clonedSubs[] = [
                        'food_id' => (int)$s['food_id'],
                        'amount' => (float)$s['amount'],
                        'measure_unit' => $s['measure_unit'],
                        'notes' => $s['notes'],
                    ];
                }

                $clonedItems[] = [
                    'food_id' => (int)$it['food_id'],
                    'amount' => (float)$it['amount'],
                    'measure_unit' => $it['measure_unit'],
                    'notes' => $it['notes'],
                    'substitutions' => $clonedSubs,
                ];
            }

            $clonedMeals[] = [
                'name' => $m['name'],
                'time_of_day' => $m['time_of_day'],
                'notes' => $m['notes'],
                'items' => $clonedItems,
            ];
        }

        // Criar novo plano draft com parent_plan_id apontando para o original
        return $this->createPlan(
            (int)$orig['patient_id'],
            (int)$orig['professional_id'],
            $cleanTitle,
            $orig['notes'],
            $clonedMeals,
            $planId
        );
    }

    /**
     * Retorna estritamente o plano alimentar publicado vigente do paciente (NI2-79)
     * Rascunhos e versões desativadas nunca chegam ao paciente.
     */
    public function getPatientPublishedPlan(int $patientId): ?array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT * FROM meal_plan_versions
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND status = 'published'
            ORDER BY version_number DESC
            LIMIT 1
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['payload'] = json_decode($row['payload_json'], true);
        unset($row['payload_json']);
        return $row;
    }

    /**
     * Alias para obter o plano ativo publicado do paciente (compatibilidade com suíte clínica)
     */
    public function getActivePublishedPlan(int $patientId): ?array
    {
        return $this->getPatientPublishedPlan($patientId);
    }

    /**
     * Lista o histórico de planos alimentares de um paciente
     */
    public function listPlansForPatient(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT mp.*, u.name AS professional_name
            FROM meal_plans mp
            JOIN professionals pr ON pr.id = mp.professional_id
            JOIN users u ON u.id = pr.user_id
            WHERE mp.clinic_id = :clinic_id AND mp.patient_id = :patient_id
            ORDER BY mp.id DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        return $stmt->fetchAll();
    }

    /**
     * Recalcula em cascata todos os totais nutricionais do plano alimentar e persiste em meal_plans
     */
    public function calculateAndPersistPlanTotals(int $planId): array
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(i.energy_kcal), 0) AS total_kcal,
                   COALESCE(SUM(i.protein_g), 0) AS total_prot,
                   COALESCE(SUM(i.carbohydrate_g), 0) AS total_carb,
                   COALESCE(SUM(i.lipid_g), 0) AS total_lip,
                   COALESCE(SUM(i.fiber_g), 0) AS total_fib
            FROM meal_plan_items i
            JOIN meal_plan_meals m ON m.id = i.meal_id
            WHERE m.meal_plan_id = :plan_id
        ");
        $stmt->execute(['plan_id' => $planId]);
        $totals = $stmt->fetch();

        $kcal = round((float)($totals['total_kcal'] ?? 0), 2);
        $prot = round((float)($totals['total_prot'] ?? 0), 2);
        $carb = round((float)($totals['total_carb'] ?? 0), 2);
        $lip = round((float)($totals['total_lip'] ?? 0), 2);
        $fib = round((float)($totals['total_fib'] ?? 0), 2);

        $up = $this->db->prepare("
            UPDATE meal_plans
            SET daily_energy_kcal = :kcal,
                protein_g = :prot,
                carbohydrate_g = :carb,
                lipid_g = :lip,
                fiber_g = :fib,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $up->execute([
            'kcal' => $kcal,
            'prot' => $prot,
            'carb' => $carb,
            'lip' => $lip,
            'fib' => $fib,
            'id' => $planId,
        ]);

        return [
            'energy_kcal' => $kcal,
            'protein_g' => $prot,
            'carbohydrate_g' => $carb,
            'lipid_g' => $lip,
            'fiber_g' => $fib,
        ];
    }

    private function assertPlanIsEditable(int $planId): void
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("SELECT status FROM meal_plans WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $planId, 'clinic_id' => $clinicId]);
        $status = $stmt->fetchColumn();

        if ($status === false) {
            throw new InvalidArgumentException("Plano alimentar não encontrado.");
        }

        if ($status !== 'draft') {
            throw new RuntimeException("Plano alimentar com status '{$status}' é imutável. Para realizar alterações, crie uma cópia/duplicação.");
        }
    }

    private function findMealWithTenantCheck(int $mealId): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT m.*, mp.clinic_id, mp.status AS plan_status
            FROM meal_plan_meals m
            JOIN meal_plans mp ON mp.id = m.meal_plan_id
            WHERE m.id = :id
        ");
        $stmt->execute(['id' => $mealId]);
        $meal = $stmt->fetch();

        if (!$meal || (int)$meal['clinic_id'] !== $clinicId) {
            throw new InvalidArgumentException("Refeição não encontrada no tenant ativo.");
        }

        return $meal;
    }

    private function findItemWithTenantCheck(int $itemId): array
    {
        $clinicId = TenantContext::getClinicId();
        $stmt = $this->db->prepare("
            SELECT i.*, mp.clinic_id, mp.status AS plan_status
            FROM meal_plan_items i
            JOIN meal_plan_meals m ON m.id = i.meal_id
            JOIN meal_plans mp ON mp.id = m.meal_plan_id
            WHERE i.id = :id
        ");
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch();

        if (!$item || (int)$item['clinic_id'] !== $clinicId) {
            throw new InvalidArgumentException("Item de refeição não encontrado no tenant ativo.");
        }

        return $item;
    }
}
