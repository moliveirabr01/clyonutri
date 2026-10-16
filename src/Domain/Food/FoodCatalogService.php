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
        // Alimentos essenciais de referência TACO com alergênicos e medidas
        $tacoItems = [
            ['Arroz branco cozido', 'Cereais', 128.0, 2.5, 28.1, 0.2, 1.6, 100, 'g', ''],
            ['Arroz integral cozido', 'Cereais', 124.0, 2.6, 25.8, 1.0, 2.7, 100, 'g', ''],
            ['Feijão carioca cozido', 'Leguminosas', 76.0, 4.8, 13.6, 0.5, 8.5, 100, 'g', ''],
            ['Peito de frango grelhado', 'Carnes e Aves', 159.0, 32.0, 0.0, 2.5, 0.0, 100, 'g', ''],
            ['Ovo de galinha cozido', 'Ovos', 146.0, 13.3, 0.6, 9.5, 0.0, 50, 'unidade', 'ovo'],
            ['Banana prata crua', 'Frutas', 98.0, 1.3, 26.0, 0.1, 2.0, 100, 'unidade', ''],
            ['Aveia em flocos', 'Cereais', 394.0, 13.9, 66.6, 8.5, 9.1, 30, 'colher de sopa', 'gluten, aveia'],
            ['Leite desnatado', 'Leites e Derivados', 35.0, 3.4, 4.9, 0.1, 0.0, 200, 'ml', 'leite, lactose'],
            ['Azeite de oliva extravirgem', 'Óleos e Gorduras', 884.0, 0.0, 0.0, 100.0, 0.0, 13, 'colher de sopa', ''],
            ['Castanha-do-Pará', 'Oleaginosas', 643.0, 14.5, 15.1, 63.5, 7.9, 15, 'unidades', 'castanhas'],
            ['Alface americana crua', 'Verduras e Hortaliças', 9.0, 0.9, 1.7, 0.1, 1.0, 50, 'porção', ''],
            ['Tomate com semente cru', 'Verduras e Hortaliças', 15.0, 1.1, 3.1, 0.2, 1.2, 100, 'unidade', ''],
        ];

        $stmt = $this->db->prepare("
            INSERT INTO foods (name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, allergens, is_custom)
            VALUES (:name, :category, 'TACO', :energy_kcal, :protein_g, :carbohydrate_g, :lipid_g, :fiber_g, :serving_size_g, :serving_unit, :allergens, 0)
        ");

        $count = 0;
        foreach ($tacoItems as $item) {
            $chk = $this->db->prepare("SELECT id FROM foods WHERE name = :name AND source = 'TACO'");
            $chk->execute(['name' => $item[0]]);
            $existingId = $chk->fetchColumn();

            if (!$existingId) {
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
                    'allergens' => $item[9] ?? null,
                ]);
                $foodId = (int)$this->db->lastInsertId();
                $count++;
            } else {
                $foodId = (int)$existingId;
                // Atualizar alérgenos se vazios
                $upd = $this->db->prepare("UPDATE foods SET allergens = :allergens WHERE id = :id AND (allergens IS NULL OR allergens = '')");
                $upd->execute(['allergens' => $item[9] ?? null, 'id' => $foodId]);
            }

            // Inserir medidas caseiras específicas
            if ($item[0] === 'Aveia em flocos') {
                $this->registerMeasure($foodId, 'colher de sopa', 15.0);
                $this->registerMeasure($foodId, 'xícara de chá', 90.0);
            } elseif ($item[0] === 'Azeite de oliva extravirgem') {
                $this->registerMeasure($foodId, 'colher de sopa', 13.0);
                $this->registerMeasure($foodId, 'colher de sobremesa', 8.0);
                $this->registerMeasure($foodId, 'colher de chá', 4.0);
            } elseif ($item[0] === 'Arroz branco cozido') {
                $this->registerMeasure($foodId, 'colher de sopa', 25.0);
                $this->registerMeasure($foodId, 'escumadeira', 100.0);
            }
        }

        return $count;
    }

    private function registerMeasure(int $foodId, string $measure, float $grams): void
    {
        try {
            // SQLite usa "INSERT OR IGNORE"; MySQL usa "INSERT IGNORE".
            $insertIgnore = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
            $stmt = $this->db->prepare("
                {$insertIgnore} INTO food_household_measures (food_id, measure_name, grams_equivalent)
                VALUES (:food_id, :measure, :grams)
            ");
            $stmt->execute(['food_id' => $foodId, 'measure' => $measure, 'grams' => $grams]);
        } catch (\Throwable $e) {
            // Ignorar se tabela ainda não criada ou erro de driver
        }
    }

    /** Autocomplete / callers existentes: até 50 resultados, contrato inalterado. */
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

    /** Tamanhos de página permitidos na Base de Alimentos. */
    public const PER_PAGE_ALLOWED = [25, 50, 100];

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
        'É' => 'E', 'Ê' => 'E', 'Í' => 'I',
        'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ç' => 'C',
    ];

    /**
     * Listagem paginada da Base de Alimentos (somente globais + da própria clínica).
     *
     * @param array{q?:string,category?:string,source?:string,page?:int,per_page?:int} $opts
     * @return array{
     *   foods: list<array>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   pages: int,
     *   from: int,
     *   to: int,
     *   q: string,
     *   category: string,
     *   source: string
     * }
     */
    public function paginate(array $opts = []): array
    {
        $allowed = self::PER_PAGE_ALLOWED;
        $perPage = (int)($opts['per_page'] ?? 50);
        if (!in_array($perPage, $allowed, true)) {
            $perPage = 50;
        }
        $page = max(1, (int)($opts['page'] ?? 1));
        $q = trim((string)($opts['q'] ?? ''));
        $category = trim((string)($opts['category'] ?? ''));
        $source = trim((string)($opts['source'] ?? ''));
        if (!in_array($source, ['', 'TACO', 'clinic'], true)) {
            $source = '';
        }

        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;

        $where = '(is_custom = 0 OR clinic_id = :clinic_id)';
        $params = ['clinic_id' => $clinicId];

        if ($q !== '') {
            $normalized = mb_strtolower(strtr($q, self::ACCENTS));
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized) . '%';
            $nameSql = 'name';
            foreach (self::ACCENTS as $from => $to) {
                $nameSql = 'REPLACE(' . $nameSql . ', ' . $this->db->quote($from) . ', ' . $this->db->quote($to) . ')';
            }
            $nameSql = 'LOWER(' . $nameSql . ')';
            // Também busca na categoria (placeholder da UI: "alimento ou categoria")
            $catSql = "COALESCE(category, '')";
            foreach (self::ACCENTS as $from => $to) {
                $catSql = 'REPLACE(' . $catSql . ', ' . $this->db->quote($from) . ', ' . $this->db->quote($to) . ')';
            }
            $catSql = 'LOWER(' . $catSql . ')';
            $where .= " AND ({$nameSql} LIKE :term ESCAPE '!' OR {$catSql} LIKE :term_cat ESCAPE '!')";
            $params['term'] = $like;
            $params['term_cat'] = $like;
        }

        if ($category !== '') {
            $where .= ' AND category = :category';
            $params['category'] = $category;
        }

        if ($source === 'TACO') {
            $where .= " AND source = 'TACO' AND (clinic_id IS NULL AND is_custom = 0)";
        } elseif ($source === 'clinic') {
            $where .= ' AND clinic_id = :clinic_id_src AND is_custom = 1';
            $params['clinic_id_src'] = $clinicId;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM foods WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT * FROM foods WHERE {$where} ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $foods = $stmt->fetchAll();

        $from = $total === 0 ? 0 : $offset + 1;
        $to = $total === 0 ? 0 : min($offset + count($foods), $total);

        return [
            'foods' => $foods,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'from' => $from,
            'to' => $to,
            'q' => $q,
            'category' => $category,
            'source' => $source,
        ];
    }

    /** Categorias distintas visíveis para a clínica (globais + próprias), ordenadas. */
    public function listCategories(): array
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;
        $stmt = $this->db->prepare("
            SELECT DISTINCT category FROM foods
            WHERE (is_custom = 0 OR clinic_id = :clinic_id)
              AND category IS NOT NULL AND TRIM(category) <> ''
            ORDER BY category ASC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        return array_values(array_filter(array_map(
            static fn($r) => (string)$r['category'],
            $stmt->fetchAll()
        )));
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
