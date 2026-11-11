<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Food;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Domain\MealPlan\SubstitutionComparison;
use PDO;

/**
 * Regras de equivalência da clínica (NI2-519 / NI2-520, v43).
 *
 * O sistema não traz lista de equivalentes pronta: não há fonte com licença comercial para isso (ver
 * docs/benchmark-webdiet/FONTES-E-LICENCAS-V43.md). A clínica cadastra a regra: quais grupos de alimentos da TACO
 * podem ser trocados entre si, a tolerância de energia e de macronutrientes e a fonte que embasa a regra (obrigatória).
 * A regra nasce "pendente de revisão" e só um nutricionista com CRN marca como revisada; qualquer edição volta para
 * pendente. O cálculo é aritmético sobre a composição já gravada nos itens do plano.
 *
 * Veredito de uma troca (evaluate):
 *   sem_regra           nenhum grupo do item tem regra ativa: mostra só a diferença, sem dizer "equivalente";
 *   grupos_diferentes   o substituto está fora dos grupos da regra do item;
 *   fora_da_tolerancia  mesmo grupo, mas energia ou algum macro passa da tolerância;
 *   equivalente         dentro da regra e das tolerâncias.
 * Tolerância de macro: |diferença| <= max(tol% x valor do item, 1 g), para não reprovar diferenças de décimos em
 * alimentos com pouco do macro.
 */
final class FoodEquivalenceService
{
    public const MIN_SOURCE = 10;
    public const MAX_ENERGY_TOL = 50.0;
    public const MAX_MACRO_TOL = 100.0;
    public const MACRO_FLOOR_G = 1.0;
    public const VERDICT_LABELS = [
        'equivalente' => 'Equivalente pela regra da clínica',
        'fora_da_tolerancia' => 'Fora da tolerância da regra',
        'grupos_diferentes' => 'Grupo diferente do item',
        'sem_regra' => 'Sem regra de equivalência',
    ];

    private PDO $db;
    /** @var list<array>|null */
    private ?array $rulesCache = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db, 'food_equivalence_rules');
    }

    /** @return list<array> regras da clínica atual (ativas; com $all também as arquivadas) */
    public function listRules(bool $all = false): array
    {
        if (!$this->available()) {
            return [];
        }
        $st = $this->db->prepare('SELECT * FROM food_equivalence_rules WHERE clinic_id = ?' . ($all ? '' : " AND status = 'active'") . ' ORDER BY status ASC, name ASC, id ASC');
        $st->execute([TenantContext::getClinicId()]);
        return array_map([self::class, 'hydrate'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM food_equivalence_rules WHERE id = ? AND clinic_id = ?');
        $st->execute([$id, TenantContext::getClinicId()]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::hydrate($r) : null;
    }

    /** Valida e normaliza os campos do formulário. @throws \InvalidArgumentException */
    public static function validate(array $in): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Dê um nome à regra (até 120 caracteres).');
        }
        $groups = $in['food_groups'] ?? [];
        if (!is_array($groups)) {
            $groups = [$groups];
        }
        $clean = [];
        foreach ($groups as $g) {
            $g = FoodCategory::canonical(is_string($g) ? $g : '');
            if ($g !== null && FoodCategory::isTacoGroup($g)) {
                $clean[$g] = true;
            }
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('Escolha pelo menos um grupo de alimentos da TACO para a regra.');
        }
        $energy = self::pct($in['energy_tolerance_pct'] ?? '10', self::MAX_ENERGY_TOL, 'energia');
        $macro = self::pct($in['macro_tolerance_pct'] ?? '20', self::MAX_MACRO_TOL, 'macronutrientes');
        $source = trim((string)($in['source_note'] ?? ''));
        if (mb_strlen($source) < self::MIN_SOURCE || mb_strlen($source) > 500) {
            throw new \InvalidArgumentException('Informe a fonte que embasa a regra (de 10 a 500 caracteres), por exemplo a referência bibliográfica ou o protocolo da clínica.');
        }
        return ['name' => $name, 'food_groups' => array_map('strval', array_keys($clean)), 'energy_tolerance_pct' => $energy, 'macro_tolerance_pct' => $macro, 'source_note' => $source];
    }

    public function create(array $in, int $userId): int
    {
        $this->requireTable();
        $v = self::validate($in);
        $now = date('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO food_equivalence_rules (clinic_id, name, food_groups, energy_tolerance_pct, macro_tolerance_pct, source_note, review_status, status, created_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pendente', 'active', ?, ?, ?)")
            ->execute([TenantContext::getClinicId(), $v['name'], json_encode($v['food_groups'], JSON_UNESCAPED_UNICODE), $v['energy_tolerance_pct'], $v['macro_tolerance_pct'], $v['source_note'], $userId, $now, $now]);
        $id = (int)$this->db->lastInsertId();
        (new AuditLogger($this->db))->log('FOOD_EQUIVALENCE_RULE_CREATED', 'food_equivalence_rules', (string)$id, ['name' => $v['name'], 'groups' => $v['food_groups'], 'energy_tolerance_pct' => $v['energy_tolerance_pct'], 'macro_tolerance_pct' => $v['macro_tolerance_pct']]);
        $this->rulesCache = null;
        return $id;
    }

    /** Edição volta a regra para pendente de revisão. */
    public function update(int $id, array $in): void
    {
        $rule = $this->ownRule($id);
        if ($rule['status'] !== 'active') {
            throw new \DomainException('Regra arquivada não pode ser editada.');
        }
        $v = self::validate($in);
        $this->db->prepare("UPDATE food_equivalence_rules SET name = ?, food_groups = ?, energy_tolerance_pct = ?, macro_tolerance_pct = ?, source_note = ?,
            review_status = 'pendente', reviewed_by_user_id = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ? AND clinic_id = ?")
            ->execute([$v['name'], json_encode($v['food_groups'], JSON_UNESCAPED_UNICODE), $v['energy_tolerance_pct'], $v['macro_tolerance_pct'], $v['source_note'], date('Y-m-d H:i:s'), $id, TenantContext::getClinicId()]);
        (new AuditLogger($this->db))->log('FOOD_EQUIVALENCE_RULE_UPDATED', 'food_equivalence_rules', (string)$id, ['before' => array_intersect_key($rule, $v), 'after' => $v]);
        $this->rulesCache = null;
    }

    /** Marca como revisada; quem chama garante que o usuário é nutricionista com CRN. */
    public function markReviewed(int $id, int $userId): void
    {
        $rule = $this->ownRule($id);
        if ($rule['status'] !== 'active') {
            throw new \DomainException('Regra arquivada não pode ser revisada.');
        }
        $this->db->prepare("UPDATE food_equivalence_rules SET review_status = 'revisada', reviewed_by_user_id = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND clinic_id = ?")
            ->execute([$userId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id, TenantContext::getClinicId()]);
        (new AuditLogger($this->db))->log('FOOD_EQUIVALENCE_RULE_REVIEWED', 'food_equivalence_rules', (string)$id, ['name' => $rule['name']]);
        $this->rulesCache = null;
    }

    public function archive(int $id): void
    {
        $rule = $this->ownRule($id);
        if ($rule['status'] !== 'active') {
            return;
        }
        $this->db->prepare("UPDATE food_equivalence_rules SET status = 'archived', archived_at = ?, updated_at = ? WHERE id = ? AND clinic_id = ?")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id, TenantContext::getClinicId()]);
        (new AuditLogger($this->db))->log('FOOD_EQUIVALENCE_RULE_ARCHIVED', 'food_equivalence_rules', (string)$id, ['name' => $rule['name']]);
        $this->rulesCache = null;
    }

    /** Regra ativa que cobre o grupo (desempata por: revisada primeiro, menor tolerância de energia, menor tolerância de macros e menor id). */
    public function ruleForGroup(?string $group): ?array
    {
        if ($group === null) {
            return null;
        }
        $best = null;
        foreach ($this->activeRules() as $r) {
            if (!in_array($group, $r['food_groups'], true)) {
                continue;
            }
            if ($best === null) {
                $best = $r;
                continue;
            }
            // 1. Regra revisada tem precedência sobre pendente
            $rRev = ($r['review_status'] ?? '') === 'revisada' ? 1 : 0;
            $bRev = ($best['review_status'] ?? '') === 'revisada' ? 1 : 0;
            if ($rRev !== $bRev) {
                if ($rRev > $bRev) {
                    $best = $r;
                }
                continue;
            }
            // 2. Menor tolerância energética
            if ((float)$r['energy_tolerance_pct'] < (float)$best['energy_tolerance_pct']) {
                $best = $r;
                continue;
            }
            if ((float)$r['energy_tolerance_pct'] > (float)$best['energy_tolerance_pct']) {
                continue;
            }
            // 3. Menor tolerância de macronutrientes
            if ((float)$r['macro_tolerance_pct'] < (float)$best['macro_tolerance_pct']) {
                $best = $r;
                continue;
            }
            if ((float)$r['macro_tolerance_pct'] > (float)$best['macro_tolerance_pct']) {
                continue;
            }
            // 4. Menor ID
            if ((int)$r['id'] < (int)$best['id']) {
                $best = $r;
            }
        }
        return $best;
    }

    /**
     * Veredito de uma troca. $item e $sub: category e totais (energy_kcal, protein_g, carbohydrate_g, lipid_g) da
     * quantidade prescrita.
     * @return array{verdict:string, label:string, rule:?array, energy_diff_pct:?float, failed:list<string>, review_pending:bool}
     */
    public function evaluate(array $item, array $sub): array
    {
        $ig = FoodCategory::canonical($item['category'] ?? null);
        $sg = FoodCategory::canonical($sub['category'] ?? null);
        $rule = $this->ruleForGroup($ig);
        $base = ['rule' => $rule, 'energy_diff_pct' => null, 'failed' => [], 'review_pending' => $rule !== null && $rule['review_status'] !== 'revisada', 'item_group' => $ig, 'sub_group' => $sg];
        if ($rule === null) {
            return ['verdict' => 'sem_regra', 'label' => self::VERDICT_LABELS['sem_regra']] + $base;
        }
        if ($sg === null || !in_array($sg, $rule['food_groups'], true)) {
            return ['verdict' => 'grupos_diferentes', 'label' => self::VERDICT_LABELS['grupos_diferentes']] + $base;
        }
        $check = self::withinTolerance($item, $sub, (float)$rule['energy_tolerance_pct'], (float)$rule['macro_tolerance_pct']);
        $verdict = $check['ok'] ? 'equivalente' : 'fora_da_tolerancia';
        return ['verdict' => $verdict, 'label' => self::VERDICT_LABELS[$verdict], 'energy_diff_pct' => $check['energy_diff_pct'], 'failed' => $check['failed']] + $base;
    }

    /** @return array{ok:bool, energy_diff_pct:?float, failed:list<string>} */
    public static function withinTolerance(array $item, array $sub, float $energyTol, float $macroTol): array
    {
        $failed = [];
        $ie = (float)($item['energy_kcal'] ?? 0);
        $se = (float)($sub['energy_kcal'] ?? 0);
        $pct = null;
        if ($ie > 0) {
            $pct = round(abs($se - $ie) / $ie * 100, 1);
            if ($pct > $energyTol + 1e-9) {
                $failed[] = 'energy_kcal';
            }
        } elseif ($se > 0) {
            $failed[] = 'energy_kcal';
        }
        foreach (['protein_g', 'carbohydrate_g', 'lipid_g'] as $k) {
            $a = (float)($item[$k] ?? 0);
            $b = (float)($sub[$k] ?? 0);
            $limit = max($macroTol / 100 * $a, self::MACRO_FLOOR_G);
            if (abs($b - $a) > $limit + 1e-9) {
                $failed[] = $k;
            }
        }
        return ['ok' => $failed === [], 'energy_diff_pct' => $pct, 'failed' => $failed];
    }

    /**
     * Sugestões de troca para um item do plano: alimentos dos grupos da regra (catálogo global atual e da clínica),
     * na quantidade que dá a mesma energia, filtrados pela tolerância. Até $limit, menor diferença de macros primeiro.
     * Sem regra para o grupo do item: lista vazia e o motivo.
     * @return array{rule:?array, reason:?string, suggestions:list<array>}
     */
    public function suggestions(array $item, int $limit = 10): array
    {
        $ig = FoodCategory::canonical($item['category'] ?? null);
        $rule = $this->ruleForGroup($ig);
        if ($rule === null) {
            return ['rule' => null, 'reason' => 'Sem regra de equivalência da clínica para o grupo ' . ($ig ?? 'deste alimento') . '. A comparação mostra só a diferença de nutrientes.', 'suggestions' => []];
        }
        $ie = (float)($item['energy_kcal'] ?? 0);
        if ($ie <= 0) {
            return ['rule' => $rule, 'reason' => 'O item não tem energia calculada; não dá para sugerir quantidade equivalente.', 'suggestions' => []];
        }
        $spell = FoodCategory::spellingsFor($rule['food_groups']);
        $in = implode(',', array_fill(0, count($spell), '?'));
        $current = SchemaInfo::hasColumn($this->db, 'foods', 'is_current') ? ' AND COALESCE(is_current, 1) = 1' : '';
        $st = $this->db->prepare("SELECT id, name, category, energy_kcal, protein_g, carbohydrate_g, lipid_g, source, " . (SchemaInfo::hasColumn($this->db, 'foods', 'source_version') ? 'source_version' : 'NULL AS source_version') . ", clinic_id
            FROM foods WHERE (clinic_id IS NULL OR clinic_id = ?) AND category IN ({$in}) AND id <> ? AND energy_kcal > 0{$current}");
        $st->execute(array_merge([TenantContext::getClinicId()], $spell, [(int)($item['food_id'] ?? 0)]));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $g = SubstitutionComparison::sameEnergyGrams($ie, $f['energy_kcal']);
            if ($g === null) {
                continue;
            }
            $factor = $g / 100;
            $sub = ['energy_kcal' => round((float)$f['energy_kcal'] * $factor, 1), 'protein_g' => round((float)$f['protein_g'] * $factor, 1),
                'carbohydrate_g' => round((float)$f['carbohydrate_g'] * $factor, 1), 'lipid_g' => round((float)$f['lipid_g'] * $factor, 1)];
            $check = self::withinTolerance($item, $sub, (float)$rule['energy_tolerance_pct'], (float)$rule['macro_tolerance_pct']);
            if (!$check['ok']) {
                continue;
            }
            $dist = 0.0;
            foreach (['protein_g', 'carbohydrate_g', 'lipid_g'] as $k) {
                $dist += abs($sub[$k] - (float)($item[$k] ?? 0)) / max((float)($item[$k] ?? 0), self::MACRO_FLOOR_G);
            }
            $out[] = ['food_id' => (int)$f['id'], 'name' => (string)$f['name'], 'group' => FoodCategory::canonical($f['category']), 'grams' => $g, 'energy_kcal_100g' => (float)$f['energy_kcal'],
                'source_label' => $f['clinic_id'] !== null ? 'Cadastro da clínica' : trim((string)$f['source'] . ' ' . (string)($f['source_version'] ?? '')),
                'delta' => SubstitutionComparison::delta($item, $sub), 'delta_label' => SubstitutionComparison::label(SubstitutionComparison::delta($item, $sub)), '_d' => $dist];
        }
        usort($out, static fn($a, $b) => [$a['_d'], $a['name']] <=> [$b['_d'], $b['name']]);
        $out = array_map(static function ($r) { unset($r['_d']); return $r; }, array_slice($out, 0, $limit));
        return ['rule' => $rule, 'reason' => $out === [] ? 'Nenhum alimento dos grupos da regra fica dentro das tolerâncias para esta quantidade.' : null, 'suggestions' => $out];
    }

    private function activeRules(): array
    {
        if ($this->rulesCache === null) {
            $this->rulesCache = TenantContext::hasClinic() ? $this->listRules(false) : [];
        }
        return $this->rulesCache;
    }

    private function ownRule(int $id): array
    {
        $r = $this->find($id);
        if ($r === null) {
            throw new \InvalidArgumentException('Regra não encontrada nesta clínica.');
        }
        return $r;
    }

    private function requireTable(): void
    {
        if (!$this->available()) {
            throw new \DomainException('As regras de equivalência precisam da atualização do banco v43 (migração 63).');
        }
    }

    private static function pct(mixed $v, float $max, string $what): float
    {
        $s = str_replace(',', '.', trim((string)$v));
        if ($s === '' || !is_numeric($s)) {
            throw new \InvalidArgumentException("Tolerância de {$what} precisa ser um número entre 0 e " . (int)$max . '%.');
        }
        $f = (float)$s;
        if ($f < 0 || $f > $max) {
            throw new \InvalidArgumentException("Tolerância de {$what} precisa ficar entre 0 e " . (int)$max . '%.');
        }
        return round($f, 2);
    }

    private static function hydrate(array $r): array
    {
        $g = json_decode((string)($r['food_groups'] ?? '[]'), true);
        $r['food_groups'] = is_array($g) ? array_values(array_filter($g, 'is_string')) : [];
        $r['id'] = (int)$r['id'];
        $r['energy_tolerance_pct'] = (float)$r['energy_tolerance_pct'];
        $r['macro_tolerance_pct'] = (float)$r['macro_tolerance_pct'];
        return $r;
    }
}
