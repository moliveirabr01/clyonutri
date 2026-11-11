<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ShoppingList;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator;
use InvalidArgumentException;
use PDO;

/**
 * Motor de Lista de Compras e Receitas do Plano Alimentar (NI2-116).
 *
 * Critérios de aceite:
 * 1. Lista reflete exclusivamente versões publicadas (fail-closed para rascunhos sem opt-in explícito);
 * 2. Agrupamento não duplica itens (soma correta de gramas/unidades);
 * 3. Troca de refeição / substituições atualizam quantidades dinamicamente;
 * 4. Alergênicos e restrições alimentares da anamnese aparecem destacados;
 * 5. Testes rigorosos de arredondamento e multiplicadores de período (7, 14, 30 dias);
 * 6. Integração com receitas vinculadas às refeições (recipe_prescriptions);
 * 7. Isolamento multi-tenant estrito com auditoria rastreável.
 */
class ShoppingListService
{
    private PDO $db;
    private AllergenSafetyValidator $allergenValidator;
    private AuditLogger $auditLogger;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->allergenValidator = new AllergenSafetyValidator($this->db);
        $this->auditLogger = new AuditLogger($this->db);
    }

    /**
     * Mapeia categoria do alimento para o corredor/gôndola do supermercado
     */
    public function mapAisle(string $category, string $foodName = ''): string
    {
        $cat = mb_strtolower(trim($category), 'UTF-8');
        $name = mb_strtolower(trim($foodName), 'UTF-8');
        $combined = ' ' . $cat . ' ' . $name . ' ';

        if (str_contains($combined, 'verdura') || str_contains($combined, 'fruta') ||
            str_contains($combined, 'legum') || str_contains($combined, 'hortali') ||
            str_contains($combined, 'folha') || str_contains($combined, 'salada')) {
            return 'Hortifrúti & Feira';
        }

        // Cereais & Grãos antes de Açougue para evitar que 'aveia' case com 'ave'
        if (str_contains($combined, 'cereal') || str_contains($combined, 'cereais') ||
            str_contains($combined, 'arroz') || str_contains($combined, 'aveia') ||
            str_contains($combined, 'grão') || str_contains($combined, 'grao') ||
            str_contains($combined, 'pão') || str_contains($combined, 'pao') ||
            str_contains($combined, 'torrada') || str_contains($combined, 'farinha') ||
            str_contains($combined, 'macarrão') || str_contains($combined, 'massa') ||
            str_contains($combined, 'feijão') || str_contains($combined, 'feijao') ||
            str_contains($combined, 'lentilha') || str_contains($combined, 'grão-de-bico')) {
            return 'Cereais & Grãos';
        }

        if (str_contains($combined, 'carne') || str_contains($combined, 'aves') ||
            str_contains($combined, ' ave ') || str_contains($combined, 'frango') ||
            str_contains($combined, 'ovo') || str_contains($combined, 'peixe') ||
            str_contains($combined, 'bovino') || str_contains($combined, 'suíno') ||
            str_contains($combined, 'pescado') || str_contains($combined, 'frutos do mar')) {
            return 'Açougue & Ovos';
        }

        if (str_contains($combined, 'leite') || str_contains($combined, 'iogurte') ||
            str_contains($combined, 'queijo') || str_contains($combined, 'requeij') ||
            str_contains($combined, 'manteiga') || str_contains($combined, 'ricota')) {
            return 'Laticínios & Refrigerados';
        }

        if (str_contains($combined, 'óleo') || str_contains($combined, 'oleo') ||
            str_contains($combined, 'azeite') || str_contains($combined, 'gordura') ||
            str_contains($combined, 'tempero') || str_contains($combined, 'vinagre') ||
            str_contains($combined, 'sal') || str_contains($combined, 'molho')) {
            return 'Óleos & Condimentos';
        }

        return 'Mercearia & Diversos';
    }

    /**
     * Gera lista de compras multiplicando as quantidades diárias pelo período selecionado.
     *
     * @param int $planId ID do plano alimentar
     * @param int $daysMultiplier Número de dias (ex: 7, 14, 30)
     * @param array $options Opções:
     *   - 'require_published' (bool, default true): exige que o plano esteja publicado
     *   - 'substitutions' (array): mapa [itemId => substitutionId] para trocas de alimentos
     *   - 'custom_items' (array): itens manuais adicionais [{name, amount, unit, aisle}]
     *   - 'checked_items' (array): IDs ou chaves de itens marcados como comprados
     * @return array Estrutura completa da lista de compras
     */
    public function generateForPlan(int $planId, int $daysMultiplier = 7, array $options = []): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        $days = max(1, min(90, $daysMultiplier));
        $requirePublished = $options['require_published'] ?? true;
        $appliedSubstitutions = $options['substitutions'] ?? [];
        $customItems = $options['custom_items'] ?? [];
        $checkedItems = $options['checked_items'] ?? [];

        // Validar plano alimentar
        $pStmt = $this->db->prepare("
            SELECT mp.id, mp.title, mp.patient_id, mp.professional_id, mp.status, mp.version,
                   p.name AS patient_name, u.name AS professional_name
            FROM meal_plans mp
            LEFT JOIN patients p ON p.id = mp.patient_id
            LEFT JOIN professionals pr ON pr.id = mp.professional_id
            LEFT JOIN users u ON u.id = pr.user_id
            WHERE mp.id = :id AND mp.clinic_id = :clinic_id
        ");
        $pStmt->execute(['id' => $planId, 'clinic_id' => $clinicId]);
        $plan = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            throw new InvalidArgumentException("Plano alimentar não encontrado.");
        }

        // AC1: Lista reflete somente versões publicadas (fail-closed)
        if ($requirePublished && $plan['status'] !== 'published') {
            throw new InvalidArgumentException(
                "A lista de compras reflete exclusivamente versões publicadas do plano alimentar. Situação atual: {$plan['status']}."
            );
        }

        $patientId = $plan['patient_id'] !== null ? (int)$plan['patient_id'] : null;
        if ($patientId !== null) {
            TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        }

        // 1. Buscar todos os itens prescritos nas refeições
        $stmt = $this->db->prepare("
            SELECT i.id AS item_id, i.meal_id, i.food_id, i.amount, i.measure_unit,
                   f.name AS food_name, f.category, f.allergens,
                   m.name AS meal_name, m.ordering AS meal_order
            FROM meal_plan_items i
            JOIN meal_plan_meals m ON m.id = i.meal_id
            JOIN foods f ON f.id = i.food_id
            WHERE m.meal_plan_id = :plan_id
            ORDER BY m.ordering ASC, i.ordering ASC
        ");
        $stmt->execute(['plan_id' => $planId]);
        $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2. Buscar substituições disponíveis no plano para esses itens
        $itemIds = array_column($rawItems, 'item_id');
        $availableSubstitutionsByItem = [];
        if (!empty($itemIds)) {
            $inClause = implode(',', array_map('intval', $itemIds));
            $subStmt = $this->db->prepare("
                SELECT s.id AS sub_id, s.item_id, s.food_id, s.amount, s.measure_unit,
                       f.name AS food_name, f.category, f.allergens
                FROM meal_plan_substitutions s
                JOIN foods f ON f.id = s.food_id
                WHERE s.item_id IN ({$inClause})
            ");
            $subStmt->execute();
            while ($subRow = $subStmt->fetch(PDO::FETCH_ASSOC)) {
                $availableSubstitutionsByItem[(int)$subRow['item_id']][(int)$subRow['sub_id']] = $subRow;
            }
        }

        // 3. Processar itens de refeições considerando trocas / substituições ativas (AC3)
        $effectiveMealItems = [];
        foreach ($rawItems as $it) {
            $itemId = (int)$it['item_id'];
            $selectedSubId = isset($appliedSubstitutions[$itemId]) ? (int)$appliedSubstitutions[$itemId] : null;

            if ($selectedSubId !== null && isset($availableSubstitutionsByItem[$itemId][$selectedSubId])) {
                // Item foi substituído: usar dados da substituição
                $sub = $availableSubstitutionsByItem[$itemId][$selectedSubId];
                $effectiveMealItems[] = [
                    'source_type' => 'meal_item_substituted',
                    'meal_id' => $it['meal_id'],
                    'meal_name' => $it['meal_name'],
                    'item_id' => $itemId,
                    'is_substituted' => true,
                    'original_food_name' => $it['food_name'],
                    'food_id' => (int)$sub['food_id'],
                    'name' => $sub['food_name'],
                    'category' => $sub['category'] ?? 'Geral',
                    'measure_unit' => $sub['measure_unit'],
                    'daily_amount' => (float)$sub['amount'],
                    'allergens' => $sub['allergens'],
                    'available_substitutions' => array_values($availableSubstitutionsByItem[$itemId] ?? []),
                ];
            } else {
                // Item original
                $effectiveMealItems[] = [
                    'source_type' => 'meal_item',
                    'meal_id' => $it['meal_id'],
                    'meal_name' => $it['meal_name'],
                    'item_id' => $itemId,
                    'is_substituted' => false,
                    'original_food_name' => null,
                    'food_id' => (int)$it['food_id'],
                    'name' => $it['food_name'],
                    'category' => $it['category'] ?? 'Geral',
                    'measure_unit' => $it['measure_unit'],
                    'daily_amount' => (float)$it['amount'],
                    'allergens' => $it['allergens'],
                    'available_substitutions' => array_values($availableSubstitutionsByItem[$itemId] ?? []),
                ];
            }
        }

        // 4. Buscar receitas vinculadas a este plano alimentar (recipe_prescriptions)
        $rpStmt = $this->db->prepare("
            SELECT rp.id AS prescription_id, rp.prescribed_servings, rp.meal_name,
                   r.id AS recipe_id, r.title AS recipe_title,
                   ri.food_id, ri.amount AS ingredient_amount, ri.measure_unit,
                   f.name AS food_name, f.category AS food_category, f.allergens
            FROM recipe_prescriptions rp
            JOIN recipes r ON r.id = rp.recipe_id
            JOIN recipe_ingredients ri ON ri.recipe_id = r.id
            JOIN foods f ON f.id = ri.food_id
            WHERE rp.meal_plan_id = :plan_id AND rp.clinic_id = :clinic_id
        ");
        $rpStmt->execute(['plan_id' => $planId, 'clinic_id' => $clinicId]);
        $recipeRows = $rpStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $linkedRecipes = [];
        $recipeIngredients = [];
        foreach ($recipeRows as $rRow) {
            $recipeId = (int)$rRow['recipe_id'];
            if (!isset($linkedRecipes[$recipeId])) {
                $linkedRecipes[$recipeId] = [
                    'recipe_id' => $recipeId,
                    'title' => $rRow['recipe_title'],
                    'prescribed_servings' => (float)$rRow['prescribed_servings'],
                    'meal_name' => $rRow['meal_name'] ?? 'Refeição vinculada',
                ];
            }

            // Quantidade diária do ingrediente da receita
            $dailyQty = (float)$rRow['ingredient_amount'] * (float)$rRow['prescribed_servings'];
            $recipeIngredients[] = [
                'source_type' => 'recipe_ingredient',
                'recipe_title' => $rRow['recipe_title'],
                'recipe_id' => $recipeId,
                'food_id' => (int)$rRow['food_id'],
                'name' => $rRow['food_name'],
                'category' => $rRow['food_category'] ?? 'Geral',
                'measure_unit' => $rRow['measure_unit'],
                'daily_amount' => $dailyQty,
                'allergens' => $rRow['allergens'],
            ];
        }

        // 5. Agregar itens sem duplicar (AC2)
        // Agrupa por food_id e unidade de medida normalizada
        $aggregated = [];
        $allFoodsToEvaluate = [];

        $processItemForAggregation = function (array $item) use (&$aggregated, &$allFoodsToEvaluate, $days): void {
            $foodId = (int)$item['food_id'];
            $unit = trim((string)$item['measure_unit']);
            $key = $foodId . '_' . mb_strtolower($unit, 'UTF-8');

            $allFoodsToEvaluate[$foodId] = $item['name'];

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'key' => $key,
                    'food_id' => $foodId,
                    'name' => $item['name'],
                    'category' => $item['category'] ?? 'Geral',
                    'aisle' => $this->mapAisle($item['category'] ?? '', $item['name']),
                    'measure_unit' => $unit,
                    'daily_amount' => 0.0,
                    'total_amount' => 0.0,
                    'recipes_source' => [],
                    'meals_source' => [],
                    'is_substituted' => false,
                    'substitutions_available' => [],
                ];
            }

            $aggregated[$key]['daily_amount'] += (float)$item['daily_amount'];
            $aggregated[$key]['total_amount'] += (float)$item['daily_amount'] * $days;

            if (!empty($item['recipe_title']) && !in_array($item['recipe_title'], $aggregated[$key]['recipes_source'], true)) {
                $aggregated[$key]['recipes_source'][] = $item['recipe_title'];
            }
            if (!empty($item['meal_name']) && !in_array($item['meal_name'], $aggregated[$key]['meals_source'], true)) {
                $aggregated[$key]['meals_source'][] = $item['meal_name'];
            }
            if (!empty($item['is_substituted'])) {
                $aggregated[$key]['is_substituted'] = true;
            }
            if (!empty($item['available_substitutions'])) {
                $aggregated[$key]['substitutions_available'] = $item['available_substitutions'];
            }
        };

        // Agregar itens diretos das refeições
        foreach ($effectiveMealItems as $it) {
            $processItemForAggregation($it);
        }

        // Agregar ingredientes das receitas vinculadas
        foreach ($recipeIngredients as $rIng) {
            $processItemForAggregation($rIng);
        }

        // 6. Consultar Alergias e Restrições do Paciente (AC4)
        $patientAllergiesRaw = '';
        $patientRestrictionsRaw = '';
        $conflictsByFoodId = [];
        $allergenSafety = ['has_conflict' => false, 'conflicts' => []];

        if ($patientId !== null) {
            // NI2-605: mesma fonte da trava (anamnese concluída + acréscimos de rascunho mais novo)
            $anamnesis = \ClyoNutri\Domain\Anamnesis\AnamnesisVersions::mergeForSafety(\ClyoNutri\Domain\Anamnesis\AnamnesisVersions::pick($this->db, $clinicId, $patientId, 'a.allergies, a.restrictions, a.version'))['row'] ?? [];

            $patientAllergiesRaw = trim((string)($anamnesis['allergies'] ?? ''));
            $patientRestrictionsRaw = trim((string)($anamnesis['restrictions'] ?? ''));

            // Validação de segurança com o validador fail-closed
            $allergenSafety = $this->allergenValidator->validateFoodsForPatient($patientId, array_keys($allFoodsToEvaluate));
            foreach ($allergenSafety['conflicts'] as $conf) {
                $conflictsByFoodId[(int)$conf['food_id']] = $conf;
            }
        }

        // 7. Agrupar por Gôndola / Corredor do Supermercado com formatação e arredondamento (AC5)
        $byAisle = [];
        $flatItems = [];

        foreach ($aggregated as $key => $item) {
            $foodId = (int)$item['food_id'];
            $aisle = $item['aisle'];
            $hasConflict = isset($conflictsByFoodId[$foodId]);
            $conflictDetails = $hasConflict ? $conflictsByFoodId[$foodId] : null;

            // Arredondamento limpo (testes de arredondamento)
            $totalAmountRounded = round($item['total_amount'], 1);
            $dailyAmountRounded = round($item['daily_amount'], 1);

            $formattedItem = [
                'key' => $key,
                'food_id' => $foodId,
                'name' => $item['name'],
                'category' => $item['category'],
                'aisle' => $aisle,
                'amount' => $totalAmountRounded,
                'daily' => $dailyAmountRounded,
                'unit' => $item['measure_unit'],
                'is_checked' => in_array($key, $checkedItems, true) || in_array((string)$foodId, $checkedItems, true),
                'recipes_source' => $item['recipes_source'],
                'meals_source' => $item['meals_source'],
                'is_substituted' => $item['is_substituted'],
                'substitutions_available' => $item['substitutions_available'],
                'has_allergen_conflict' => $hasConflict,
                'allergen_warning' => $hasConflict ? $conflictDetails['message'] : null,
                'allergen_group' => $hasConflict ? $conflictDetails['allergen_group'] : null,
            ];

            if (!isset($byAisle[$aisle])) {
                $byAisle[$aisle] = [];
            }
            $byAisle[$aisle][] = $formattedItem;
            $flatItems[] = $formattedItem;
        }

        // Incorporar itens personalizados manuais
        if (!empty($customItems) && is_array($customItems)) {
            foreach ($customItems as $cIdx => $cItem) {
                if (empty($cItem['name'])) continue;
                $cAisle = !empty($cItem['aisle']) ? trim($cItem['aisle']) : 'Mercearia & Diversos';
                $cKey = 'custom_' . $cIdx;
                $formattedCustom = [
                    'key' => $cKey,
                    'food_id' => null,
                    'name' => trim($cItem['name']),
                    'category' => 'Manual',
                    'aisle' => $cAisle,
                    'amount' => (float)($cItem['amount'] ?? 1),
                    'daily' => round(((float)($cItem['amount'] ?? 1)) / $days, 1),
                    'unit' => $cItem['unit'] ?? 'un',
                    'is_checked' => in_array($cKey, $checkedItems, true),
                    'recipes_source' => [],
                    'meals_source' => ['Item manual'],
                    'is_substituted' => false,
                    'substitutions_available' => [],
                    'has_allergen_conflict' => false,
                    'allergen_warning' => null,
                    'allergen_group' => null,
                ];
                $byAisle[$cAisle][] = $formattedCustom;
                $flatItems[] = $formattedCustom;
            }
        }

        ksort($byAisle);

        // Registrar auditoria
        $this->auditLogger->log(
            'shopping_list_generated',
            'meal_plans',
            (string)$planId,
            [
                'days' => $days,
                'total_items' => count($flatItems),
                'patient_id' => $patientId,
                'substitutions_count' => count($appliedSubstitutions),
            ]
        );

        return [
            'plan_id' => $planId,
            'plan_title' => $plan['title'],
            'patient_id' => $patientId,
            'patient_name' => $plan['patient_name'] ?: 'Paciente a definir',
            'professional_name' => $plan['professional_name'],
            'version' => (int)$plan['version'],
            'status' => $plan['status'],
            'days' => $days,
            'generated_at' => date('c'),
            'total_items_count' => count($flatItems),
            'linked_recipes' => array_values($linkedRecipes),
            'allergens_and_restrictions' => [
                'allergies_recorded' => $patientAllergiesRaw !== '' ? $patientAllergiesRaw : null,
                'restrictions_recorded' => $patientRestrictionsRaw !== '' ? $patientRestrictionsRaw : null,
                'has_conflicts' => $allergenSafety['has_conflict'],
                'conflicts_count' => count($allergenSafety['conflicts']),
                'conflicts' => $allergenSafety['conflicts'],
            ],
            'aisles' => $byAisle,
            'items' => $flatItems,
        ];
    }

    /**
     * Gera lista de compras a partir do plano publicado ativo do paciente.
     */
    public function generateForPatient(int $patientId, int $daysMultiplier = 7, array $options = []): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT id FROM meal_plans
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND status = 'published'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $planId = $stmt->fetchColumn();

        if (!$planId) {
            throw new InvalidArgumentException(
                "Nenhum plano alimentar publicado foi encontrado para este paciente. Entre em contato com seu nutricionista."
            );
        }

        $options['require_published'] = true;
        return $this->generateForPlan((int)$planId, $daysMultiplier, $options);
    }
}
