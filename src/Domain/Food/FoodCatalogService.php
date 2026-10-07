<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Food;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class FoodCatalogService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function seedTacoReference(): int
    {
        // Alimentos essenciais de referência TACO
        $tacoItems = [
            ['Arroz branco cozido', 'Cereais', 128.0, 2.5, 28.1, 0.2, 1.6, 100, 'g'],
            ['Arroz integral cozido', 'Cereais', 124.0, 2.6, 25.8, 1.0, 2.7, 100, 'g'],
            ['Feijão carioca cozido', 'Leguminosas', 76.0, 4.8, 13.6, 0.5, 8.5, 100, 'g'],
            ['Peito de frango grelhado', 'Carnes e Aves', 159.0, 32.0, 0.0, 2.5, 0.0, 100, 'g'],
            ['Ovo de galinha cozido', 'Ovos', 146.0, 13.3, 0.6, 9.5, 0.0, 50, 'unidade'],
            ['Banana prata crua', 'Frutas', 98.0, 1.3, 26.0, 0.1, 2.0, 100, 'unidade'],
            ['Aveia em flocos', 'Cereais', 394.0, 13.9, 66.6, 8.5, 9.1, 30, 'colher de sopa'],
            ['Leite desnatado', 'Leites e Derivados', 35.0, 3.4, 4.9, 0.1, 0.0, 200, 'ml'],
            ['Azeite de oliva extravirgem', 'Óleos e Gorduras', 884.0, 0.0, 0.0, 100.0, 0.0, 13, 'colher de sopa'],
            ['Castanha-do-Pará', 'Oleaginosas', 643.0, 14.5, 15.1, 63.5, 7.9, 15, 'unidades'],
            ['Alface americana crua', 'Verduras e Hortaliças', 9.0, 0.9, 1.7, 0.1, 1.0, 50, 'porção'],
            ['Tomate com semente cru', 'Verduras e Hortaliças', 15.0, 1.1, 3.1, 0.2, 1.2, 100, 'unidade'],
        ];

        $stmt = $this->db->prepare("
            INSERT INTO foods (name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom)
            VALUES (:name, :category, 'TACO', :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g, :serving_size_g, :serving_unit, 0)
        ");

        $count = 0;
        foreach ($tacoItems as $item) {
            // Verificar existência prévia
            $chk = $this->db->prepare("SELECT id FROM foods WHERE name = :name AND source = 'TACO'");
            $chk->execute(['name' => $item[0]]);
            if (!$chk->fetch()) {
                $stmt->execute([
                    'name' => $item[0],
                    'category' => $item[1],
                    'energy_kcal' => $item[2],
                    'protein_g' => $item[3],
                    'carbohydrate_g' => $item[4],
                    'lipid_g' => $item[5],
                    'fiber_g' => $item[6],
                    'serving_size_g' => $item[7],
                    'serving_unit' => $item[8],
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function search(string $term): array
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;

        $sql = "
            SELECT * FROM foods
            WHERE (is_custom = 0 OR clinic_id = :clinic_id)
              AND name LIKE :term
            ORDER BY is_custom DESC, name ASC
            LIMIT 50
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'clinic_id' => $clinicId,
            'term' => '%' . trim($term) . '%',
        ]);

        return $stmt->fetchAll();
    }

    public function addCustomFood(array $data): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            INSERT INTO foods (
                clinic_id, name, category, source,
                energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g,
                serving_size_g, serving_unit, is_custom
            ) VALUES (
                :clinic_id, :name, :category, 'CUSTOM',
                :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g,
                :serving_size_g, :serving_unit, 1
            )
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'name' => trim($data['name']),
            'category' => $data['category'] ?? 'Personalizado',
            'energy_kcal' => (float)$data['energy_kcal'],
            'protein_g' => (float)$data['protein_g'],
            'carbohydrate_g' => (float)$data['carbohydrate_g'],
            'lipid_g' => (float)$data['lipid_g'],
            'fiber_g' => (float)($data['fiber_g'] ?? 0),
            'serving_size_g' => (float)($data['serving_size_g'] ?? 100),
            'serving_unit' => $data['serving_unit'] ?? 'g',
        ]);

        $id = (int)$this->db->lastInsertId();
        return ['id' => $id, 'name' => $data['name']];
    }
}
