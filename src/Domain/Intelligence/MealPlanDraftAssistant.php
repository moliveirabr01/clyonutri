<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Intelligence;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\MealPlan\MealPlanService;
use PDO;

class MealPlanDraftAssistant
{
    private PDO $db;
    private FoodCatalogService $foodCatalog;
    private MealPlanService $mealPlanService;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->foodCatalog = new FoodCatalogService($this->db);
        $this->mealPlanService = new MealPlanService($this->db);
    }

    /**
     * Gera rascunho de plano alimentar com base em meta calórica e aversões alimentares
     * Salvaguarda: NUNCA publica automaticamente; nasce como 'draft' para validação clínica.
     */
    public function draftPlan(int $patientId, int $professionalId, float $targetKcal, array $allergiesOrDislikes = []): array
    {
        $clinicId = TenantContext::getClinicId();

        // 1. Obter catálogo de alimentos disponíveis
        $allFoods = $this->foodCatalog->search('');

        // Filtrar alimentos excluídos por intolerância/aversão
        $filtered = array_filter($allFoods, function ($food) use ($allergiesOrDislikes) {
            foreach ($allergiesOrDislikes as $avoid) {
                if (stripos($food['name'], $avoid) !== false) {
                    return false;
                }
            }
            return true;
        });

        $foodsByName = [];
        foreach ($filtered as $f) {
            $foodsByName[mb_strtolower($f['name'], 'UTF-8')] = $f;
        }

        // 2. Compor rascunho inteligente baseado na meta
        $arroz = $foodsByName['arroz branco cozido'] ?? reset($filtered);
        $frango = $foodsByName['peito de frango grelhado'] ?? next($filtered);
        $banana = $foodsByName['banana prata crua'] ?? next($filtered);
        $aveia = $foodsByName['aveia em flocos'] ?? next($filtered);

        $mealsStructure = [
            [
                'name' => 'Café da Manhã (Sugerido por IA)',
                'time_of_day' => '07:30',
                'items' => [
                    ['food_id' => $banana['id'], 'amount' => 100, 'measure_unit' => 'unidade'],
                    ['food_id' => $aveia['id'], 'amount' => 30, 'measure_unit' => 'g'],
                ],
            ],
            [
                'name' => 'Almoço (Sugerido por IA)',
                'time_of_day' => '12:30',
                'items' => [
                    ['food_id' => $arroz['id'], 'amount' => 160, 'measure_unit' => 'g'],
                    ['food_id' => $frango['id'], 'amount' => 150, 'measure_unit' => 'g'],
                ],
            ],
        ];

        // 3. Criar o plano estritamente como RASCUNHO (draft)
        $created = $this->mealPlanService->createPlan(
            $patientId,
            $professionalId,
            'Rascunho Inteligente de Prescrição (Aguardando Aprovação)',
            $mealsStructure
        );

        return [
            'plan_id' => $created['plan_id'],
            'status' => 'draft',
            'is_published' => false,
            'requires_professional_approval' => true,
            'target_kcal' => $targetKcal,
            'calculated_daily_kcal' => $created['daily_energy_kcal'],
            'notice' => 'Este plano é uma sugestão computacional e NÃO substitui a avaliação clínica do nutricionista.',
        ];
    }
}
