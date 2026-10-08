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
     * Cria um novo plano alimentar como RASCUNHO (draft).
     * Calcula calorias e macronutrientes com base na calculadora de 100g e medidas caseiras.
     */
    public function createPlan(int $patientId, int $professionalId, string $title, array $meals = []): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);

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
                        $foodId = (int)$item['food_id'];
                        $amount = (float)$item['amount'];
                        $measureUnit = $item['measure_unit'] ?? 'g';

                        $iStmt = $this->db->prepare("
                            INSERT INTO meal_plan_items (meal_id, food_id, amount, measure_unit, ordering)
                            VALUES (:meal_id, :food_id, :amount, :measure_unit, :ordering)
                        ");
                        $iStmt->execute([
                            'meal_id' => $mealId,
                            'food_id' => $foodId,
                            'amount' => $amount,
                            'measure_unit' => $measureUnit,
                            'ordering' => $itemOrder++,
                        ]);
                        $itemId = (int)$this->db->lastInsertId();

                        // Buscar alimento base e calcular via NutritionCalculator determinístico
                        $fStmt = $this->db->prepare("SELECT * FROM foods WHERE id = :id");
                        $fStmt->execute(['id' => $foodId]);
                        $food = $fStmt->fetch();
                        if ($food) {
                            $calc = $this->calculator->calculateForPortion($food, $amount, $measureUnit, $foodId);
                            $totalKcal += $calc['energy_kcal'];
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

    /**
     * Publica transacionalmente o plano alimentar com snapshot imutável em meal_plan_versions.
     * Valida previamente se há conflitos alergênicos com a anamnese do paciente.
     *
     * @param int $planId ID do plano
     * @param bool $allowAllergenOverride Se verdadeiro, permite publicação supervisionada mesmo com aviso de alergênico
     * @return bool
     * @throws InvalidArgumentException Em caso de conflito não autorizado com alergênicos
     */
    public function publishPlan(int $planId, bool $allowAllergenOverride = false): bool
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        // 1. Obter dados do plano
        $pStmt = $this->db->prepare("
            SELECT id, clinic_id, patient_id, professional_id, title, daily_energy_kcal, status 
            FROM meal_plans 
            WHERE id = :id AND clinic_id = :clinic_id
        ");
        $pStmt->execute(['id' => $planId, 'clinic_id' => $clinicId]);
        $plan = $pStmt->fetch();

        if (!$plan) {
            throw new InvalidArgumentException("Plano alimentar não encontrado.");
        }

        $patientId = (int)$plan['patient_id'];
        $professionalId = (int)$plan['professional_id'];

        // 2. Coletar todos os food_ids do plano para validação de segurança alergênica
        $fStmt = $this->db->prepare("
            SELECT DISTINCT i.food_id 
            FROM meal_plan_items i
            JOIN meal_plan_meals m ON m.id = i.meal_id
            WHERE m.meal_plan_id = :plan_id
        ");
        $fStmt->execute(['plan_id' => $planId]);
        $foodIds = $fStmt->fetchAll(PDO::FETCH_COLUMN);

        // Validar alergênicos contra a anamnese do paciente
        $allergenCheck = $this->allergenValidator->validateFoodsForPatient($patientId, $foodIds);
        if ($allergenCheck['has_conflict'] && !$allowAllergenOverride) {
            $conflictNames = array_map(fn($c) => $c['food_name'] . " (" . $c['allergen_group'] . ")", $allergenCheck['conflicts']);
            $msg = "Publicação bloqueada por segurança clínica: conflito de alergênicos detectado [" . implode(', ', $conflictNames) . "].";
            throw new InvalidArgumentException($msg);
        }

        // 3. Montar snapshot completo das refeições do plano
        $fullPayload = $this->getPlanCompleteStructure($planId);

        $this->db->beginTransaction();
        try {
            // Obter próximo version_number para o paciente
            $vStmt = $this->db->prepare("
                SELECT COALESCE(MAX(version_number), 0) + 1 
                FROM meal_plan_versions 
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ");
            $vStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
            $nextVersionNumber = (int)$vStmt->fetchColumn();

            // Desativar versão publicada anterior tornando-a 'superseded'
            $supStmt = $this->db->prepare("
                UPDATE meal_plan_versions 
                SET status = 'superseded' 
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND status = 'published'
            ");
            $supStmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);

            // Desativar plano anterior caso exista
            $supPlanStmt = $this->db->prepare("
                UPDATE meal_plans 
                SET status = 'superseded' 
                WHERE clinic_id = :clinic_id AND patient_id = :patient_id AND status = 'published' AND id != :plan_id
            ");
            $supPlanStmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'plan_id' => $planId,
            ]);

            // Gravar snapshot imutável em meal_plan_versions
            $publishedAt = date('Y-m-d H:i:s');
            $payloadJson = json_encode($fullPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

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
                'payload_json' => $payloadJson,
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
            ]);

            // Atualizar plano original
            $stmt = $this->db->prepare("
                UPDATE meal_plans
                SET status = 'published', published_at = :published_at, version = :version
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $stmt->execute([
                'published_at' => $publishedAt,
                'version' => $nextVersionNumber,
                'id' => $planId,
                'clinic_id' => $clinicId,
            ]);

            // Log de auditoria
            $aStmt = $this->db->prepare("
                INSERT INTO audit_logs (clinic_id, action, resource, resource_id, details)
                VALUES (:clinic_id, 'MEAL_PLAN_PUBLISHED', 'meal_plans', :res_id, :details)
            ");
            $aStmt->execute([
                'clinic_id' => $clinicId,
                'res_id' => (string)$planId,
                'details' => json_encode([
                    'patient_id' => $patientId,
                    'version' => $nextVersionNumber,
                    'daily_energy_kcal' => $plan['daily_energy_kcal'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Retorna a versão publicada VIGENTE do paciente.
     * Garante que o paciente acesse apenas prescrições ativas e publicadas.
     */
    public function getActivePublishedPlan(int $patientId): ?array
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
     * Clona um plano publicado para um novo rascunho (draft) permitindo ajustes
     * sem alterar ou corromper a prescrição publicada histórica.
     */
    public function createRevisionDraft(int $publishedPlanId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($publishedPlanId, $clinicId, $this->db);

        $pStmt = $this->db->prepare("SELECT * FROM meal_plans WHERE id = :id AND clinic_id = :clinic_id");
        $pStmt->execute(['id' => $publishedPlanId, 'clinic_id' => $clinicId]);
        $origPlan = $pStmt->fetch();

        if (!$origPlan) {
            throw new InvalidArgumentException("Plano alimentar não encontrado.");
        }

        $structure = $this->getPlanCompleteStructure($publishedPlanId);
        $newTitle = "[Rascunho de Ajuste] " . $origPlan['title'];

        return $this->createPlan((int)$origPlan['patient_id'], (int)$origPlan['professional_id'], $newTitle, $structure);
    }

    private function getPlanCompleteStructure(int $planId): array
    {
        $mStmt = $this->db->prepare("SELECT * FROM meal_plan_meals WHERE meal_plan_id = :plan_id ORDER BY ordering ASC");
        $mStmt->execute(['plan_id' => $planId]);
        $meals = $mStmt->fetchAll();

        $structure = [];
        foreach ($meals as $m) {
            $iStmt = $this->db->prepare("
                SELECT i.*, f.name as food_name, f.energy_kcal as base_kcal
                FROM meal_plan_items i
                JOIN foods f ON f.id = i.food_id
                WHERE i.meal_id = :meal_id
                ORDER BY i.ordering ASC
            ");
            $iStmt->execute(['meal_id' => $m['id']]);
            $items = $iStmt->fetchAll();

            $structure[] = [
                'name' => $m['name'],
                'time_of_day' => $m['time_of_day'],
                'items' => array_map(function ($it) {
                    return [
                        'food_id' => (int)$it['food_id'],
                        'amount' => (float)$it['amount'],
                        'measure_unit' => $it['measure_unit'],
                    ];
                }, $items),
            ];
        }

        return $structure;
    }
}
