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
            'global' => 'medida da base geral do sistema',
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
            $st = $this->db->prepare('SELECT measure_name, grams_equivalent FROM food_household_measures WHERE food_id = ? ORDER BY id');
            $st->execute([$foodId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
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
}
