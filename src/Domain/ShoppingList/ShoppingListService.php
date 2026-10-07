<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ShoppingList;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class ShoppingListService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Mapeia categoria do alimento para o corredor do supermercado
     */
    private function mapAisle(string $category): string
    {
        $cat = mb_strtolower(trim($category), 'UTF-8');
        if (str_contains($cat, 'verdura') || str_contains($cat, 'fruta') || str_contains($cat, 'legum')) {
            return 'Hortifrúti & Feira';
        }
        if (str_contains($cat, 'carne') || str_contains($cat, 'ave') || str_contains($cat, 'ovo') || str_contains($cat, 'peixe')) {
            return 'Açougue & Ovos';
        }
        if (str_contains($cat, 'leite') || str_contains($cat, 'iogurte') || str_contains($cat, 'queijo')) {
            return 'Laticínios & Refrigerados';
        }
        if (str_contains($cat, 'cereal') || str_contains($cat, 'cereais') || str_contains($cat, 'arroz') || str_contains($cat, 'aveia') || str_contains($cat, 'grão')) {
            return 'Cereais & Grãos';
        }
        if (str_contains($cat, 'óleo') || str_contains($cat, 'oleo') || str_contains($cat, 'azeite') || str_contains($cat, 'gordura')) {
            return 'Óleos & Condimentos';
        }
        return 'Mercearia & Diversos';
    }

    /**
     * Gera lista de compras multiplicando as quantidades diárias pelo período selecionado (ex: 7 dias)
     */
    public function generateForPlan(int $planId, int $daysMultiplier = 7): array
    {
        $clinicId = TenantContext::getClinicId();

        // Validar plano
        $pStmt = $this->db->prepare("SELECT id, title, patient_id FROM meal_plans WHERE id = :id AND clinic_id = :clinic_id");
        $pStmt->execute(['id' => $planId, 'clinic_id' => $clinicId]);
        $plan = $pStmt->fetch();

        if (!$plan) {
            throw new \InvalidArgumentException("Plano alimentar não encontrado.");
        }

        // Buscar todos os itens de todas as refeições do plano
        $stmt = $this->db->prepare("
            SELECT i.food_id, i.amount, i.measure_unit, f.name AS food_name, f.category
            FROM meal_plan_items i
            JOIN meal_plan_meals m ON m.id = i.meal_id
            JOIN foods f ON f.id = i.food_id
            WHERE m.meal_plan_id = :plan_id
        ");
        $stmt->execute(['plan_id' => $planId]);
        $items = $stmt->fetchAll();

        // Agregar itens por alimento
        $aggregated = [];
        foreach ($items as $it) {
            $key = $it['food_id'] . '_' . $it['measure_unit'];
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'food_id' => $it['food_id'],
                    'name' => $it['food_name'],
                    'category' => $it['category'] ?? 'Geral',
                    'aisle' => $this->mapAisle($it['category'] ?? ''),
                    'measure_unit' => $it['measure_unit'],
                    'daily_amount' => 0.0,
                    'total_amount' => 0.0,
                ];
            }
            $aggregated[$key]['daily_amount'] += (float)$it['amount'];
            $aggregated[$key]['total_amount'] += (float)$it['amount'] * $daysMultiplier;
        }

        // Agrupar por corredor/gôndola do supermercado
        $byAisle = [];
        foreach ($aggregated as $item) {
            $aisle = $item['aisle'];
            if (!isset($byAisle[$aisle])) {
                $byAisle[$aisle] = [];
            }
            $byAisle[$aisle][] = [
                'name' => $item['name'],
                'amount' => round($item['total_amount'], 1),
                'unit' => $item['measure_unit'],
                'daily' => round($item['daily_amount'], 1),
            ];
        }

        ksort($byAisle);

        return [
            'plan_id' => $planId,
            'plan_title' => $plan['title'],
            'days' => $daysMultiplier,
            'generated_at' => date('c'),
            'total_items_count' => count($aggregated),
            'aisles' => $byAisle,
        ];
    }
}
