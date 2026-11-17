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
        \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::ensureCommonMeasures($db);
        \ClyoNutri\Domain\Food\FoodSearchIndex::sync($db);
        $terms=\ClyoNutri\Domain\Food\FoodSearchIndex::terms($term);
        // NI2-520 (v42/v43): sinônimos regionais valem no filtro e na ordem; favoritos da clínica e alimentos já usados
        // pela clínica desempatam dentro do mesmo grupo de relevância (regra completa em FoodSearchIndex::sql).
        $cid=TenantContext::getClinicId();
        $v43=\ClyoNutri\Core\Database\SchemaInfo::hasTable($db,'food_favorites')&&\ClyoNutri\Core\Database\SchemaInfo::hasColumn($db,'foods','is_current');
        $q=\ClyoNutri\Domain\Food\FoodSearchIndex::sql($terms,'f.search_text','f.name',$db,['synonyms'=>true,'tie_break'=>'CASE WHEN COALESCE(u.uses,0)>0 THEN 0 ELSE 1 END']+($v43?['favorite'=>'CASE WHEN fav.food_id IS NULL THEN 0 ELSE 1 END']:[]));
        $tenant='(f.clinic_id IS NULL OR f.clinic_id=:cid)';
        // v43: versão antiga de uma fonte externa (is_current=0) sai da busca, mas continua no banco para os planos que a usam.
        if($v43){$tenant.=' AND COALESCE(f.is_current,1)=1';}
        $count=$db->prepare("SELECT COUNT(*) FROM foods f WHERE $tenant AND {$q['where']}");
        $count->execute(['cid'=>$cid]+$q['where_params']);$total=(int)$count->fetchColumn();
        $offset=((int)$page-1)*25;
        $usage="LEFT JOIN (SELECT mi.food_id, COUNT(*) AS uses FROM meal_plan_items mi JOIN meal_plan_meals mm ON mm.id=mi.meal_id JOIN meal_plans mp ON mp.id=mm.meal_plan_id WHERE mp.clinic_id=:cid_u GROUP BY mi.food_id) u ON u.food_id=f.id";
        $fav=$v43?' LEFT JOIN food_favorites fav ON fav.food_id=f.id AND fav.clinic_id=:cid_f':'';
        $cols=$v43?',f.source_version,f.source_code,f.preparation,(fav.food_id IS NOT NULL) AS is_favorite':',NULL AS source_version,NULL AS source_code,NULL AS preparation,0 AS is_favorite';
        $stmt=$db->prepare("SELECT f.id,f.name,f.category,f.energy_kcal,f.protein_g,f.carbohydrate_g,f.lipid_g,f.source,f.taco_code,f.serving_size_g,f.serving_unit,f.allergens,(f.clinic_id IS NOT NULL) AS is_custom,COALESCE(u.uses,0) AS clinic_uses{$cols} FROM foods f $usage$fav WHERE $tenant AND {$q['where']} ORDER BY {$q['order']}, f.id LIMIT 26 OFFSET $offset");
        $stmt->execute(['cid'=>$cid,'cid_u'=>$cid]+($v43?['cid_f'=>$cid]:[])+$q['where_params']+$q['order_params']);
        $raw=$stmt->fetchAll();$more=count($raw)>25;$raw=array_slice($raw,0,25);
        $measures=self::measures($db,array_map(static fn($r)=>(int)$r['id'],$raw));
        $conv = new \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter($db);
        $patientId = self::resolvePatientIdFromRequest($db, (int)$cid);
        $allergenValidator = $patientId > 0 ? new \ClyoNutri\Domain\Clinical\AllergenSafetyValidator($db) : null;
        $patientAllergyState = $allergenValidator !== null ? $allergenValidator->evaluatePatientAllergyState($patientId) : null;
        $rows=array_map(static function(array $r)use($measures,$terms,$allergenValidator,$patientAllergyState,$conv,$cid){$prep=$r['preparation']!==null&&$r['preparation']!==''?(string)$r['preparation']:\ClyoNutri\Domain\Food\FoodSearchIndex::preparation((string)$r['name']);$group=\ClyoNutri\Domain\Food\FoodCategory::canonical($r['category']);$defPresc=$conv->defaultPrescriptionForFood((int)$r['id'],$r,(int)$cid);$row=['id'=>(int)$r['id'],'name'=>$r['name'],'category'=>\ClyoNutri\Domain\Food\FoodSearchIndex::categoryLabel($group),'category_original'=>$r['category'],'preparation'=>$prep,'preparation_label'=>$prep??'Preparo não informado no nome','part_highlight'=>\ClyoNutri\Domain\Food\FoodSearchIndex::detectPartHighlight((string)$r['name']),'default_prescription'=>$defPresc,'clinic_uses'=>(int)$r['clinic_uses'],'is_favorite'=>(bool)$r['is_favorite'],'synonym_match'=>\ClyoNutri\Domain\Food\FoodSearchIndex::synonymLabel($terms,(string)$r['name']),'energy_kcal'=>(float)$r['energy_kcal'],'protein_g'=>(float)$r['protein_g'],'carbohydrate_g'=>(float)$r['carbohydrate_g'],'lipid_g'=>(float)$r['lipid_g'],'source'=>$r['source'],'source_version'=>$r['source_version'],'source_label'=>self::sourceLabel($r),'is_custom'=>(bool)$r['is_custom'],'taco_code'=>$r['taco_code']!==null?(int)$r['taco_code']:null,'reference'=>self::reference($r),'equivalence_key'=>self::equivalenceKey($r),'portion'=>self::portion($r,$measures[(int)$r['id']]??[])];if($allergenValidator!==null&&$patientAllergyState!==null){$row['allergen_alert']=$allergenValidator->inspectFoodRowForState($patientAllergyState,$r);}return $row;},$raw);
        if((int)$page===1){self::recordSearch($db,$terms,$total);}
        $payload=['data'=>$rows,'has_more'=>$more,'page'=>(int)$page,'total'=>$total,'terms'=>$terms];
        if($patientAllergyState!==null){$payload['patient_allergy_state']=$patientAllergyState;}
        echo json_encode($payload,JSON_THROW_ON_ERROR);
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
            $cid = (int)TenantContext::getClinicId();
            if (!self::hasCatalogAccess($db)) { http_response_code(403); echo json_encode(['error' => 'Acesso ao catálogo não autorizado.']); return; }
            $st = $db->prepare('SELECT id, name, serving_unit, serving_size_g, nutrient_flags, allergens, energy_kcal, protein_g, carbohydrate_g, lipid_g FROM foods WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :cid)');
            $st->execute(['id' => (int)$id, 'cid' => $cid]);
            $food = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$food) { http_response_code(404); echo json_encode(['error' => 'Alimento não encontrado.']); return; }
            $conv = new \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter($db);
            $out = ['food_id' => (int)$food['id'], 'measures' => $conv->exactMeasuresForFood((int)$food['id'], $food),
                'default_prescription' => $conv->defaultPrescriptionForFood((int)$food['id'], $food, $cid),
                'register_measure_url' => '/medidas-caseiras?food=' . (int)$food['id'],
                'unknown_nutrients' => \ClyoNutri\Domain\Nutrition\NutritionCalculator::unknownNutrients($food['nutrient_flags'] ?? null)];
            $patientId = self::resolvePatientIdFromRequest($db, $cid);
            if ($patientId > 0) {
                $out['allergen_alert'] = (new \ClyoNutri\Domain\Clinical\AllergenSafetyValidator($db))->inspectFoodForPatient($patientId, (int)$food['id']);
            }
            $amount = \ClyoNutri\Domain\MealPlan\MealPlanService::normalizeAmount($amountRaw);
            $calcNutrition = static function (float $grams) use ($food): array {
                $ratio = $grams / 100.0;
                return [
                    'energy_kcal' => round(((float)($food['energy_kcal'] ?? 0)) * $ratio, 1),
                    'protein_g' => round(((float)($food['protein_g'] ?? 0)) * $ratio, 1),
                    'carbohydrate_g' => round(((float)($food['carbohydrate_g'] ?? 0)) * $ratio, 1),
                    'lipid_g' => round(((float)($food['lipid_g'] ?? 0)) * $ratio, 1),
                    'grams' => round($grams, 2),
                ];
            };
            try {
                $conv->assertCountUnitHasMeasure($unit, (int)$food['id'], $food); // NI2-609: sem 100 g por "unidade"
                $c = $conv->convertDetailed($amount, $unit, (int)$food['id'], $food, false);
                $g = round($c['grams'], 2);
                $out += ['ok' => true, 'needs_confirmation' => false, 'grams' => $g, 'source' => $c['source'], 'source_label' => $c['source_label'] ?? null, 'grams_per_unit' => $c['grams_per_unit'], 'message' => null, 'nutrition' => $calcNutrition($g)];
            } catch (\ClyoNutri\Domain\Nutrition\UnitConfirmationRequiredException $e) {
                $g = round($e->conversion['grams'], 2);
                $out += ['ok' => true, 'needs_confirmation' => true, 'grams' => $g, 'source' => $e->conversion['source'], 'grams_per_unit' => $e->conversion['grams_per_unit'], 'message' => $e->getMessage(), 'nutrition' => $calcNutrition($g)];
            } catch (\InvalidArgumentException $e) {
                $out += ['ok' => false, 'needs_confirmation' => false, 'grams' => null, 'source' => null, 'grams_per_unit' => null, 'message' => $e->getMessage(), 'nutrition' => null];
            }
            echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(503); echo json_encode(['error' => 'Não foi possível conferir a unidade. Tente novamente.']);
        }
    }

    /**
     * NI2-520 (v42): contagem diária por termo e por clínica, para achar buscas sem resultado. Só o termo normalizado
     * (sem acento, até 80 caracteres), sem usuário nem paciente. Falha aqui nunca derruba a busca.
     */
    public static function recordSearch(\PDO $db, array $terms, int $total): void
    {
        $term = implode(' ', $terms);
        if (mb_strlen($term) < 3 || !TenantContext::hasClinic()) {
            return;
        }
        $term = mb_substr($term, 0, 80);
        $zero = $total === 0 ? 1 : 0;
        try {
            $mysql = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
            $sql = $mysql
                ? 'INSERT INTO food_search_metrics (clinic_id, metric_date, term, searches, zero_results, last_result_count, updated_at) VALUES (:c, :d, :t, 1, :z, :n, :u)
                   ON DUPLICATE KEY UPDATE searches = searches + 1, zero_results = zero_results + VALUES(zero_results), last_result_count = VALUES(last_result_count), updated_at = VALUES(updated_at)'
                : 'INSERT INTO food_search_metrics (clinic_id, metric_date, term, searches, zero_results, last_result_count, updated_at) VALUES (:c, :d, :t, 1, :z, :n, :u)
                   ON CONFLICT (clinic_id, metric_date, term) DO UPDATE SET searches = searches + 1, zero_results = zero_results + excluded.zero_results, last_result_count = excluded.last_result_count, updated_at = excluded.updated_at';
            $db->prepare($sql)->execute(['c' => TenantContext::getClinicId(), 'd' => date('Y-m-d'), 't' => $term, 'z' => $zero, 'n' => $total, 'u' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('NI2-520 search metric skipped: ' . $e->getMessage());
        }
    }

    /** Corpo JSON (picker) ou formulário. */
    private static function input(): array
    {
        $raw = file_get_contents('php://input');
        $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($j) ? $j : $_POST;
    }

    /**
     * POST /catalogo/alimentos/{id}/favorito (NI2-520, v43): liga ou desliga o favorito da clínica. O favorito só
     * desempata a busca dentro do mesmo nível de relevância; não muda nenhum plano.
     */
    public function favorite(array $params): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $db = Connection::getInstance();
        if (!self::hasCatalogAccess($db)) { http_response_code(403); echo json_encode(['error' => 'Acesso ao catálogo não autorizado.']); return; }
        if (!\ClyoNutri\Core\Database\SchemaInfo::hasTable($db, 'food_favorites')) { http_response_code(409); echo json_encode(['error' => 'Favoritos precisam da atualização do banco v43.']); return; }
        $foodId = (int)($params['id'] ?? 0);
        $cid = (int)TenantContext::getClinicId();
        $st = $db->prepare('SELECT id, name FROM foods WHERE id = ? AND (clinic_id IS NULL OR clinic_id = ?)');
        $st->execute([$foodId, $cid]);
        $food = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$food) { http_response_code(404); echo json_encode(['error' => 'Alimento não encontrado.']); return; }
        $has = $db->prepare('SELECT id FROM food_favorites WHERE clinic_id = ? AND food_id = ?');
        $has->execute([$cid, $foodId]);
        $favId = $has->fetchColumn();
        if ($favId !== false) {
            $db->prepare('DELETE FROM food_favorites WHERE id = ? AND clinic_id = ?')->execute([(int)$favId, $cid]);
            $on = false;
        } else {
            $db->prepare('INSERT INTO food_favorites (clinic_id, food_id, created_by_user_id, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$cid, $foodId, TenantContext::getUserId(), date('Y-m-d H:i:s')]);
            $on = true;
        }
        (new \ClyoNutri\Core\Audit\AuditLogger($db))->log($on ? 'FOOD_FAVORITE_ADDED' : 'FOOD_FAVORITE_REMOVED', 'foods', (string)$foodId, ['name' => (string)$food['name']]);
        echo json_encode(['food_id' => $foodId, 'favorite' => $on]);
    }

    /**
     * POST /catalogo/alimentos/selecao (NI2-520, v43): conta quando um resultado é escolhido e quando a escolha é
     * trocada na mesma busca (sinal de seleção incorreta). Guarda só termo, dia e alimento; nada de paciente ou usuário.
     */
    public function selection(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $db = Connection::getInstance();
        if (!self::hasCatalogAccess($db)) { http_response_code(403); echo json_encode(['error' => 'Acesso ao catálogo não autorizado.']); return; }
        $in = self::input();
        $term = \ClyoNutri\Domain\Food\FoodSearchIndex::normalize((string)($in['term'] ?? ''));
        $foodId = (int)($in['food_id'] ?? 0);
        $event = (string)($in['event'] ?? '');
        if (mb_strlen($term) < 2 || $foodId <= 0 || !in_array($event, ['selected', 'corrected'], true)) {
            http_response_code(422); echo json_encode(['error' => 'Informe termo, alimento e evento válidos.']); return;
        }
        if (!\ClyoNutri\Core\Database\SchemaInfo::hasTable($db, 'food_search_selections')) { echo json_encode(['recorded' => false]); return; }
        $cid = (int)TenantContext::getClinicId();
        $chk = $db->prepare('SELECT 1 FROM foods WHERE id = ? AND (clinic_id IS NULL OR clinic_id = ?)');
        $chk->execute([$foodId, $cid]);
        if (!$chk->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Alimento não encontrado.']); return; }
        $term = mb_substr($term, 0, 80);
        $corr = $event === 'corrected' ? 1 : 0;
        try {
            $mysql = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
            $sql = $mysql
                ? 'INSERT INTO food_search_selections (clinic_id, metric_date, term, food_id, selections, corrections, updated_at) VALUES (:c, :d, :t, :f, 1, :k, :u)
                   ON DUPLICATE KEY UPDATE selections = selections + 1, corrections = corrections + VALUES(corrections), updated_at = VALUES(updated_at)'
                : 'INSERT INTO food_search_selections (clinic_id, metric_date, term, food_id, selections, corrections, updated_at) VALUES (:c, :d, :t, :f, 1, :k, :u)
                   ON CONFLICT (clinic_id, metric_date, term, food_id) DO UPDATE SET selections = selections + 1, corrections = corrections + excluded.corrections, updated_at = excluded.updated_at';
            $db->prepare($sql)->execute(['c' => $cid, 'd' => date('Y-m-d'), 't' => $term, 'f' => $foodId, 'k' => $corr, 'u' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            error_log('NI2-520 selection metric skipped: ' . $e->getMessage());
            echo json_encode(['recorded' => false]); return;
        }
        echo json_encode(['recorded' => true]);
    }

    /** GET /alimentos/buscas: termos buscados sem resultado nos últimos 30 dias, só da clínica atual. */
    public function zeroResultReport(): void
    {
        $db = Connection::getInstance();
        if (!self::hasCatalogAccess($db)) {
            http_response_code(403);
            echo 'Apenas nutricionistas da clínica podem ver as buscas do catálogo.';
            return;
        }
        $cid = TenantContext::getClinicId();
        $rows = [];
        $selections = [];
        $unavailable = false;
        try {
            // Retenção: guarda 90 dias; mostra 30.
            $db->prepare('DELETE FROM food_search_metrics WHERE clinic_id = ? AND metric_date < ?')->execute([$cid, date('Y-m-d', strtotime('-90 days'))]);
            $st = $db->prepare('SELECT term, SUM(searches) AS searches, SUM(zero_results) AS zero_results, MAX(metric_date) AS last_date
                FROM food_search_metrics WHERE clinic_id = ? AND metric_date >= ? GROUP BY term HAVING SUM(zero_results) > 0
                ORDER BY SUM(zero_results) DESC, MAX(metric_date) DESC, term ASC LIMIT 100');
            $st->execute([$cid, date('Y-m-d', strtotime('-30 days'))]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            // NI2-520 (v43): termos em que a equipe trocou de alimento depois de escolher (seleção incorreta).
            $selections = [];
            if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($db, 'food_search_selections')) {
                $db->prepare('DELETE FROM food_search_selections WHERE clinic_id = ? AND metric_date < ?')->execute([$cid, date('Y-m-d', strtotime('-90 days'))]);
                $ss = $db->prepare('SELECT s.term, SUM(s.selections) AS selections, SUM(s.corrections) AS corrections, MAX(s.metric_date) AS last_date
                    FROM food_search_selections s WHERE s.clinic_id = ? AND s.metric_date >= ? GROUP BY s.term HAVING SUM(s.corrections) > 0
                    ORDER BY SUM(s.corrections) DESC, MAX(s.metric_date) DESC, s.term ASC LIMIT 100');
                $ss->execute([$cid, date('Y-m-d', strtotime('-30 days'))]);
                $selections = $ss->fetchAll(\PDO::FETCH_ASSOC);
            }
        } catch (\PDOException $e) {
            error_log('NI2-520 report unavailable: ' . $e->getMessage());
            $unavailable = true; // migração 62 pendente
        }
        $title = 'Buscas sem resultado';
        $activeNav = 'alimentos';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Profissional';
        $csrfToken = \ClyoNutri\Core\Security\Csrf::token();
        ob_start();
        require __DIR__ . '/../../../views/foods/search_report.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private static function hasCatalogAccess(\PDO $db): bool
    {
        $access = $db->prepare("SELECT 1 FROM users u JOIN clinic_members cm ON cm.user_id=u.id WHERE u.id=? AND cm.clinic_id=? AND u.role='nutritionist' AND u.status='active' AND cm.status='active' AND cm.role IN ('owner','admin','nutritionist')");
        $access->execute([TenantContext::getUserId(), TenantContext::getClinicId()]);
        return (bool)$access->fetchColumn();
    }

    private static function resolvePatientIdFromRequest(\PDO $db, int $clinicId): int
    {
        $rawPatient = $_GET['patient_id'] ?? '';
        if (is_string($rawPatient) && ctype_digit($rawPatient) && (int)$rawPatient > 0) {
            $st = $db->prepare('SELECT id FROM patients WHERE id = ? AND clinic_id = ?');
            $st->execute([(int)$rawPatient, $clinicId]);
            $found = $st->fetchColumn();
            return $found !== false ? (int)$found : 0;
        }
        $rawPlan = $_GET['plan_id'] ?? '';
        if (is_string($rawPlan) && ctype_digit($rawPlan) && (int)$rawPlan > 0) {
            $st = $db->prepare('SELECT patient_id FROM meal_plans WHERE id = ? AND clinic_id = ?');
            $st->execute([(int)$rawPlan, $clinicId]);
            $found = $st->fetchColumn();
            return $found !== false && $found !== null ? (int)$found : 0;
        }
        return 0;
    }

    /** @return array<int, list<array{name:string, grams:float}>> medidas caseiras cadastradas (até 3 por alimento) */
    private static function measures(\PDO $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        $seen = [];
        $add = static function (array $m) use (&$out, &$seen): void {
            $fid = (int)$m['food_id'];
            $key = \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::normalizeUnit((string)$m['measure_name']);
            if (isset($seen[$fid][$key]) || count($out[$fid] ?? []) >= 3) {
                return;
            }
            $seen[$fid][$key] = true;
            $out[$fid][] = ['name' => (string)$m['measure_name'], 'grams' => (float)$m['grams_equivalent']];
        };
        // NI2-514: medidas cadastradas pela clínica (com fonte) vêm antes das globais; só as da própria clínica.
        if (TenantContext::hasClinic()) {
            try {
                $st = $db->prepare("SELECT food_id, measure_name, grams_equivalent FROM clinic_household_measures WHERE clinic_id = ? AND food_id IN ({$in}) ORDER BY food_id, grams_equivalent");
                $st->execute([TenantContext::getClinicId()]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $m) {
                    $add($m);
                }
            } catch (\PDOException) {
                // migração 61 pendente: só a base global
            }
        }
        $notRemoved = \ClyoNutri\Domain\Nutrition\HouseholdMeasureReviewService::ready($db) ? " AND (review_status IS NULL OR review_status <> 'removed')" : ''; // NI2-886
        foreach ($db->query("SELECT food_id, measure_name, grams_equivalent FROM food_household_measures WHERE food_id IN ({$in}){$notRemoved} ORDER BY food_id, grams_equivalent")->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $add($m);
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
            $mName = $m['name'];
            if (preg_match('/^\d+\s+/u', $mName)) {
                $parts[] = $mName . ' = ' . $fmt($m['grams']) . ' g';
            } else {
                $parts[] = '1 ' . $mName . ' = ' . $fmt($m['grams']) . ' g';
            }
        }
        return [
            'per' => '100 g',
            'serving_size_g' => $size > 0 ? $size : 100.0,
            'serving_unit' => $unit !== '' ? $unit : 'g',
            'household_measures' => $measures,
            'label' => $parts ? 'Porção: ' . implode(' · ', array_unique($parts)) : 'Sem medida caseira cadastrada (valores por 100 g)',
        ];
    }

    /**
     * NI2-520 (v43): fonte e versão sempre visíveis no resultado ("TACO 4ª ed. rev. e ampl. (2011)",
     * "USDA FoodData Central, Foundation Foods (2025-04)", "Cadastro da clínica").
     */
    public static function sourceLabel(array $r): string
    {
        if (!empty($r['is_custom'])) {
            return 'Cadastro da clínica';
        }
        $src = trim((string)($r['source'] ?? ''));
        $ver = trim((string)($r['source_version'] ?? ''));
        if ($src === 'TACO') {
            return 'TACO ' . ($ver !== '' ? $ver : '4ª ed.');
        }
        if ($src === 'USDA_FDC') {
            return 'USDA FoodData Central' . ($ver !== '' ? ', ' . $ver : '');
        }
        if ($src === '') {
            return 'Fonte não informada';
        }
        return $src . ($ver !== '' ? ' (' . $ver . ')' : '');
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
