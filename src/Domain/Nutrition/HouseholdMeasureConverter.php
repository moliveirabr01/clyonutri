<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Food\FoodSearchIndex;
use InvalidArgumentException;
use PDO;

/**
 * Conversão de quantidade + unidade em gramas (NI2-74). Regras em docs/clinical/CONVERSAO_DE_PORCOES_NI2-74.md.
 *
 * Ordem de decisão (a primeira que se aplica vale):
 *   1. massa (g, mg, kg): exata;
 *   2. medida cadastrada para o alimento (da clínica, em clinic_household_measures, e depois a global, em food_household_measures): exata;
 *   3. porção do próprio alimento (foods.serving_unit / serving_size_g, ex.: 1 unidade = 40 g): exata;
 *   4. tabela padrão (ml, colher, xícara, unidade, fatia…): peso genérico, igual para qualquer alimento.
 *      É uma estimativa: só vale com confirmação explícita ($confirmEstimated); sem ela, erro explícito
 *      (UnitConfirmationRequiredException) dizendo o peso que seria usado e as alternativas exatas;
 *   5. unidade desconhecida: erro explícito.
 *
 * Unidades são comparadas sem acento, sem maiúsculas e no singular ("Colheres de Sopa" = "colher de sopa").
 */
class HouseholdMeasureConverter
{
    public const SOURCE_MASS = 'mass';
    public const SOURCE_FOOD_MEASURE = 'food_measure';
    public const SOURCE_FOOD_SERVING = 'food_serving';
    public const SOURCE_GENERIC = 'generic';
    /** R-004 (reteste v54.62): origem das medidas semeadas por inferência de nome (não são dado do TACO). */
    public const ESTIMATED_SOURCE = 'Estimativa ClyoNutri (revisar)';

    /** Massa: conversão exata. */
    private const MASS = ['g' => 1.0, 'grama' => 1.0, 'gr' => 1.0, 'mg' => 0.001, 'miligrama' => 0.001, 'kg' => 1000.0, 'quilo' => 1000.0, 'quilograma' => 1000.0];

    /**
     * Tabela padrão (gramas por 1 unidade), igual para qualquer alimento. Volume supõe densidade da água
     * (1 ml = 1 g). Chave = forma normalizada; 'label' = como a unidade aparece; 'kind' = volume | utensílio | contagem.
     */
    private const STANDARD = [
        'ml' => ['grams' => 1.0, 'label' => 'ml', 'kind' => 'volume'],
        'l' => ['grams' => 1000.0, 'label' => 'l', 'kind' => 'volume'],
        'colher de sopa' => ['grams' => 15.0, 'label' => 'colher de sopa', 'kind' => 'utensílio'],
        'colher de sobremesa' => ['grams' => 10.0, 'label' => 'colher de sobremesa', 'kind' => 'utensílio'],
        'colher de cha' => ['grams' => 5.0, 'label' => 'colher de chá', 'kind' => 'utensílio'],
        'colher de cafe' => ['grams' => 2.0, 'label' => 'colher de café', 'kind' => 'utensílio'],
        'xicara' => ['grams' => 150.0, 'label' => 'xícara (de chá)', 'kind' => 'utensílio'],
        'copo' => ['grams' => 200.0, 'label' => 'copo (americano)', 'kind' => 'utensílio'],
        'concha' => ['grams' => 130.0, 'label' => 'concha (média)', 'kind' => 'utensílio'],
        'fatia' => ['grams' => 30.0, 'label' => 'fatia (média)', 'kind' => 'contagem'],
        'unidade' => ['grams' => 100.0, 'label' => 'unidade (média)', 'kind' => 'contagem'],
        'porcao' => ['grams' => 100.0, 'label' => 'porção', 'kind' => 'contagem'],
    ];

    /** Outras formas de escrever as unidades da tabela (já normalizadas). */
    private const ALIASES = [
        'mililitro' => 'ml', 'litro' => 'l', 'lt' => 'l',
        'colher sopa' => 'colher de sopa', 'colher sobremesa' => 'colher de sobremesa', 'colher cha' => 'colher de cha',
        'colher cafe' => 'colher de cafe', 'xicara de cha' => 'xicara', 'xicara cha' => 'xicara',
        'copo americano' => 'copo', 'concha media' => 'concha', 'fatia media' => 'fatia', 'unidade media' => 'unidade',
        'un' => 'unidade', 'und' => 'unidade',
    ];

    private ?PDO $db;
    /** @var array<string, list<array{name:string, key:string, grams:float, origin:string, source_text:string}>> */
    private array $measureCache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
        if ($this->db !== null) {
            self::ensureCommonMeasures($this->db);
        }
    }

    /** "Colheres de Sopa" → "colher de sopa"; "Xícaras" → "xicara"; "Unidade média" → "unidade media". */
    public static function normalizeUnit(string $unit): string
    {
        $words = array_filter(explode(' ', FoodSearchIndex::normalize($unit)), static fn($w) => $w !== '');
        return implode(' ', array_map(static fn($w) => strlen($w) > 3 ? FoodSearchIndex::singular($w) : $w, $words));
    }

    /**
     * Tabela documentada (para a documentação, a tela e os testes).
     * @return list<array{unit:string, grams:float, kind:string, aliases:list<string>}>
     */
    public static function standardTable(): array
    {
        $rows = [];
        foreach (self::MASS as $k => $g) {
            if (in_array($k, ['g', 'mg', 'kg'], true)) {
                $rows[] = ['unit' => $k, 'grams' => $g, 'kind' => 'massa (exata)', 'aliases' => array_keys(array_filter(self::MASS, static fn($v, $a) => $v === $g && $a !== $k, ARRAY_FILTER_USE_BOTH))];
            }
        }
        foreach (self::STANDARD as $k => $s) {
            $rows[] = ['unit' => $s['label'], 'grams' => $s['grams'], 'kind' => $s['kind'] . ' (estimada)', 'aliases' => array_keys(array_filter(self::ALIASES, static fn($v) => $v === $k))];
        }
        return $rows;
    }

    /** @throws InvalidArgumentException quantidade inválida, unidade desconhecida ou estimativa não confirmada */
    public function convertToGrams(float $amount, string $unit, ?int $foodId = null, bool $confirmEstimated = false): float
    {
        return $this->convertDetailed($amount, $unit, $foodId, null, $confirmEstimated)['grams'];
    }

    /**
     * @param array|null $food linha de foods (name, serving_unit, serving_size_g); se nula e houver banco, é lida pelo id
     * @return array{grams: float, estimated: bool, source: string, grams_per_unit: float, unit_label: string, origin?: string}
     * @throws UnitConfirmationRequiredException peso só pela tabela padrão e sem confirmação
     * @throws InvalidArgumentException quantidade inválida ou unidade desconhecida
     */
    public function convertDetailed(float $amount, string $unit, ?int $foodId = null, ?array $food = null, bool $confirmEstimated = false): array
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException("A quantidade prescrita deve ser maior que zero (recebido: {$amount}).");
        }
        $key = self::normalizeUnit($unit);
        if ($key === '') {
            throw new InvalidArgumentException('Informe a unidade da quantidade (ex.: g, colher de sopa, unidade).');
        }
        $label = trim($unit);
        $result = static fn(float $perUnit, string $source, bool $estimated, string $unitLabel) => [
            'grams' => round($amount * $perUnit, 4), 'estimated' => $estimated, 'source' => $source,
            'grams_per_unit' => $perUnit, 'unit_label' => $unitLabel,
        ];

        // 1. Massa
        if (isset(self::MASS[$key])) {
            return $result(self::MASS[$key], self::SOURCE_MASS, false, $label);
        }

        // 2. Medida cadastrada para o alimento
        foreach ($this->foodMeasures($foodId) as $m) {
            if ($m['key'] === $key) {
                return $result($m['grams'], self::SOURCE_FOOD_MEASURE, false, $m['name']) + ['origin' => $m['origin'], 'source_label' => self::originLabel($m['origin'], $m['source_text'] ?? '')];
            }
        }

        // 3. Porção do próprio alimento (1 serving_unit = serving_size_g)
        if ($food === null && $foodId !== null && $this->db !== null) {
            $st = $this->db->prepare('SELECT name, serving_unit, serving_size_g FROM foods WHERE id = ?');
            $st->execute([$foodId]);
            $food = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $serving = self::foodServing($food);
        if ($serving !== null && $serving['key'] === $key) {
            return $result($serving['grams'], self::SOURCE_FOOD_SERVING, false, $serving['unit']) + ['source_label' => self::originLabel(null)];
        }

        // 4. Tabela padrão: estimativa, só com confirmação
        $std = self::STANDARD[self::ALIASES[$key] ?? $key] ?? null;
        if ($std !== null) {
            $conv = $result($std['grams'], self::SOURCE_GENERIC, true, $std['label']);
            if (!$confirmEstimated) {
                throw new UnitConfirmationRequiredException(self::confirmationMessage($food, $label, $std, $this->alternatives($foodId, $serving)), $conv);
            }
            return $conv;
        }

        throw new InvalidArgumentException("Unidade «{$label}» não reconhecida. Use g, kg, ml, litro, colher de sopa, sobremesa, chá ou café, xícara, copo, concha, fatia, unidade, porção"
            . ($this->alternatives($foodId, $serving) !== '' ? ' ou uma medida cadastrada para o alimento (' . $this->alternatives($foodId, $serving) . ')' : ' ou uma medida cadastrada para o alimento') . '.');
    }

    /**
     * Medidas exatas conhecidas para o alimento (cadastradas + porção do alimento), para a tela oferecer.
     * NI2-609: cada medida traz 'source_label' (de onde vem o peso), mostrado ao lado da medida.
     * @return list<array{name:string, grams:float, source:string, origin?:string, source_label:string}>
     */
    public function exactMeasuresForFood(?int $foodId, ?array $food = null): array
    {
        $out = [];
        foreach ($this->foodMeasures($foodId) as $m) {
            $out[$m['key']] = ['name' => $m['name'], 'grams' => $m['grams'], 'source' => self::SOURCE_FOOD_MEASURE, 'origin' => $m['origin'],
                'source_label' => self::originLabel($m['origin'], $m['source_text'] ?? '')];
        }
        $serving = self::foodServing($food);
        if ($serving !== null && !isset($out[$serving['key']])) {
            $out[$serving['key']] = ['name' => $serving['unit'], 'grams' => $serving['grams'], 'source' => self::SOURCE_FOOD_SERVING,
                'source_label' => 'porção do cadastro do alimento'];
        }
        return array_values($out);
    }

    /** NI2-609: texto da origem do peso ("medida da clínica · fonte: ..."). */
    public static function originLabel(?string $origin, string $sourceText = ''): string
    {
        $sourceText = trim($sourceText);
        return match ($origin) {
            'clinic' => 'medida da clínica' . ($sourceText !== '' ? ' · fonte: ' . $sourceText : ''),
            'global' => $sourceText === self::ESTIMATED_SOURCE
                ? 'estimativa da base geral, confira o peso'
                : 'medida da base geral do sistema' . ($sourceText !== '' ? ' · fonte: ' . $sourceText : ''),
            default => 'porção do cadastro do alimento',
        };
    }

    /** NI2-609: mesma unidade depois de normalizada ("Unidades" = "unidade"). */
    public static function sameUnit(string $a, string $b): bool
    {
        $ka = self::normalizeUnit($a);
        $kb = self::normalizeUnit($b);
        return (self::ALIASES[$ka] ?? $ka) === (self::ALIASES[$kb] ?? $kb);
    }

    /** NI2-609: unidade de contagem da tabela padrão (unidade, fatia, porção), cujo peso varia muito entre alimentos. */
    public static function isGenericCountUnit(string $unit): bool
    {
        $key = self::normalizeUnit($unit);
        $std = self::STANDARD[self::ALIASES[$key] ?? $key] ?? null;
        return $std !== null && $std['kind'] === 'contagem';
    }

    /**
     * NI2-609 (UX-R3-08): "unidade", "fatia" e "porção" só podem ser prescritas quando o alimento tem essa medida
     * cadastrada (da clínica, da base geral ou a porção do próprio alimento). O peso padrão de 100 g por unidade
     * deixou de ser aceito, mesmo com confirmação, em itens novos ou com unidade trocada. Itens já gravados não mudam.
     * @throws InvalidArgumentException
     */
    public function assertCountUnitHasMeasure(string $unit, ?int $foodId, ?array $food = null): void
    {
        if (!self::isGenericCountUnit($unit)) {
            return;
        }
        $key = self::normalizeUnit($unit);
        foreach ($this->foodMeasures($foodId) as $m) {
            if ($m['key'] === $key) {
                return;
            }
        }
        if ($food === null && $foodId !== null && $this->db !== null) {
            $st = $this->db->prepare('SELECT name, serving_unit, serving_size_g FROM foods WHERE id = ?');
            $st->execute([$foodId]);
            $food = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $serving = self::foodServing($food);
        if ($serving !== null && $serving['key'] === $key) {
            return;
        }
        $name = trim((string)($food['name'] ?? ''));
        $alt = $this->alternatives($foodId, $serving);
        throw new InvalidArgumentException(($name !== '' ? "«{$name}» não tem" : 'O alimento não tem') . ' a medida «' . trim($unit) . '» cadastrada. '
            . 'Use gramas' . ($alt !== '' ? " ou uma medida cadastrada ({$alt})" : '')
            . ', ou cadastre a medida, com a fonte, em Medidas caseiras.');
    }

    /** @return array{unit:string, key:string, grams:float}|null porção do alimento quando não é em massa */
    private static function foodServing(?array $food): ?array
    {
        if ($food === null) {
            return null;
        }
        $unit = trim((string)($food['serving_unit'] ?? ''));
        $grams = (float)($food['serving_size_g'] ?? 0);
        $key = self::normalizeUnit($unit);
        if ($key === '' || $grams <= 0 || isset(self::MASS[$key]) || $key === 'ml' || $key === 'l') {
            return null;
        }
        return ['unit' => $unit, 'key' => $key, 'grams' => $grams];
    }

    /**
     * Medidas exatas do alimento: primeiro as cadastradas pela clínica (NI2-514, com fonte), depois as globais.
     * Se a clínica e a base global têm o mesmo nome de medida, vale a da clínica.
     * @return list<array{name:string, key:string, grams:float, origin:string}>
     */
    private function foodMeasures(?int $foodId): array
    {
        if ($foodId === null || $this->db === null) {
            return [];
        }
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : 0;
        $cacheKey = $clinicId . ':' . $foodId;
        if (!isset($this->measureCache[$cacheKey])) {
            $rows = [];
            if ($clinicId > 0) {
                try {
                    $st = $this->db->prepare('SELECT measure_name, grams_equivalent, source FROM clinic_household_measures WHERE clinic_id = ? AND food_id = ? ORDER BY id');
                    $st->execute([$clinicId, $foodId]);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $rows[] = $r + ['origin' => 'clinic'];
                    }
                } catch (\PDOException) {
                    // tabela ainda não criada (migração 61 pendente): segue só com a base global
                }
            }
            // NI2-886: medida removida na revisão não é oferecida; a aprovada mostra fonte e revisor.
            $reviewed = HouseholdMeasureReviewService::ready($this->db);
            $st = $this->db->prepare($reviewed
                ? "SELECT measure_name, grams_equivalent, source, review_status, reviewer_name FROM food_household_measures WHERE food_id = ? AND (review_status IS NULL OR review_status <> 'removed') ORDER BY id"
                : (\ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'food_household_measures', 'source')
                    ? 'SELECT measure_name, grams_equivalent, source FROM food_household_measures WHERE food_id = ? ORDER BY id'
                    : 'SELECT measure_name, grams_equivalent FROM food_household_measures WHERE food_id = ? ORDER BY id'));
            $st->execute([$foodId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (($r['review_status'] ?? null) === 'approved' && trim((string)($r['reviewer_name'] ?? '')) !== '') {
                    $r['source'] = trim((string)$r['source']) . ' · revisada por ' . trim((string)$r['reviewer_name']);
                }
                $rows[] = $r + ['origin' => 'global'];
            }
            $seen = [];
            $out = [];
            foreach ($rows as $r) {
                $key = self::normalizeUnit((string)$r['measure_name']);
                $grams = (float)$r['grams_equivalent'];
                if ($key === '' || $grams <= 0 || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['name' => (string)$r['measure_name'], 'key' => $key, 'grams' => $grams, 'origin' => $r['origin'], 'source_text' => (string)($r['source'] ?? '')];
            }
            $this->measureCache[$cacheKey] = $out;
        }
        return $this->measureCache[$cacheKey];
    }

    private function alternatives(?int $foodId, ?array $serving): string
    {
        $parts = [];
        foreach ($this->foodMeasures($foodId) as $m) {
            $parts[$m['key']] = '1 ' . $m['name'] . ' = ' . self::fmt($m['grams']) . ' g';
        }
        if ($serving !== null && !isset($parts[$serving['key']])) {
            $parts[$serving['key']] = '1 ' . $serving['unit'] . ' = ' . self::fmt($serving['grams']) . ' g';
        }
        return implode('; ', $parts);
    }

    private static function confirmationMessage(?array $food, string $unit, array $std, string $alternatives): string
    {
        $name = trim((string)($food['name'] ?? ''));
        $volume = $std['kind'] === 'volume' ? ' (densidade da água; óleo, mel e outros líquidos pesam diferente)' : '';
        return ($name !== '' ? "Para «{$name}», a" : 'A') . " unidade «{$unit}» não tem peso cadastrado. "
            . 'O cálculo usaria o peso padrão 1 ' . $std['label'] . ' = ' . self::fmt($std['grams']) . " g{$volume}, igual para qualquer alimento. "
            . 'Use gramas' . ($alternatives !== '' ? ", uma medida cadastrada ({$alternatives})" : '')
            . ' ou marque «Confirmo o peso estimado».';
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
    }

    /** @var array<int, int> */
    private static array $seededConnections = [];

    /**
     * NI2-845 (UX-001): garante medidas caseiras padrão (IBGE/TACO/Pinheiro) para os alimentos mais frequentes
     * do catálogo TACO (pão francês, queijos, carnes/frango/peixes, arroz, feijão, frutas, ovos, laticínios,
     * tubérculos, verduras, aveia, azeite, oleaginosas), evitando bloqueio por falta de medida cadastrada.
     */
    public static function ensureCommonMeasures(PDO $db): int
    {
        $connId = spl_object_id($db);
        try {
            $foodCount = (int)$db->query("SELECT COUNT(*) FROM foods")->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
        if (isset(self::$seededConnections[$connId]) && self::$seededConnections[$connId] === $foodCount && $foodCount > 0) {
            return 0;
        }

        try {
            // Reteste UX v54.62 (R-004): não cria mais "Frango, peito, sem pele, grelhado" como cadastro interno.
            // O item do TACO 410 já existe ("Peito de frango grelhado"); a cópia aparecia com selo TACO, sem nº TACO
            // e com fibra 0 em vez de "não analisada". A migration 95 tira da busca a cópia criada pela v54.62.

            $existingCount = (int)$db->query('SELECT COUNT(*) FROM food_household_measures')->fetchColumn();
            if ($existingCount >= 250) {
                return 0;
            }

            $isMysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $insertIgnore = $isMysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
            // R-004: a medida estimada registra a origem, e a tela mostra "estimativa da base geral (confira)".
            $hasSource = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($db, 'food_household_measures', 'source');
            $ins = $hasSource
                ? $db->prepare("{$insertIgnore} INTO food_household_measures (food_id, measure_name, grams_equivalent, source, source_version) VALUES (:fid, :name, :g, '" . self::ESTIMATED_SOURCE . "', 'v54.63')")
                : $db->prepare("{$insertIgnore} INTO food_household_measures (food_id, measure_name, grams_equivalent) VALUES (:fid, :name, :g)");

            $foods = $db->query("SELECT id, name, category, search_text FROM foods WHERE clinic_id IS NULL AND COALESCE(category, '') <> 'TESTE'")->fetchAll(PDO::FETCH_ASSOC);
            $added = 0;
            $ownTx = !$db->inTransaction();
            if ($ownTx) {
                $db->beginTransaction();
            }
            try {
                foreach ($foods as $f) {
                    $fid = (int)$f['id'];
                    $norm = ' ' . FoodSearchIndex::normalize((string)$f['name']) . ' ';
                    $measures = self::inferCommonMeasuresForNormalizedName($norm);
                    foreach ($measures as [$mName, $grams]) {
                        $ins->execute(['fid' => $fid, 'name' => $mName, 'g' => $grams]);
                        $added += $ins->rowCount();
                    }
                }
                if ($ownTx && $db->inTransaction()) {
                    $db->commit();
                }
            } catch (\Throwable $txErr) {
                if ($ownTx && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $txErr;
            }
            self::$seededConnections[$connId] = (int)$db->query("SELECT COUNT(*) FROM foods")->fetchColumn();
            return $added;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{0:string, 1:float}>
     */
    public static function inferCommonMeasuresForNormalizedName(string $norm): array
    {
        // R-004: medida de alimento pronto (concha, escumadeira, pegador, colher de arroz cozido) não serve para o
        // grão, a leguminosa ou a massa crus. Sem medida, o item cru fica em gramas, que é o seguro.
        if (preg_match('/ (cru|crua|crus|cruas) /', $norm)
            && preg_match('/ (arroz|feijao|lentilha|bico|ervilha|macarrao|quinoa|soja) /', $norm)) {
            return [];
        }
        if (str_contains($norm, ' pao ')) {
            if (str_contains($norm, ' queijo ')) {
                return [['unidade', 40.0], ['porção', 80.0]];
            }
            if (str_contains($norm, ' frances ')) {
                return [['unidade', 50.0], ['fatia', 25.0]];
            }
            return [['fatia', 25.0], ['unidade', 50.0]];
        }
        if (str_contains($norm, ' torrada ')) {
            return [['unidade', 10.0], ['fatia', 10.0], ['porção', 30.0]];
        }
        if (str_contains($norm, ' biscoito ') || str_contains($norm, ' bolacha ')) {
            return [['unidade', 7.0], ['porção', 30.0]];
        }
        if (str_contains($norm, ' queijo ')) {
            if (str_contains($norm, ' requeijao ')) {
                return [['colher de sopa', 30.0], ['colher de sobremesa', 15.0], ['porção', 30.0]];
            }
            return [['fatia', 30.0], ['porção', 30.0], ['colher de sopa', 25.0], ['unidade', 30.0]];
        }
        if (str_contains($norm, ' requeijao ')) {
            return [['colher de sopa', 30.0], ['colher de sobremesa', 15.0]];
        }
        if (str_contains($norm, ' arroz ')) {
            return [['colher de sopa', 25.0], ['escumadeira', 85.0], ['concha', 100.0], ['porção', 100.0]];
        }
        if (str_contains($norm, ' feijao ') || str_contains($norm, ' lentilha ') || str_contains($norm, ' bico ') || str_contains($norm, ' ervilha ')) {
            return [['concha', 86.0], ['colher de sopa', 25.0], ['porção', 86.0]];
        }
        if (str_contains($norm, ' aveia ') || str_contains($norm, ' granola ') || str_contains($norm, ' quinoa ') || str_contains($norm, ' farinha ') || str_contains($norm, ' fuba ')) {
            return [['colher de sopa', 15.0], ['xícara', 90.0], ['colher de sobremesa', 10.0], ['porção', 30.0]];
        }
        if (str_contains($norm, ' macarrao ')) {
            return [['pegador', 100.0], ['escumadeira', 90.0], ['porção', 100.0], ['xícara', 100.0]];
        }
        if (str_contains($norm, ' frango ') || str_contains($norm, ' peru ')) {
            if (str_contains($norm, ' coracao ')) {
                return [['unidade', 10.0], ['porção', 80.0], ['colher de sopa', 25.0]];
            }
            return [['filé', 100.0], ['unidade', 100.0], ['porção', 100.0], ['colher de sopa', 25.0]];
        }
        if (str_contains($norm, ' carne ') || str_contains($norm, ' patinho ') || str_contains($norm, ' acem ') || str_contains($norm, ' alcatra ') || str_contains($norm, ' mignon ') || str_contains($norm, ' contra file ') || str_contains($norm, ' porco ') || str_contains($norm, ' bovina ')) {
            return [['bife', 100.0], ['porção', 100.0], ['unidade', 100.0], ['colher de sopa', 25.0], ['fatia', 80.0]];
        }
        if (str_contains($norm, ' salmao ') || str_contains($norm, ' tilapia ') || str_contains($norm, ' merluza ') || str_contains($norm, ' pescada ') || str_contains($norm, ' cacao ') || str_contains($norm, ' bacalhau ') || str_contains($norm, ' peixe ') || str_contains($norm, ' pintado ')) {
            return [['filé', 120.0], ['posta', 120.0], ['unidade', 100.0], ['porção', 100.0]];
        }
        if (str_contains($norm, ' atum ') || str_contains($norm, ' sardinha ')) {
            return [['colher de sopa', 20.0], ['porção', 80.0], ['unidade', 40.0]];
        }
        if (str_contains($norm, ' ovo ')) {
            return [['unidade', 50.0], ['colher de sopa', 25.0], ['porção', 50.0]];
        }
        if (str_contains($norm, ' leite ')) {
            if (str_contains($norm, ' po ')) {
                return [['colher de sopa', 15.0], ['colher de sobremesa', 10.0]];
            }
            return [['copo', 200.0], ['xícara', 200.0], ['colher de sopa', 15.0]];
        }
        if (str_contains($norm, ' iogurte ') || str_contains($norm, ' coalhada ')) {
            return [['unidade', 170.0], ['copo', 200.0], ['xícara', 200.0], ['colher de sopa', 25.0]];
        }
        if (str_contains($norm, ' manteiga ') || str_contains($norm, ' margarina ')) {
            return [['colher de chá', 5.0], ['colher de sopa', 12.0], ['porção', 10.0]];
        }
        if (str_contains($norm, ' azeite ') || str_contains($norm, ' oleo ')) {
            return [['colher de sopa', 13.0], ['colher de sobremesa', 8.0], ['colher de chá', 4.0]];
        }
        if (str_contains($norm, ' mamao ') || str_contains($norm, ' melao ') || str_contains($norm, ' melancia ') || str_contains($norm, ' abacaxi ')) {
            return [['fatia', 100.0], ['xícara', 140.0], ['unidade', 250.0], ['porção', 100.0]];
        }
        if (str_contains($norm, ' abacate ')) {
            return [['colher de sopa', 30.0], ['fatia', 80.0], ['porção', 100.0], ['unidade', 200.0]];
        }
        if (str_contains($norm, ' morango ') || str_contains($norm, ' uva ') || str_contains($norm, ' acerola ') || str_contains($norm, ' ameixa ')) {
            return [['xícara', 120.0], ['porção', 100.0], ['unidade', 15.0]];
        }
        if (str_contains($norm, ' banana ') || str_contains($norm, ' maca ') || str_contains($norm, ' pera ') || str_contains($norm, ' laranja ') || str_contains($norm, ' tangerina ') || str_contains($norm, ' mexerica ') || str_contains($norm, ' manga ') || str_contains($norm, ' goiaba ') || str_contains($norm, ' kiwi ') || str_contains($norm, ' pessego ') || str_contains($norm, ' caqui ') || str_contains($norm, ' limao ') || str_contains($norm, ' maracuja ') || str_contains($norm, ' caju ')) {
            return [['unidade', 100.0], ['fatia', 40.0], ['xícara', 140.0], ['porção', 100.0]];
        }
        if (str_contains($norm, ' batata ') || str_contains($norm, ' mandioca ') || str_contains($norm, ' inhame ') || str_contains($norm, ' cara ') || str_contains($norm, ' abobora ')) {
            return [['colher de sopa', 30.0], ['unidade', 100.0], ['fatia', 60.0], ['porção', 100.0]];
        }
        if (str_contains($norm, ' alface ') || str_contains($norm, ' rucula ') || str_contains($norm, ' agriao ') || str_contains($norm, ' couve ') || str_contains($norm, ' espinafre ') || str_contains($norm, ' repolho ') || str_contains($norm, ' acelga ') || str_contains($norm, ' chicoria ') || str_contains($norm, ' almeirao ')) {
            return [['porção', 50.0], ['colher de sopa', 15.0], ['xícara', 40.0], ['unidade', 10.0]];
        }
        if (str_contains($norm, ' tomate ') || str_contains($norm, ' pepino ') || str_contains($norm, ' cenoura ') || str_contains($norm, ' beterraba ') || str_contains($norm, ' chuchu ') || str_contains($norm, ' abobrinha ') || str_contains($norm, ' berinjela ') || str_contains($norm, ' brocolis ') || str_contains($norm, ' vagem ') || str_contains($norm, ' quiabo ') || str_contains($norm, ' cebola ') || str_contains($norm, ' pimentao ')) {
            return [['colher de sopa', 25.0], ['fatia', 20.0], ['unidade', 100.0], ['xícara', 100.0], ['porção', 80.0]];
        }
        if (str_contains($norm, ' castanha ') || str_contains($norm, ' amendoa ') || str_contains($norm, ' noz ') || str_contains($norm, ' amendoim ')) {
            return [['unidade', 5.0], ['colher de sopa', 15.0], ['porção', 30.0]];
        }
        if (str_contains($norm, ' linhaca ') || str_contains($norm, ' gergelim ') || str_contains($norm, ' chia ')) {
            return [['colher de sopa', 12.0], ['colher de chá', 5.0], ['porção', 12.0]];
        }
        if (str_contains($norm, ' cafe ') || str_contains($norm, ' cha ')) {
            return [['xícara', 100.0], ['copo', 200.0]];
        }
        return [];
    }

    /**
     * NI2-845 (UX-001): sugere quantidade e medida padrão para prescrição rápida do alimento:
     * 1) última medida/quantidade usada pela clínica para este alimento;
     * 2) medida caseira primária do alimento (ex.: 1 unidade, 1 fatia, 4 colheres de sopa, 1 concha);
     * 3) fallback em 100 g.
     *
     * @return array{amount:float, unit:string, grams:float, origin:string}
     */
    public function defaultPrescriptionForFood(int $foodId, ?array $food = null, ?int $clinicId = null): array
    {
        $cid = $clinicId ?? (TenantContext::hasClinic() ? (int)TenantContext::getClinicId() : 0);
        if ($cid > 0 && $this->db !== null && $foodId > 0) {
            try {
                $st = $this->db->prepare('SELECT mi.amount, mi.measure_unit, mi.weight_grams FROM meal_plan_items mi JOIN meal_plan_meals mm ON mm.id = mi.meal_id JOIN meal_plans mp ON mp.id = mm.meal_plan_id WHERE mp.clinic_id = ? AND mi.food_id = ? ORDER BY mi.id DESC LIMIT 1');
                $st->execute([$cid, $foodId]);
                $last = $st->fetch(PDO::FETCH_ASSOC);
                if ($last && (float)$last['amount'] > 0 && trim((string)$last['measure_unit']) !== '') {
                    return [
                        'amount' => round((float)$last['amount'], 2),
                        'unit' => trim((string)$last['measure_unit']),
                        'grams' => round((float)($last['weight_grams'] ?? 100.0), 1),
                        'origin' => 'clinic_history',
                    ];
                }
            } catch (\Throwable) {
                /* NI2-892: sem histórico da clínica a sugestão cai para a medida padrão. */
            }
        }
        $measures = $this->exactMeasuresForFood($foodId, $food);
        if ($measures !== []) {
            $primary = $measures[0];
            $normName = ' ' . FoodSearchIndex::normalize((string)($food['name'] ?? '')) . ' ';
            $defaultAmount = 1.0;
            if (str_contains($normName, ' arroz ') && self::normalizeUnit($primary['name']) === 'colher de sopa') {
                $defaultAmount = 4.0;
            } elseif (str_contains($normName, ' aveia ') && self::normalizeUnit($primary['name']) === 'colher de sopa') {
                $defaultAmount = 2.0;
            }
            return [
                'amount' => $defaultAmount,
                'unit' => (string)$primary['name'],
                'grams' => round($defaultAmount * (float)$primary['grams'], 1),
                'origin' => (string)($primary['origin'] ?? $primary['source'] ?? 'food_measure'),
            ];
        }
        return [
            'amount' => 100.0,
            'unit' => 'g',
            'grams' => 100.0,
            'origin' => 'mass',
        ];
    }
}
