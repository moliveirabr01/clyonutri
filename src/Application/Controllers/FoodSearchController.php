<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
final class FoodSearchController
{
    public function search(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $term=$_GET['q']??'';$page=$_GET['page']??'1';
        if(!is_string($term)||mb_strlen($term)>80||!is_string($page)||!ctype_digit($page)||(int)$page<1||(int)$page>100){http_response_code(422);echo json_encode(['error'=>'Informe uma busca de até 80 caracteres e uma página válida.']);return;}
        try {
        $db=Connection::getInstance();$access=$db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id=u.id WHERE u.id=? AND cm.clinic_id=? AND u.role='nutritionist' AND u.status='active' AND cm.status='active' AND cm.role IN ('owner','admin','nutritionist')");$access->execute([TenantContext::getUserId(),TenantContext::getClinicId()]);if(!$access->fetchColumn()){http_response_code(403);echo json_encode(['error'=>'Acesso ao catálogo não autorizado.']);return;}
        // NI2-73: termos em qualquer ordem, sem acento nem pontuação; ordem de relevância explicada em FoodSearchIndex.
        \ClyoNutri\Domain\Food\FoodSearchIndex::sync($db);
        $terms=\ClyoNutri\Domain\Food\FoodSearchIndex::terms($term);
        $q=\ClyoNutri\Domain\Food\FoodSearchIndex::sql($terms,'search_text','name',$db);
        $tenant='(clinic_id IS NULL OR clinic_id=:cid)';
        $count=$db->prepare("SELECT COUNT(*) FROM foods WHERE $tenant AND {$q['where']}");
        $count->execute(['cid'=>TenantContext::getClinicId()]+$q['where_params']);$total=(int)$count->fetchColumn();
        $offset=((int)$page-1)*25;
        $stmt=$db->prepare("SELECT id,name,category,energy_kcal,protein_g,carbohydrate_g,lipid_g,source,taco_code,serving_size_g,serving_unit,(clinic_id IS NOT NULL) AS is_custom FROM foods WHERE $tenant AND {$q['where']} ORDER BY {$q['order']}, id LIMIT 26 OFFSET $offset");
        $stmt->execute(['cid'=>TenantContext::getClinicId()]+$q['where_params']+$q['order_params']);
        $raw=$stmt->fetchAll();$more=count($raw)>25;$raw=array_slice($raw,0,25);
        $measures=self::measures($db,array_map(static fn($r)=>(int)$r['id'],$raw));
        $rows=array_map(static fn(array $r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'category'=>$r['category'],'energy_kcal'=>(float)$r['energy_kcal'],'protein_g'=>(float)$r['protein_g'],'carbohydrate_g'=>(float)$r['carbohydrate_g'],'lipid_g'=>(float)$r['lipid_g'],'source'=>$r['source'],'is_custom'=>(bool)$r['is_custom'],'taco_code'=>$r['taco_code']!==null?(int)$r['taco_code']:null,'reference'=>self::reference($r),'equivalence_key'=>self::equivalenceKey($r),'portion'=>self::portion($r,$measures[(int)$r['id']]??[])],$raw);
        echo json_encode(['data'=>$rows,'has_more'=>$more,'page'=>(int)$page,'total'=>$total,'terms'=>$terms],JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) { http_response_code(503); echo json_encode(['error'=>'Não foi possível carregar o catálogo. Tente novamente.']); }
    }

    /**
     * NI2-74: como a quantidade + unidade vira gramas para este alimento (a mesma regra do servidor ao salvar).
     * GET /catalogo/alimentos/{id}/conversao?amount=1&unit=xícara → peso, origem, se precisa de confirmação e a
     * mensagem; também lista as medidas exatas do alimento para o seletor de unidade.
     */
    public function conversion(array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $id = (string)($params['id'] ?? '');
        $amountRaw = $_GET['amount'] ?? '1';
        $unit = $_GET['unit'] ?? 'g';
        if (!ctype_digit($id) || !is_string($amountRaw) || !is_string($unit) || mb_strlen($unit) > 60) {
            http_response_code(422); echo json_encode(['error' => 'Informe alimento, quantidade e unidade válidos.']); return;
        }
        try {
            $db = Connection::getInstance();
            if (!self::hasCatalogAccess($db)) { http_response_code(403); echo json_encode(['error' => 'Acesso ao catálogo não autorizado.']); return; }
            $st = $db->prepare('SELECT id, name, serving_unit, serving_size_g, nutrient_flags FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :cid)');
            $st->execute(['id' => (int)$id, 'cid' => TenantContext::getClinicId()]);
            $food = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$food) { http_response_code(404); echo json_encode(['error' => 'Alimento não encontrado.']); return; }
            $conv = new \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter($db);
            $out = ['food_id' => (int)$food['id'], 'measures' => $conv->exactMeasuresForFood((int)$food['id'], $food),
                'unknown_nutrients' => \ClyoNutri\Domain\Nutrition\NutritionCalculator::unknownNutrients($food['nutrient_flags'] ?? null)];
            $amount = \ClyoNutri\Domain\MealPlan\MealPlanService::normalizeAmount($amountRaw);
            try {
                $c = $conv->convertDetailed($amount, $unit, (int)$food['id'], $food, false);
                $out += ['ok' => true, 'needs_confirmation' => false, 'grams' => round($c['grams'], 2), 'source' => $c['source'], 'grams_per_unit' => $c['grams_per_unit'], 'message' => null];
            } catch (\ClyoNutri\Domain\Nutrition\UnitConfirmationRequiredException $e) {
                $out += ['ok' => true, 'needs_confirmation' => true, 'grams' => round($e->conversion['grams'], 2), 'source' => $e->conversion['source'], 'grams_per_unit' => $e->conversion['grams_per_unit'], 'message' => $e->getMessage()];
            } catch (\InvalidArgumentException $e) {
                $out += ['ok' => false, 'needs_confirmation' => false, 'grams' => null, 'source' => null, 'grams_per_unit' => null, 'message' => $e->getMessage()];
            }
            echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(503); echo json_encode(['error' => 'Não foi possível conferir a unidade. Tente novamente.']);
        }
    }

    private static function hasCatalogAccess(\PDO $db): bool
    {
        $access = $db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id=u.id WHERE u.id=? AND cm.clinic_id=? AND u.role='nutritionist' AND u.status='active' AND cm.status='active' AND cm.role IN ('owner','admin','nutritionist')");
        $access->execute([TenantContext::getUserId(), TenantContext::getClinicId()]);
        return (bool)$access->fetchColumn();
    }

    /** @return array<int, list<array{name:string, grams:float}>> medidas caseiras cadastradas (até 3 por alimento) */
    private static function measures(\PDO $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($db->query("SELECT food_id, measure_name, grams_equivalent FROM food_household_measures WHERE food_id IN ({$in}) ORDER BY food_id, grams_equivalent")->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            if (count($out[(int)$m['food_id']] ?? []) < 3) {
                $out[(int)$m['food_id']][] = ['name' => (string)$m['measure_name'], 'grams' => (float)$m['grams_equivalent']];
            }
        }
        return $out;
    }

    /**
     * NI2-73: porção visível no resultado. A composição é sempre por 100 g; a porção de referência e as medidas
     * caseiras aparecem quando existem. Sem medida cadastrada, o texto diz isso (não inventa uma).
     */
    public static function portion(array $r, array $measures): array
    {
        $size = (float)($r['serving_size_g'] ?? 100);
        $unit = trim((string)($r['serving_unit'] ?? 'g'));
        $hasServing = $size > 0 && !($unit === 'g' && abs($size - 100) < 0.001);
        $fmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
        $parts = [];
        if ($hasServing) {
            $parts[] = ($unit === 'g' || $unit === 'ml' ? $fmt($size) . ' ' . $unit : '1 ' . $unit . ' = ' . $fmt($size) . ' g');
        }
        foreach ($measures as $m) {
            $parts[] = '1 ' . $m['name'] . ' = ' . $fmt($m['grams']) . ' g';
        }
        return [
            'per' => '100 g',
            'serving_size_g' => $size > 0 ? $size : 100.0,
            'serving_unit' => $unit !== '' ? $unit : 'g',
            'household_measures' => $measures,
            'label' => $parts ? 'Porção: ' . implode(' · ', array_unique($parts)) : 'Sem medida caseira cadastrada (valores por 100 g)',
        ];
    }

    /** UX-08 (v14): identificação legível da origem (nº TACO 4ª ed. x cadastro interno). */
    public static function reference(array $r): string
    {
        if (!empty($r['clinic_id']) || !empty($r['is_custom'])) {
            return 'Cadastro da clínica nº ' . (int)$r['id'];
        }
        if (isset($r['taco_code']) && $r['taco_code'] !== null && $r['taco_code'] !== '') {
            return 'TACO 4ª ed. nº ' . (int)$r['taco_code'];
        }
        return 'Cadastro interno nº ' . (int)$r['id'] . ' (' . trim((string)($r['source'] ?: 'sem fonte')) . ', sem nº TACO)';
    }

    /**
     * UX-08 (v14): chave de equivalência só para APRESENTAÇÃO — mesmo nome normalizado (sem acento,
     * pontuação e a palavra "TACO") e mesma composição por 100 g. Nada é mesclado/apagado no banco;
     * planos continuam apontando para o id original.
     */
    public static function equivalenceKey(array $r): string
    {
        $name = mb_strtolower((string)$r['name']);
        $name = strtr($name, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
        $name = preg_replace('/\btaco\b/u', ' ', $name);
        $name = trim(preg_replace('/[^a-z0-9]+/', ' ', (string)$name));
        $macros = sprintf('%.0f|%.1f|%.1f|%.1f', (float)$r['energy_kcal'], (float)$r['protein_g'], (float)$r['carbohydrate_g'], (float)$r['lipid_g']);
        return sha1(((int)(bool)$r['is_custom']) . '|' . $name . '|' . $macros);
    }
}
