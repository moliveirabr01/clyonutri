<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Audit\AuditLogger;
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

    /** Refeições padrão da criação rápida (NI2-514); o nutricionista pode renomear ou remover depois. */
    public const DEFAULT_MEALS = [
        ['name' => 'Café da manhã', 'time_of_day' => '07:30'],
        ['name' => 'Colação', 'time_of_day' => '10:00'],
        ['name' => 'Almoço', 'time_of_day' => '12:30'],
        ['name' => 'Lanche da tarde', 'time_of_day' => '16:00'],
        ['name' => 'Jantar', 'time_of_day' => '20:00'],
        ['name' => 'Ceia', 'time_of_day' => '22:00'],
    ];

    /**
     * Cria um novo plano alimentar como RASCUNHO (draft) - NI2-76
     */
    public function createPlan(
        ?int $patientId,
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
        // NI2-514: o plano pode nascer sem paciente (modelo de primeira prescrição) e receber o paciente depois.
        $patientId = $patientId !== null && $patientId > 0 ? $patientId : null;
        if ($patientId !== null) {
            TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        }
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
                            $amount = self::normalizeAmount($item['amount']);
                            $unit = $item['measure_unit'] ?? 'g';
                            $itemNotes = $item['notes'] ?? null;

                            // Buscar alimento para calcular macros
                            $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :food_clinic)");
                            $fStmt->execute(['id' => $foodId, 'food_clinic' => TenantContext::getClinicId()]);
                            $food = $fStmt->fetch();
                            if (!$food) throw new InvalidArgumentException('Alimento não disponível neste catálogo.');

                            $calc = $this->portionNutrition($food, $amount, $unit, $foodId, !empty($item['confirm_estimated']));

                            $iStmt = $this->db->prepare("
                                INSERT INTO meal_plan_items (
                                    meal_id, food_id, amount, measure_unit, notes, ordering,
                                    energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g,
                                    weight_grams, weight_is_estimated
                                ) VALUES (
                                    :meal_id, :food_id, :amount, :measure_unit, :notes, :ordering,
                                    :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g,
                                    :weight_grams, :weight_is_estimated
                                )
                            ");
                            $iStmt->execute([
                                'meal_id' => $mealId,
                                'food_id' => $foodId,
                                'amount' => $amount,
                                'measure_unit' => $unit,
                                'notes' => $itemNotes,
                                'ordering' => $itemOrder++,
                            ] + self::nutritionColumns($calc));
                            $itemId = (int)$this->db->lastInsertId();

                            if (!empty($item['substitutions'])) {
                                foreach ($item['substitutions'] as $sub) {
                                    $subFoodId = (int)$sub['food_id'];
                                    $subAmount = self::normalizeAmount($sub['amount']);
                                    $subUnit = $sub['measure_unit'] ?? 'g';
                                    $subNotes = $sub['notes'] ?? null;

                                    $sfStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :food_clinic)");
                                    $sfStmt->execute(['id' => $subFoodId, 'food_clinic' => TenantContext::getClinicId()]);
                                    $subFood = $sfStmt->fetch();
                                    if (!$subFood) throw new InvalidArgumentException('Substituição não disponível neste catálogo.');

                                    $sCalc = $this->portionNutrition($subFood, $subAmount, $subUnit, $subFoodId, !empty($sub['confirm_estimated']));

                                    $sStmt = $this->db->prepare("
                                        INSERT INTO meal_plan_substitutions (
                                            item_id, food_id, amount, measure_unit, notes,
                                            energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g,
                                            weight_grams, weight_is_estimated
                                        ) VALUES (
                                            :item_id, :food_id, :amount, :measure_unit, :notes,
                                            :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g,
                                            :weight_grams, :weight_is_estimated
                                        )
                                    ");
                                    $sStmt->execute([
                                        'item_id' => $itemId,
                                        'food_id' => $subFoodId,
                                        'amount' => $subAmount,
                                        'measure_unit' => $subUnit,
                                        'notes' => $subNotes,
                                    ] + self::nutritionColumns($sCalc));
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
                'id' => $planId,
                'title' => $cleanTitle,
                'status' => 'draft',
                'parent_plan_id' => $parentPlanId,
                'daily_energy_kcal' => $totals['energy_kcal'],
                'protein_g' => $totals['protein_g'],
                'carbohydrate_g' => $totals['carbohydrate_g'],
                'lipid_g' => $totals['lipid_g'],
                'fiber_g' => $totals['fiber_g'],
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

        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException("O nome da refeição é obrigatório.");
        }
        $timeOfDay = self::normalizeTimeOfDay($timeOfDay);

        return $this->inDraftTransaction($planId, function () use ($planId, $cleanName, $timeOfDay, $notes): int {
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
        });
    }

    /**
     * Atualiza dados de uma refeição existente (NI2-76 / NI2-519)
     */
    public function updateMeal(int $mealId, string $name, ?string $timeOfDay = null, ?string $notes = null, ?int $expectedPlanId = null): bool
    {
        $meal = $this->findMealWithTenantCheck($mealId);
        $planId = (int)$meal['meal_plan_id'];
        if ($expectedPlanId !== null && $planId !== $expectedPlanId) {
            throw new InvalidArgumentException("Refeição não pertence a este plano.");
        }

        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException("O nome da refeição é obrigatório.");
        }
        $timeOfDay = self::normalizeTimeOfDay($timeOfDay);
        $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        return $this->inDraftTransaction($planId, function () use ($mealId, $planId, $cleanName, $timeOfDay, $cleanNotes): bool {
            $stmt = $this->db->prepare("
                UPDATE meal_plan_meals
                SET name = :name, time_of_day = :time_of_day, notes = :notes
                WHERE id = :id AND meal_plan_id = :plan_id
            ");
            return $stmt->execute([
                'name' => $cleanName,
                'time_of_day' => $timeOfDay,
                'notes' => $cleanNotes,
                'id' => $mealId,
                'plan_id' => $planId,
            ]);
        });
    }

    /**
     * Remove uma refeição do plano e recalcula totais em cascata (NI2-76)
     */
    public function deleteMeal(int $mealId): bool
    {
        $meal = $this->findMealWithTenantCheck($mealId);
        $planId = (int)$meal['meal_plan_id'];

        return $this->inDraftTransaction($planId, function () use ($mealId, $planId): bool {
            $stmt = $this->db->prepare("DELETE FROM meal_plan_meals WHERE id = :id AND meal_plan_id = :plan_id");
            $res = $stmt->execute(['id' => $mealId, 'plan_id' => $planId]);
            $this->calculateAndPersistPlanTotals($planId);
            return $res;
        });
    }

    /**
     * Reordena as refeições de um plano (NI2-76)
     */
    public function reorderMeals(int $planId, array $mealIdsInOrder): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        return $this->inDraftTransaction($planId, function () use ($planId, $mealIdsInOrder): bool {
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
        });
    }

    /**
     * Adiciona um alimento à refeição, calculando macros determinísticos (NI2-77) e conferindo alérgenos no ato (NI2-525)
     */
    public function addItem(
        int $mealId,
        int $foodId,
        float $amount,
        string $measureUnit = 'g',
        ?string $notes = null,
        bool $confirmEstimated = false,
        bool $enforceAllergenGate = false,
        bool $confirmAllergenConflict = false,
        ?string $allergenAckReason = null
    ): int {
        $amount = self::normalizeAmount($amount);
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade prescrita deve ser estritamente maior que zero.");
        }

        $meal = $this->findMealWithTenantCheck($mealId);
        $planId = (int)$meal['meal_plan_id'];
        $patientId = !empty($meal['plan_patient_id']) ? (int)$meal['plan_patient_id'] : 0;

        return $this->inDraftTransaction($planId, function () use ($mealId, $planId, $patientId, $foodId, $amount, $measureUnit, $notes, $confirmEstimated, $enforceAllergenGate, $confirmAllergenConflict, $allergenAckReason): int {
            $food = $this->findCatalogFood($foodId);
            if (!$food) {
                throw new InvalidArgumentException("Alimento com ID {$foodId} não encontrado no catálogo.");
            }

            $allergenAlert = $patientId > 0 ? $this->allergenValidator->inspectFoodForPatient($patientId, $foodId) : null;
            $cleanAckReason = $allergenAckReason !== null && trim($allergenAckReason) !== '' ? trim($allergenAckReason) : null;
            if ($allergenAlert !== null && ($allergenAlert['status'] ?? '') === 'conflict' && ($enforceAllergenGate || $confirmAllergenConflict || $cleanAckReason !== null)) {
                if (!$confirmAllergenConflict || $cleanAckReason === null || mb_strlen($cleanAckReason) < 10) {
                    $matched = implode(', ', array_merge($allergenAlert['matched_allergens'] ?? [], $allergenAlert['restriction_matches'] ?? []));
                    throw new InvalidArgumentException("Conflito alergênico / restrição detectado ({$matched}). Para adicionar este alimento, marque a confirmação clínica e informe justificativa (mínimo de 10 caracteres).");
                }
            }

            // NI2-609: "unidade"/"fatia"/"porção" só com medida cadastrada para o alimento
            $this->calculator->converter()->assertCountUnitHasMeasure($measureUnit, $foodId, $food);
            // Macronutrientes proporcionais (100g ou medida caseira) e peso em gramas persistido
            $nutrition = $this->portionNutrition($food, $amount, $measureUnit, $foodId, $confirmEstimated);

            $oStmt = $this->db->prepare("SELECT COALESCE(MAX(ordering), 0) + 1 FROM meal_plan_items WHERE meal_id = :meal_id");
            $oStmt->execute(['meal_id' => $mealId]);
            $nextOrder = (int)$oStmt->fetchColumn();

            $hasAllergenCols = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'meal_plan_items', 'allergen_alert_json');
            if ($hasAllergenCols) {
                $stmt = $this->db->prepare("
                    INSERT INTO meal_plan_items (
                        meal_id, food_id, amount, measure_unit, notes, ordering,
                        energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g,
                        weight_grams, weight_is_estimated, allergen_alert_json, allergen_ack_reason
                    ) VALUES (
                        :meal_id, :food_id, :amount, :measure_unit, :notes, :ordering,
                        :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g,
                        :weight_grams, :weight_is_estimated, :allergen_alert_json, :allergen_ack_reason
                    )
                ");
                $stmt->execute([
                    'meal_id' => $mealId,
                    'food_id' => $foodId,
                    'amount' => $amount,
                    'measure_unit' => trim($measureUnit),
                    'notes' => $notes !== null ? trim($notes) : null,
                    'ordering' => $nextOrder,
                    'allergen_alert_json' => $allergenAlert !== null ? json_encode($allergenAlert, JSON_UNESCAPED_UNICODE) : null,
                    'allergen_ack_reason' => $cleanAckReason,
                ] + self::nutritionColumns($nutrition));
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO meal_plan_items (
                        meal_id, food_id, amount, measure_unit, notes, ordering,
                        energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g,
                        weight_grams, weight_is_estimated
                    ) VALUES (
                        :meal_id, :food_id, :amount, :measure_unit, :notes, :ordering,
                        :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g,
                        :weight_grams, :weight_is_estimated
                    )
                ");
                $stmt->execute([
                    'meal_id' => $mealId,
                    'food_id' => $foodId,
                    'amount' => $amount,
                    'measure_unit' => trim($measureUnit),
                    'notes' => $notes !== null ? trim($notes) : null,
                    'ordering' => $nextOrder,
                ] + self::nutritionColumns($nutrition));
            }
            $itemId = (int)$this->db->lastInsertId();

            if ($allergenAlert !== null && !empty($allergenAlert['requires_attention'])) {
                (new AuditLogger($this->db))->log(
                    $cleanAckReason !== null ? 'MEAL_PLAN_ITEM_ALLERGEN_OVERRIDE' : 'MEAL_PLAN_ITEM_ALLERGEN_ALERT',
                    'meal_plan_items',
                    (string)$itemId,
                    [
                        'plan_id' => $planId,
                        'patient_id' => $patientId,
                        'food_id' => $foodId,
                        'food_name' => $food['name'] ?? null,
                        'alert_status' => $allergenAlert['status'] ?? null,
                        'matched_allergens' => $allergenAlert['matched_allergens'] ?? [],
                        'restriction_matches' => $allergenAlert['restriction_matches'] ?? [],
                        'ack_reason' => $cleanAckReason,
                    ]
                );
            }

            // Recalcular totais consolidados do plano em cascata
            $this->calculateAndPersistPlanTotals($planId);

            return $itemId;
        });
    }

    /**
     * Atualiza quantidade ou notas de um item de refeição, recalculando totais (NI2-77)
     */
    public function updateItem(
        int $itemId,
        float $amount,
        string $measureUnit = 'g',
        ?string $notes = null,
        ?string $expectedRevision = null,
        ?int $expectedPlanId = null,
        bool $confirmEstimated = false
    ): bool {
        $amount = self::normalizeAmount($amount);
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade prescrita deve ser maior que zero.");
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            $item = $this->findItemWithTenantCheck($itemId);
            $meal = $this->findMealWithTenantCheck((int)$item['meal_id']);
            $planId = (int)$meal['meal_plan_id'];
            if ($expectedPlanId !== null && $planId !== $expectedPlanId) {
                throw new InvalidArgumentException('Item não encontrado neste plano.');
            }
            $this->assertPlanIsEditable($planId);
            if ($expectedRevision !== null && !hash_equals(self::itemRevision($item), $expectedRevision)) {
                throw new \DomainException('Este item foi alterado em outra aba. Confira os dados atuais antes de salvar novamente.');
            }

            $food = $this->findCatalogFood((int)$item['food_id']);
            if (!$food) {
                throw new InvalidArgumentException("Alimento associado não encontrado.");
            }

            if (!\ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::sameUnit((string)$item['measure_unit'], $measureUnit)) { // NI2-609: item antigo mantém a unidade
                $this->calculator->converter()->assertCountUnitHasMeasure($measureUnit, (int)$item['food_id'], $food);
            }
            $nutrition = $this->portionNutrition($food, $amount, $measureUnit, (int)$item['food_id'], $confirmEstimated);

            $stmt = $this->db->prepare("
                UPDATE meal_plan_items
                SET amount = :amount, measure_unit = :measure_unit, notes = :notes,
                    energy_kcal = :energy_kcal, protein_g = :protein_g, carbohydrate_g = :carbohydrate_g,
                    lipid_g = :lipid_g, fiber_g = :fiber_g,
                    weight_grams = :weight_grams, weight_is_estimated = :weight_is_estimated
                WHERE id = :id AND amount = :old_amount AND measure_unit = :old_unit
                    AND (notes = :old_notes OR (notes IS NULL AND :old_notes IS NULL))
                    AND EXISTS (SELECT 1 FROM meal_plan_meals m JOIN meal_plans p ON p.id = m.meal_plan_id
                        WHERE m.id = meal_plan_items.meal_id AND p.id = :plan_id AND p.status = 'draft' AND p.clinic_id = :clinic_id)
            ");
            $stmt->execute([
                'amount' => $amount,
                'measure_unit' => trim($measureUnit),
                'notes' => $notes !== null ? trim($notes) : null,
                'id' => $itemId,
                'old_amount' => $item['amount'], 'old_unit' => $item['measure_unit'], 'old_notes' => $item['notes'],
                'plan_id' => $planId, 'clinic_id' => TenantContext::getClinicId(),
            ] + self::nutritionColumns($nutrition));

            if ($stmt->rowCount() !== 1) throw new \DomainException('O plano ou item mudou. Recarregue antes de salvar.');
            $this->calculateAndPersistPlanTotals($planId);
            if ($ownsTransaction) $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public static function itemRevision(array $item): string
    {
        return hash('sha256', json_encode([(int)$item['id'], (float)$item['amount'],
            $item['measure_unit'], $item['notes']], JSON_THROW_ON_ERROR));
    }

    /**
     * Remove um item da refeição e recalcula o plano em cascata (NI2-77)
     */
    public function deleteItem(int $itemId): bool
    {
        $item = $this->findItemWithTenantCheck($itemId);
        $meal = $this->findMealWithTenantCheck((int)$item['meal_id']);
        $planId = (int)$meal['meal_plan_id'];

        return $this->inDraftTransaction($planId, function () use ($itemId, $planId): bool {
            $stmt = $this->db->prepare("DELETE FROM meal_plan_items WHERE id = :id
                AND meal_id IN (SELECT id FROM meal_plan_meals WHERE meal_plan_id = :plan_id)");
            $res = $stmt->execute(['id' => $itemId, 'plan_id' => $planId]);
            $this->calculateAndPersistPlanTotals($planId);
            return $res;
        });
    }

    /**
     * Configura uma alternativa de substituição vinculada a um item (NI2-78) com conferência de alérgenos no ato (NI2-525)
     */
    public function addSubstitution(
        int $itemId,
        int $foodId,
        float $amount,
        string $measureUnit = 'g',
        ?string $notes = null,
        bool $confirmEstimated = false,
        bool $enforceAllergenGate = false,
        bool $confirmAllergenConflict = false,
        ?string $allergenAckReason = null
    ): int {
        $amount = self::normalizeAmount($amount);
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade da substituição deve ser estritamente maior que zero.");
        }

        $item = $this->findItemWithTenantCheck($itemId);
        $meal = $this->findMealWithTenantCheck((int)$item['meal_id']);
        $planId = (int)$meal['meal_plan_id'];
        $patientId = !empty($meal['plan_patient_id']) ? (int)$meal['plan_patient_id'] : 0;

        return $this->inDraftTransaction($planId, function () use ($itemId, $planId, $patientId, $foodId, $amount, $measureUnit, $notes, $confirmEstimated, $enforceAllergenGate, $confirmAllergenConflict, $allergenAckReason): int {
            $food = $this->findCatalogFood($foodId);
            if (!$food) {
                throw new InvalidArgumentException("Alimento de substituição com ID {$foodId} não encontrado.");
            }

            $allergenAlert = $patientId > 0 ? $this->allergenValidator->inspectFoodForPatient($patientId, $foodId) : null;
            $cleanAckReason = $allergenAckReason !== null && trim($allergenAckReason) !== '' ? trim($allergenAckReason) : null;
            if ($allergenAlert !== null && ($allergenAlert['status'] ?? '') === 'conflict' && ($enforceAllergenGate || $confirmAllergenConflict || $cleanAckReason !== null)) {
                if (!$confirmAllergenConflict || $cleanAckReason === null || mb_strlen($cleanAckReason) < 10) {
                    $matched = implode(', ', array_merge($allergenAlert['matched_allergens'] ?? [], $allergenAlert['restriction_matches'] ?? []));
                    throw new InvalidArgumentException("Conflito alergênico / restrição detectado na substituição ({$matched}). Para adicionar, marque a confirmação clínica e informe justificativa (mínimo de 10 caracteres).");
                }
            }

            $this->calculator->converter()->assertCountUnitHasMeasure($measureUnit, $foodId, $food); // NI2-609
            // Macros da substituição para consulta e comparação de equivalência
            $nutrition = $this->portionNutrition($food, $amount, $measureUnit, $foodId, $confirmEstimated);

            $hasAllergenCols = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'meal_plan_substitutions', 'allergen_alert_json');
            if ($hasAllergenCols) {
                $stmt = $this->db->prepare("
                    INSERT INTO meal_plan_substitutions (
                        item_id, food_id, amount, measure_unit, notes,
                        energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g,
                        weight_grams, weight_is_estimated, allergen_alert_json, allergen_ack_reason
                    ) VALUES (
                        :item_id, :food_id, :amount, :measure_unit, :notes,
                        :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g,
                        :weight_grams, :weight_is_estimated, :allergen_alert_json, :allergen_ack_reason
                    )
                ");
                $stmt->execute([
                    'item_id' => $itemId,
                    'food_id' => $foodId,
                    'amount' => $amount,
                    'measure_unit' => trim($measureUnit),
                    'notes' => $notes !== null ? trim($notes) : null,
                    'allergen_alert_json' => $allergenAlert !== null ? json_encode($allergenAlert, JSON_UNESCAPED_UNICODE) : null,
                    'allergen_ack_reason' => $cleanAckReason,
                ] + self::nutritionColumns($nutrition));
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO meal_plan_substitutions (
                        item_id, food_id, amount, measure_unit, notes,
                        energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g,
                        weight_grams, weight_is_estimated
                    ) VALUES (
                        :item_id, :food_id, :amount, :measure_unit, :notes,
                        :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g,
                        :weight_grams, :weight_is_estimated
                    )
                ");
                $stmt->execute([
                    'item_id' => $itemId,
                    'food_id' => $foodId,
                    'amount' => $amount,
                    'measure_unit' => trim($measureUnit),
                    'notes' => $notes !== null ? trim($notes) : null,
                ] + self::nutritionColumns($nutrition));
            }
            $subId = (int)$this->db->lastInsertId();

            if ($allergenAlert !== null && !empty($allergenAlert['requires_attention'])) {
                (new AuditLogger($this->db))->log(
                    $cleanAckReason !== null ? 'MEAL_PLAN_SUB_ALLERGEN_OVERRIDE' : 'MEAL_PLAN_SUB_ALLERGEN_ALERT',
                    'meal_plan_substitutions',
                    (string)$subId,
                    [
                        'plan_id' => $planId,
                        'patient_id' => $patientId,
                        'item_id' => $itemId,
                        'food_id' => $foodId,
                        'food_name' => $food['name'] ?? null,
                        'alert_status' => $allergenAlert['status'] ?? null,
                        'matched_allergens' => $allergenAlert['matched_allergens'] ?? [],
                        'restriction_matches' => $allergenAlert['restriction_matches'] ?? [],
                        'ack_reason' => $cleanAckReason,
                    ]
                );
            }

            return $subId;
        });
    }

    /**
     * Atualiza quantidade, unidade ou orientação de uma substituição vinculada a um item (NI2-521)
     */
    public function updateSubstitution(
        int $subId,
        float $amount,
        string $measureUnit = 'g',
        ?string $notes = null,
        ?int $expectedPlanId = null,
        bool $confirmEstimated = false
    ): bool {
        $amount = self::normalizeAmount($amount);
        if ($amount <= 0) {
            throw new InvalidArgumentException("A quantidade da substituição deve ser estritamente maior que zero.");
        }

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
        $planId = (int)$sub['meal_plan_id'];
        if ($expectedPlanId !== null && $planId !== $expectedPlanId) {
            throw new InvalidArgumentException("Substituição não encontrada neste plano.");
        }
        if ($sub['status'] !== 'draft') {
            throw new RuntimeException("Planos alimentares publicados ou arquivados são imutáveis.");
        }

        return $this->inDraftTransaction($planId, function () use ($subId, $sub, $amount, $measureUnit, $notes, $confirmEstimated): bool {
            $foodId = (int)$sub['food_id'];
            $food = $this->findCatalogFood($foodId);
            if (!$food) {
                throw new InvalidArgumentException("Alimento de substituição com ID {$foodId} não encontrado.");
            }
            if (!\ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::sameUnit((string)$sub['measure_unit'], $measureUnit)) { // NI2-609
                $this->calculator->converter()->assertCountUnitHasMeasure($measureUnit, $foodId, $food);
            }
            $nutrition = $this->portionNutrition($food, $amount, $measureUnit, $foodId, $confirmEstimated);
            $cleanNotes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $stmt = $this->db->prepare("
                UPDATE meal_plan_substitutions
                SET amount = :amount, measure_unit = :measure_unit, notes = :notes,
                    energy_kcal = :energy_kcal, protein_g = :protein_g, carbohydrate_g = :carbohydrate_g,
                    lipid_g = :lipid_g, fiber_g = :fiber_g,
                    weight_grams = :weight_grams, weight_is_estimated = :weight_is_estimated
                WHERE id = :id
            ");
            return $stmt->execute([
                'amount' => $amount,
                'measure_unit' => trim($measureUnit),
                'notes' => $cleanNotes,
                'id' => $subId,
            ] + self::nutritionColumns($nutrition));
        });
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

        return $this->inDraftTransaction((int)$sub['meal_plan_id'], function () use ($subId): bool {
            $del = $this->db->prepare("DELETE FROM meal_plan_substitutions WHERE id = :id");
            return $del->execute(['id' => $subId]);
        });
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
            LEFT JOIN patients p ON p.id = mp.patient_id AND p.clinic_id = mp.clinic_id
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
                SELECT i.*, f.name AS food_name, f.category AS food_category, f.allergens, f.nutrient_flags AS food_nutrient_flags
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
                $item['allergen_alert'] = !empty($item['allergen_alert_json']) ? json_decode((string)$item['allergen_alert_json'], true) : null;

                $sStmt = $this->db->prepare("
                    SELECT s.*, f.name AS food_name, f.category AS food_category, f.allergens
                    FROM meal_plan_substitutions s
                    JOIN foods f ON f.id = s.food_id
                    WHERE s.item_id = :item_id
                    ORDER BY s.id ASC
                ");
                $sStmt->execute(['item_id' => $item['id']]);
                $subs = $sStmt->fetchAll();
                foreach ($subs as &$subRow) {
                    $subRow['allergen_alert'] = !empty($subRow['allergen_alert_json']) ? json_decode((string)$subRow['allergen_alert_json'], true) : null;
                }
                unset($subRow);
                $item['substitutions'] = $subs;
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
        // NI2-514: meta de energia (TMB/GET/VET e macros) do plano; entra no snapshot da versão publicada.
        $plan['energy_target'] = (new EnergyTargetService($this->db))->forPlan($planId);
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

        $hasPatient = !empty($plan['patient_id']);
        $plan['clinical_safety_summary'] = $hasPatient
            ? (new \ClyoNutri\Domain\Anamnesis\AnamnesisService($this->db))->getClinicalSummaryForPrescription((int)$plan['patient_id'])
            : self::noPatientSafetySummary();

        // Validação clínica de alergênicos contra a anamnese do paciente (fail-closed)
        $foodIds = [];
        $estimated = [];
        foreach ($plan['meals'] as $m) {
            foreach ($m['items'] as $it) {
                $foodIds[] = (int)$it['food_id'];
                if (!empty($it['weight_is_estimated'])) {
                    $estimated[] = ['food_name' => $it['food_name'], 'amount' => (float)$it['amount'], 'measure_unit' => $it['measure_unit'], 'weight_grams' => $it['weight_grams'] !== null ? (float)$it['weight_grams'] : null];
                }
                foreach ($it['substitutions'] as $sub) {
                    $foodIds[] = (int)$sub['food_id'];
                }
            }
        }
        $foodIds = array_unique($foodIds);

        // Sem paciente não há anamnese para conferir: o resultado fica pendente e a publicação exige o paciente.
        $allergenSafety = $hasPatient
            ? $this->allergenValidator->validateFoodsForPatient((int)$plan['patient_id'], $foodIds)
            : [
                'has_conflict' => false, 'patient_allergens' => [], 'conflicts' => [], 'allergy_recorded' => false,
                'unmapped_allergy_text' => false, 'unverified_foods' => [], 'requires_confirmation' => false,
                'patient_pending' => true,
            ];

        $qualitative = ($plan['plan_method'] ?? null) === 'qualitativo';
        $hasGuidance = false;
        if ($qualitative) {
            foreach ($plan['meals'] as $m) {
                if (trim((string)($m['notes'] ?? '')) !== '') {
                    $hasGuidance = true;
                    break;
                }
            }
        }
        $hasPublishableContent = (float)$plan['daily_energy_kcal'] > 0 || ($qualitative && $hasGuidance);

        $adequacy = $this->adequacyReview($plan);
        $targetComparison = $adequacy['target_comparison'];
        $adequacyAlerts = $adequacy['alerts'];
        $requiresAdequacyOverride = $adequacy['requires_override'];

        return [
            'plan' => $plan,
            'is_ready_to_publish' => $hasPatient && count($plan['meals']) > 0 && $hasPublishableContent,
            'allergen_safety' => $allergenSafety,
            'estimated_weights' => $estimated,
            'target_comparison' => $targetComparison,
            'adequacy' => [
                'alerts' => $adequacyAlerts,
                'requires_override' => $requiresAdequacyOverride,
                'target_comparison' => $targetComparison,
            ],
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
     * NI2-587 (UX-004): revisão de adequação antes de publicar. Exige motivo quando:
     * - energia ou um macronutriente (proteína, carboidrato, lipídio) fica fora de 80–120% da meta salva;
     * - o plano tem menos de 3 refeições.
     * Sem meta energética salva, só a regra de refeições vale.
     * @return array{alerts: list<array{type:string,severity:string,message:string}>, requires_override: bool, target_comparison: ?array}
     */
    private function adequacyReview(array $plan): array
    {
        $comparison = (new EnergyTargetService($this->db))->compare($plan);
        $alerts = [];
        $mealsCount = count($plan['meals'] ?? []);
        if ($mealsCount < 3) {
            $alerts[] = [
                'type' => 'MEALS_COUNT',
                'severity' => 'HIGH',
                'message' => "O plano tem {$mealsCount} refeição(ões); o padrão é ao menos 3.",
            ];
        }
        $labels = ['energy' => ['energia', 'kcal'], 'protein' => ['proteína', 'g'], 'carbohydrate' => ['carboidrato', 'g'], 'lipid' => ['lipídio', 'g']];
        foreach ($labels as $key => [$label, $unit]) {
            $row = $comparison[$key] ?? null;
            if (!is_array($row) || (float)$row['target'] <= 0) {
                continue;
            }
            $ratio = round((float)$row['actual'] / (float)$row['target'] * 100, 1);
            if ($ratio >= 80.0 && $ratio <= 120.0) {
                continue;
            }
            $alerts[] = [
                'type' => $key === 'energy' ? 'ENERGY_ADEQUACY' : 'MACRO_ADEQUACY',
                'severity' => 'CRITICAL',
                'nutrient' => $key,
                'ratio_pct' => $ratio,
                'message' => sprintf(
                    'A %s do plano está %s da meta: %s%% (%s %s de %s %s).',
                    $label,
                    $ratio < 80.0 ? 'abaixo' : 'acima',
                    number_format($ratio, 1, ',', '.'),
                    number_format((float)$row['actual'], 1, ',', '.'),
                    $unit,
                    number_format((float)$row['target'], 1, ',', '.'),
                    $unit
                ),
            ];
        }
        return ['alerts' => $alerts, 'requires_override' => $alerts !== [], 'target_comparison' => $comparison];
    }

    /**
     * Publica o plano alimentar com snapshot imutável, versionamento e auditoria (NI2-79)
     *
     * Só rascunhos são publicados. A troca draft -> published é a primeira escrita da transação
     * (UPDATE condicional), então duas publicações simultâneas ou uma edição concorrente não passam.
     * Conflito de alérgeno, alimento sem informação de alérgeno ou alergia não reconhecida exigem
     * $allowAllergenOverride = true E uma justificativa ($overrideReason, mín. 10 caracteres),
     * gravadas na versão e na trilha de auditoria com o autor.
     */
    public function publishPlan(
        int $planId,
        bool $allowAllergenOverride = false,
        bool $returnDetails = false,
        ?string $overrideReason = null,
        bool $allowAdequacyOverride = false,
        ?string $adequacyOverrideReason = null
    ): bool|array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        $head = $this->db->prepare("SELECT patient_id, status FROM meal_plans WHERE id = :id AND clinic_id = :c");
        $head->execute(['id' => $planId, 'c' => $clinicId]);
        $planHead = $head->fetch(PDO::FETCH_ASSOC);
        if (!$planHead) {
            throw new InvalidArgumentException("Plano alimentar não encontrado.");
        }
        self::assertPublishableStatus((string)$planHead['status']);
        if (empty($planHead['patient_id'])) {
            // NI2-514: a avaliação de alergia e o consentimento dependem do paciente; sem ele não há publicação.
            throw new InvalidArgumentException("Atribua um paciente ao plano antes de publicar. A conferência de alergias usa a anamnese dele.");
        }
        $patientId = (int)$planHead['patient_id'];
        $actorUserId = TenantContext::getUserId();
        $publishedAt = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            // Serializa publicações do mesmo paciente (MySQL); no SQLite a escrita abaixo já trava o banco.
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $this->db->prepare("SELECT id FROM patients WHERE id = :id AND clinic_id = :c FOR UPDATE");
                $lock->execute(['id' => $patientId, 'c' => $clinicId]);
            }

            // Primeira escrita: draft -> published. Se outra requisição já publicou ou mudou o status, para aqui.
            $claim = $this->db->prepare("
                UPDATE meal_plans
                SET status = 'published', published_at = :published_at, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND clinic_id = :clinic_id AND status = 'draft'
            ");
            $claim->execute(['published_at' => $publishedAt, 'id' => $planId, 'clinic_id' => $clinicId]);
            if ($claim->rowCount() !== 1) {
                $st = $this->db->prepare("SELECT status FROM meal_plans WHERE id = :id AND clinic_id = :c");
                $st->execute(['id' => $planId, 'c' => $clinicId]);
                self::assertPublishableStatus((string)$st->fetchColumn());
                throw new RuntimeException("O plano mudou durante a publicação. Recarregue e tente novamente.");
            }

            // Conferência feita sobre o estado já travado: é exatamente isso que vira o snapshot.
            $preview = $this->getPlanPreview($planId);
            $plan = $preview['plan'];

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
            // NI2-519 (v43): plano qualitativo pode sair sem alimentos quantificados, desde que alguma refeição tenha
            // orientação escrita (é o que o paciente vê no app e na impressão).
            $qualitative = ($plan['plan_method'] ?? null) === 'qualitativo';
            if ($qualitative && !$hasItems) {
                $hasGuidance = false;
                foreach ($plan['meals'] as $m) {
                    if (trim((string)($m['notes'] ?? '')) !== '') {
                        $hasGuidance = true;
                        break;
                    }
                }
                if (!$hasGuidance) {
                    throw new InvalidArgumentException("Plano qualitativo precisa de orientação escrita em pelo menos uma refeição antes de publicar.");
                }
            } elseif (!$hasItems) {
                throw new InvalidArgumentException("Não é possível publicar um plano alimentar sem nenhum alimento prescrito.");
            }

            $safety = $preview['allergen_safety'];
            $reason = $overrideReason !== null ? trim($overrideReason) : '';
            $overrideUsed = false;
            if (!empty($safety['requires_confirmation'])) {
                if (!$allowAllergenOverride) {
                    throw new InvalidArgumentException(self::allergenBlockMessage($safety));
                }
                if (mb_strlen($reason) < 10) {
                    throw new InvalidArgumentException("Para publicar com alerta de alérgeno, descreva a justificativa clínica (mínimo de 10 caracteres).");
                }
                $overrideUsed = true;
            }

            // NI2-587: adequação à meta (energia e macronutrientes) e estrutura mínima, sobre o estado já travado.
            $adequacy = $this->adequacyReview($plan);
            $requiresAdequacyOverride = $adequacy['requires_override'];
            $adequacyAlerts = array_map(static fn(array $a) => $a['message'], $adequacy['alerts']);

            $adqReason = $adequacyOverrideReason !== null ? trim($adequacyOverrideReason) : '';
            $adequacyOverrideUsed = false;
            if ($requiresAdequacyOverride) {
                if (!$allowAdequacyOverride) {
                    throw new InvalidArgumentException(
                        "Alerta de adequação do plano: " . implode(' ', $adequacyAlerts) . " Para publicar assim, confirme a autorização clínica e informe a justificativa (mínimo de 10 caracteres)."
                    );
                }
                if (mb_strlen($adqReason) < 10) {
                    throw new InvalidArgumentException(
                        "Para publicar com desvio da meta ou com menos de 3 refeições, descreva a justificativa clínica (mínimo de 10 caracteres)."
                    );
                }
                $adequacyOverrideUsed = true;
            }

            $professionalId = (int)$plan['professional_id'];

            // Próximo número sequencial de versão do paciente (único por clinic_id + patient_id)
            $vStmt = $this->db->prepare("
                SELECT COALESCE(MAX(version_number), 0) + 1
                FROM meal_plan_versions
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ");
            $vStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
            $nextVersionNumber = (int)$vStmt->fetchColumn();

            $supVer = $this->db->prepare("
                UPDATE meal_plan_versions
                SET status = 'superseded'
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND status = 'published'
            ");
            $supVer->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);

            $supPlan = $this->db->prepare("
                UPDATE meal_plans
                SET status = 'superseded'
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND status = 'published' AND id != :plan_id
            ");
            $supPlan->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId, 'plan_id' => $planId]);

            $plan['version'] = $nextVersionNumber;
            $plan['published_at'] = $publishedAt;
            $plan['adequacy_override'] = $adequacyOverrideUsed;
            $plan['adequacy_override_reason'] = $adequacyOverrideUsed ? $adqReason : null;
            $snapshotPayload = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $insVer = $this->db->prepare("
                INSERT INTO meal_plan_versions (
                    clinic_id, meal_plan_id, patient_id, professional_id,
                    version_number, title, daily_energy_kcal, payload_json,
                    status, published_at, created_at, published_by_user_id, allergen_override_reason
                ) VALUES (
                    :clinic_id, :meal_plan_id, :patient_id, :professional_id,
                    :version_number, :title, :daily_energy_kcal, :payload_json,
                    'published', :published_at, :created_at, :published_by, :override_reason
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
                'published_by' => $actorUserId,
                'override_reason' => $overrideUsed ? $reason : null,
            ]);

            $upPlan = $this->db->prepare("UPDATE meal_plans SET version = :version WHERE id = :id AND clinic_id = :clinic_id");
            $upPlan->execute(['version' => $nextVersionNumber, 'id' => $planId, 'clinic_id' => $clinicId]);

            // Trilha de auditoria clínica com autor e IP (AuditLogger)
            (new AuditLogger($this->db))->log('MEAL_PLAN_PUBLISHED', 'meal_plans', (string)$planId, [
                'patient_id' => $patientId,
                'professional_id' => $professionalId,
                'published_by_user_id' => $actorUserId,
                'version_number' => $nextVersionNumber,
                'daily_energy_kcal' => $plan['daily_energy_kcal'],
                'allergen_override' => $overrideUsed,
                'allergen_override_reason' => $overrideUsed ? $reason : null,
                'adequacy_override' => $adequacyOverrideUsed,
                'adequacy_override_reason' => $adequacyOverrideUsed ? $adqReason : null,
                'allergen_conflicts' => array_map(fn($c) => ['food_id' => $c['food_id'], 'group' => $c['allergen_group']], $safety['conflicts'] ?? []),
                'unverified_food_ids' => array_map(fn($f) => $f['food_id'], $safety['unverified_foods'] ?? []),
                'unmapped_allergy_text' => (bool)($safety['unmapped_allergy_text'] ?? false),
            ]);

            $this->db->commit();

            // NI2-95: aviso à paciente (fila do cron). Falha aqui nunca desfaz a publicação.
            try {
                (new \ClyoNutri\Domain\Notification\NotificationService($this->db))->publish($clinicId, 'meal_plan.published', 'patient', $patientId,
                    'meal_plan.published:' . $planId . ':v' . $nextVersionNumber, '/');
            } catch (\Throwable $notifyError) {
                error_log('NI2-95 aviso de plano publicado falhou: ' . get_class($notifyError));
            }

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

    private static function assertPublishableStatus(string $status): void
    {
        if ($status === 'published') {
            throw new RuntimeException("Este plano alimentar já está publicado.");
        }
        if ($status !== 'draft') {
            throw new RuntimeException("Somente rascunhos podem ser publicados. Para voltar a uma versão anterior, duplique-a e publique a cópia.");
        }
    }

    private static function allergenBlockMessage(array $safety): string
    {
        $parts = [];
        if (!empty($safety['conflicts'])) {
            $parts[] = 'conflito de alergênicos detectado [' . implode(', ', array_map(
                fn($c) => $c['food_name'] . " (" . $c['allergen_group'] . ")",
                $safety['conflicts']
            )) . ']';
        }
        if (!empty($safety['unverified_foods'])) {
            $parts[] = 'alimentos sem informação de alérgenos [' . implode(', ', array_map(fn($f) => $f['food_name'], $safety['unverified_foods'])) . ']';
        }
        if (!empty($safety['unmapped_allergy_text'])) {
            $parts[] = 'a alergia registrada na anamnese não foi reconhecida automaticamente';
        }
        return "Publicação bloqueada por segurança clínica: " . implode('; ', $parts)
            . ". Revise e marque a autorização expressa do nutricionista, com justificativa, se desejar prosseguir.";
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
                        'confirm_estimated' => true, // NI2-74: já aceito no plano de origem
                    ];
                }

                $clonedItems[] = [
                    'food_id' => (int)$it['food_id'],
                    'amount' => (float)$it['amount'],
                    'measure_unit' => $it['measure_unit'],
                    'notes' => $it['notes'],
                    'confirm_estimated' => true, // NI2-74: já aceito no plano de origem
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
        $copy = $this->createPlan(
            !empty($orig['patient_id']) ? (int)$orig['patient_id'] : null,
            (int)$orig['professional_id'],
            $cleanTitle,
            $orig['notes'],
            $clonedMeals,
            $planId
        );
        // NI2-514: a meta de energia acompanha a cópia (com a origem registrada); o profissional pode recalcular.
        (new EnergyTargetService($this->db))->copyTarget($planId, (int)$copy['plan_id']);
        // NI2-519 (v43): a nova versão mantém o modelo de origem, o método e a meta de referência.
        if (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'meal_plans', 'plan_method')) {
            $this->db->prepare('UPDATE meal_plans SET template_id = :t, plan_method = :m, reference_target_json = :r WHERE id = :id AND clinic_id = :c')
                ->execute(['t' => $orig['template_id'] ?? null, 'm' => $orig['plan_method'] ?? null, 'r' => $orig['reference_target_json'] ?? null,
                    'id' => (int)$copy['plan_id'], 'c' => $clinicId]);
        }
        return $copy;
    }

    /** NI2-519 (v43): método do plano (alimentos, equivalentes ou qualitativo). Só em rascunho. */
    public function setPlanMethod(int $planId, string $method): void
    {
        $clinicId = TenantContext::getClinicId();
        $plan = TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);
        if (!in_array($method, ['alimentos', 'equivalentes', 'qualitativo'], true)) {
            throw new InvalidArgumentException('Método de plano inválido.');
        }
        if (!\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'meal_plans', 'plan_method')) {
            return; // migração 63 pendente: o plano segue como "por alimentos"
        }
        if (($plan['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('Só rascunhos mudam de método. Crie uma nova versão para alterar um plano publicado.');
        }
        $this->db->prepare('UPDATE meal_plans SET plan_method = :m, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :c')
            ->execute(['m' => $method, 'id' => $planId, 'c' => $clinicId]);
        (new \ClyoNutri\Core\Audit\AuditLogger($this->db))->log('MEAL_PLAN_METHOD_SET', 'meal_plans', (string)$planId, ['method' => $method]);
    }

    /**
     * Atribui um paciente a um rascunho criado sem paciente (NI2-514). A conferência de alergias passa a usar a
     * anamnese dele já na prévia; a publicação continua exigindo a confirmação de alérgenos de sempre.
     */
    public function assignPatient(int $planId, int $patientId): void
    {
        $clinicId = TenantContext::getClinicId();
        $plan = TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        $pStatus = $this->db->prepare("SELECT status FROM patients WHERE id = :id AND clinic_id = :c");
        $pStatus->execute(['id' => $patientId, 'c' => $clinicId]);
        if ($pStatus->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Só é possível atribuir o plano a um paciente ativo.');
        }
        if (($plan['status'] ?? '') !== 'draft') {
            throw new RuntimeException('Só é possível atribuir paciente a um rascunho.');
        }
        if (!empty($plan['patient_id'])) {
            throw new InvalidArgumentException('Este plano já tem paciente vinculado. Para outro paciente, duplique o plano.');
        }
        $st = $this->db->prepare("UPDATE meal_plans SET patient_id = :p, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND clinic_id = :c AND status = 'draft' AND patient_id IS NULL");
        $st->execute(['p' => $patientId, 'id' => $planId, 'c' => $clinicId]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('O plano mudou durante a atribuição. Recarregue e tente novamente.');
        }
        (new EnergyTargetService($this->db))->attachPatient($planId, $patientId);
        (new AuditLogger($this->db))->log('MEAL_PLAN_PATIENT_ASSIGNED', 'meal_plans', (string)$planId, [
            'patient_id' => $patientId,
            'assigned_by_user_id' => TenantContext::getUserId(),
        ]);
    }

    /** Resumo clínico exibido quando o plano ainda não tem paciente. */
    private static function noPatientSafetySummary(): array
    {
        return [
            'has_data' => false,
            'allergy_state' => 'not_assessed',
            'allergy_label' => 'Não avaliado',
            'allergy_pending' => true,
            'is_draft' => false,
            'no_patient' => true,
            'patient_id' => null,
            'message' => 'Plano sem paciente: as alergias serão avaliadas quando você atribuir um paciente.',
            'critical_alerts' => [],
            'allergies' => null,
            'restrictions' => null,
            'objectives' => null,
            'symptoms' => null,
            'source' => null,
        ];
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
            WHERE id = :id AND status = 'draft'
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

    /** Peso máximo de um item ou substituição (gramas). Evita erro de digitação virar prescrição. */
    public const MAX_PORTION_GRAMS = 5000.0;

    /**
     * Calcula energia/macros e o peso em gramas de uma porção, com validação de faixa.
     */
    private function portionNutrition(array $food, float $amount, string $unit, int $foodId, bool $confirmEstimated = false): array
    {
        $calc = $this->calculator->calculateForPortion($food, $amount, $unit, $foodId, $confirmEstimated);
        if (!is_finite((float)$calc['weight_grams']) || (float)$calc['weight_grams'] > self::MAX_PORTION_GRAMS) {
            throw new InvalidArgumentException('Quantidade acima do limite de 5 kg por alimento. Confira o valor digitado.');
        }
        return $calc;
    }

    /**
     * NI2-74: quantidade com 2 casas antes de calcular e gravar (o MySQL guarda DECIMAL(…,2)); assim o valor gravado
     * e o usado no cálculo são o mesmo e um recálculo futuro dá o mesmo resultado.
     */
    public static function normalizeAmount(mixed $amount): float
    {
        $v = is_numeric($amount) ? (float)$amount : NAN;
        return is_finite($v) ? round($v, 2) : -1.0;
    }

    /** Colunas nutricionais + peso persistidos em meal_plan_items / meal_plan_substitutions. */
    private static function nutritionColumns(array $calc): array
    {
        return [
            'energy_kcal' => $calc['energy_kcal'],
            'protein_g' => $calc['protein_g'],
            'carbohydrate_g' => $calc['carbohydrate_g'],
            'lipid_g' => $calc['lipid_g'],
            'fiber_g' => $calc['fiber_g'],
            'weight_grams' => $calc['weight_grams'],
            'weight_is_estimated' => !empty($calc['weight_is_estimated']) ? 1 : 0,
        ];
    }

    private function findCatalogFood(int $foodId): array|false
    {
        $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :food_clinic)");
        $fStmt->execute(['id' => $foodId, 'food_clinic' => TenantContext::getClinicId()]);
        return $fStmt->fetch();
    }

    private static function normalizeTimeOfDay(?string $timeOfDay): ?string
    {
        if ($timeOfDay === null || trim($timeOfDay) === '') {
            return null;
        }
        $timeOfDay = trim($timeOfDay);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOfDay)) {
            throw new InvalidArgumentException("Horário inválido. Utilize o formato HH:MM (ex: 08:00, 12:30).");
        }
        return $timeOfDay;
    }

    /**
     * Executa uma alteração de rascunho numa transação cuja PRIMEIRA escrita confirma, no próprio SQL,
     * que o plano continua em 'draft' nesta clínica (trava a linha no MySQL; trava o banco no SQLite).
     * Uma publicação concorrente fica serializada: ou a alteração entra antes do snapshot, ou falha.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inDraftTransaction(int $planId, callable $work): mixed
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                // MySQL: trava a linha do plano até o commit. (rowCount de UPDATE no MySQL conta só linhas
                // efetivamente alteradas; updated_at no mesmo segundo daria 0, então não serve de guarda.)
                $guard = $this->db->prepare("SELECT status FROM meal_plans WHERE id = :id AND clinic_id = :clinic_id FOR UPDATE");
                $guard->execute(['id' => $planId, 'clinic_id' => TenantContext::getClinicId()]);
                $isDraft = $guard->fetchColumn() === 'draft';
            } else {
                // SQLite: a primeira escrita obtém o lock de escrita do banco; changes() conta as linhas casadas.
                $guard = $this->db->prepare("UPDATE meal_plans SET updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND clinic_id = :clinic_id AND status = 'draft'");
                $guard->execute(['id' => $planId, 'clinic_id' => TenantContext::getClinicId()]);
                $isDraft = $guard->rowCount() === 1;
            }
            if (!$isDraft) {
                $this->assertPlanIsEditable($planId); // lança a mensagem adequada (não encontrado / imutável)
                throw new RuntimeException("O plano mudou durante a edição. Recarregue e tente novamente.");
            }
            $result = $work();
            if ($ownTx) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
            SELECT m.*, mp.clinic_id, mp.status AS plan_status, mp.patient_id AS plan_patient_id
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
