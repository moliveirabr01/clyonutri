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

        // Isolamento: catálogo global (sem clínica) e cadastros da própria clínica. A segunda condição mantém a regra
        // antiga (alimento próprio só da clínica dona); a primeira (v44) barra linha de outra clínica mesmo com is_custom = 0.
        $where = '(f.clinic_id IS NULL OR f.clinic_id = :clinic_id) AND (f.is_custom = 0 OR f.clinic_id = :clinic_id_own)';
        $params = ['clinic_id' => $clinicId, 'clinic_id_own' => $clinicId];
        // v44: como no editor de plano, versão antiga de fonte externa (is_current = 0) não aparece na lista; continua no banco.
        if (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'foods', 'is_current')) {
            $where .= ' AND COALESCE(f.is_current, 1) = 1';
        }

        $order = 'f.name ASC, f.id ASC';
        $orderParams = [];
        $joins = '';
        $joinParams = [];
        $extraCols = '';
        $terms = [];
        if ($q !== '') {
            // v44: a mesma busca do editor de plano (FoodSearchController::search): termos em qualquer ordem, sem acento,
            // plural simples, sinônimos regionais no filtro e na ordem, favoritos e uso da clínica como desempate.
            // Antes a tela chamava FoodSearchIndex::sql() sem sinônimos: "aipim" só achava "Bolo, pronto, aipim".
            // O texto inteiro no nome da categoria continua valendo ("cereais" lista o grupo).
            FoodSearchIndex::sync($this->db);
            $terms = FoodSearchIndex::terms($q);
            $v43 = \ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'food_favorites');
            $fs = FoodSearchIndex::sql($terms, 'f.search_text', 'f.name', $this->db, ['synonyms' => true,
                'tie_break' => 'CASE WHEN COALESCE(u.uses, 0) > 0 THEN 0 ELSE 1 END']
                + ($v43 ? ['favorite' => 'CASE WHEN fav.food_id IS NULL THEN 0 ELSE 1 END'] : []));
            $joins = ' LEFT JOIN (SELECT mi.food_id, COUNT(*) AS uses FROM meal_plan_items mi JOIN meal_plan_meals mm ON mm.id = mi.meal_id
                JOIN meal_plans mp ON mp.id = mm.meal_plan_id WHERE mp.clinic_id = :cid_u GROUP BY mi.food_id) u ON u.food_id = f.id';
            $joinParams['cid_u'] = $clinicId;
            if ($v43) {
                $joins .= ' LEFT JOIN food_favorites fav ON fav.food_id = f.id AND fav.clinic_id = :cid_f';
                $joinParams['cid_f'] = $clinicId;
                $extraCols = ', (fav.food_id IS NOT NULL) AS is_favorite';
            }
            $catMatches = [];
            $needle = FoodSearchIndex::normalize($q);
            foreach ($this->listCategories() as $cat) {
                if ($needle !== '' && str_contains(FoodSearchIndex::normalize($cat), $needle)) {
                    $catMatches[] = $cat;
                }
            }
            $catSql = '';
            $catSpell = [];
            foreach ($catMatches as $cat) {
                foreach (self::categorySpellings($cat) as $sp) {
                    $catSpell[$sp] = true;
                }
            }
            $catSpell = array_map('strval', array_keys($catSpell));
            foreach ($catSpell as $i => $cat) {
                $params["cat_m{$i}"] = $cat;
            }
            if ($catSpell) {
                $catSql = ' OR f.category IN (' . implode(',', array_map(static fn($i) => ":cat_m{$i}", array_keys($catSpell))) . ')';
            }
            $where .= " AND ({$fs['where']}{$catSql})";
            $params += $fs['where_params'];
            $order = $fs['order'] . ', f.id ASC';
            $orderParams = $fs['order_params'];
        }

        if ($category !== '') {
            $spell = self::categorySpellings($category);
            $where .= ' AND f.category IN (' . implode(',', array_map(static fn($i) => ":category{$i}", array_keys($spell))) . ')';
            foreach ($spell as $i => $sp) {
                $params["category{$i}"] = $sp;
            }
        }

        if ($source === 'TACO') {
            $where .= " AND f.source = 'TACO' AND (f.clinic_id IS NULL AND f.is_custom = 0)";
        } elseif ($source === 'clinic') {
            $where .= ' AND f.clinic_id = :clinic_id_src AND f.is_custom = 1';
            $params['clinic_id_src'] = $clinicId;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM foods f WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT f.*{$extraCols} FROM foods f{$joins} WHERE {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params + $joinParams + $orderParams as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $foods = $stmt->fetchAll();
        // v44: quando o nome não tem o termo digitado e só casou pelo sinônimo, a linha diz qual ("aipim = mandioca").
        foreach ($foods as &$row) {
            $row['synonym_match'] = $terms === [] ? null : FoodSearchIndex::synonymLabel($terms, (string)$row['name']);
        }
        unset($row);

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
            'terms' => $terms,
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
        // NI2-520 (v43): "Carnes" e "Carnes e Aves" aparecem uma vez só, como o grupo TACO "Carnes e derivados".
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $g = FoodCategory::canonical((string)$r['category']);
            if ($g !== null) {
                $out[$g] = true;
            }
        }
        $list = array_map('strval', array_keys($out));
        sort($list, SORT_STRING);
        return $list;
    }

    /** Grafias gravadas em foods.category que pertencem ao grupo exibido (o próprio nome quando não é grupo TACO). */
    public static function categorySpellings(string $group): array
    {
        return FoodCategory::isTacoGroup($group) ? FoodCategory::spellingsFor([$group]) : [$group];
    }

    /** v54.41: unidades aceitas para a porção de um alimento da clínica. */
    public const CUSTOM_UNITS = ['g', 'ml', 'unidade', 'fatia', 'colher de sopa', 'colher de chá', 'xícara', 'copo', 'porção'];

    /**
     * v54.41: cadastra um alimento próprio da clínica (Base de Alimentos > Adicionar alimento).
     * Valores nutricionais por 100 g/ml, como na TACO. Fica só na clínica atual (clinic_id + is_custom = 1).
     * @return array{id:int,name:string}
     * @throws \InvalidArgumentException com mensagem para a tela
     */
    public function createClinicFood(array $in): array
    {
        $clinicId = (int)TenantContext::getClinicId();
        if ($clinicId <= 0) {
            throw new \InvalidArgumentException('Clínica não identificada.');
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? '');
        if (mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('Informe o nome do alimento (mínimo 2 letras).');
        }
        if (mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('O nome pode ter até 160 caracteres.');
        }
        $num = static function ($v, string $label, float $max, bool $required) : float {
            $raw = trim(str_replace(',', '.', (string)($v ?? '')));
            if ($raw === '') {
                if ($required) {
                    throw new \InvalidArgumentException("Informe {$label}.");
                }
                return 0.0;
            }
            if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > $max) {
                throw new \InvalidArgumentException(sprintf('%s deve ser um número entre 0 e %s.', ucfirst($label), rtrim(rtrim(number_format($max, 1, ',', ''), '0'), ',')));
            }
            return round((float)$raw, 2);
        };
        $kcal = $num($in['energy_kcal'] ?? '', 'a energia (kcal em 100 g)', 900, true);
        $prot = $num($in['protein_g'] ?? '', 'a proteína (g em 100 g)', 100, true);
        $carb = $num($in['carbohydrate_g'] ?? '', 'o carboidrato (g em 100 g)', 100, true);
        $lip = $num($in['lipid_g'] ?? '', 'o lipídio (g em 100 g)', 100, true);
        $fib = $num($in['fiber_g'] ?? '', 'a fibra (g em 100 g)', 100, false);
        if ($prot + $carb + $lip > 100.5) {
            throw new \InvalidArgumentException('Proteína + carboidrato + lipídio passam de 100 g em 100 g. Confira se os valores estão por 100 g.');
        }
        $serving = trim(str_replace(',', '.', (string)($in['serving_size_g'] ?? '')));
        $serving = $serving === '' ? 100.0 : $serving;
        if (!is_numeric($serving) || (float)$serving <= 0 || (float)$serving > 2000) {
            throw new \InvalidArgumentException('A porção deve ter entre 0,1 e 2000 g (ou ml).');
        }
        $unit = trim((string)($in['serving_unit'] ?? 'g'));
        if (!in_array($unit, self::CUSTOM_UNITS, true)) {
            throw new \InvalidArgumentException('Escolha uma unidade da lista.');
        }
        $category = trim((string)($in['category'] ?? ''));
        if ($category === '') {
            $category = 'Personalizado';
        } elseif ($category !== 'Personalizado' && !in_array($category, $this->listCategories(), true)) {
            throw new \InvalidArgumentException('Escolha um grupo da lista.');
        }
        $allergens = trim((string)($in['allergens'] ?? ''));
        $allergens = $allergens === '' ? null : mb_substr($allergens, 0, 255);

        $search = FoodSearchIndex::searchText($name);
        $dup = $this->db->prepare('SELECT id FROM foods WHERE clinic_id = :cid AND is_custom = 1 AND (search_text = :st OR name = :n) LIMIT 1');
        $dup->execute(['cid' => $clinicId, 'st' => $search, 'n' => $name]);
        if ($dup->fetchColumn()) {
            throw new \InvalidArgumentException("Sua clínica já tem um alimento chamado \"{$name}\". Busque por ele na lista.");
        }

        $hasAllergens = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'foods', 'allergens');
        $cols = 'clinic_id, name, category, source, energy_kcal, protein_g, carbohydrate_g, lipid_g, fiber_g, serving_size_g, serving_unit, is_custom, search_text';
        $vals = ":clinic_id, :name, :category, 'CUSTOM', :kcal, :prot, :carb, :lip, :fib, :serving, :unit, 1, :st";
        $params = ['clinic_id' => $clinicId, 'name' => $name, 'category' => $category, 'kcal' => $kcal, 'prot' => $prot,
            'carb' => $carb, 'lip' => $lip, 'fib' => $fib, 'serving' => round((float)$serving, 2), 'unit' => $unit, 'st' => $search];
        if ($hasAllergens) {
            $cols .= ', allergens';
            $vals .= ', :allergens';
            $params['allergens'] = $allergens;
        }
        $this->db->prepare("INSERT INTO foods ({$cols}) VALUES ({$vals})")->execute($params);
        $id = (int)$this->db->lastInsertId();
        FoodSearchIndex::sync($this->db, 500);
        return ['id' => $id, 'name' => $name];
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
