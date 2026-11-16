<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Food;

use PDO;

/**
 * NI2-73: busca de alimentos por termos.
 *
 * Cada alimento guarda `foods.search_text`: o nome sem acento, em minúsculas, com pontuação trocada por espaço
 * e com um espaço no começo e no fim (" arroz integral cozido "). A busca quebra o que foi digitado em termos
 * e exige todos, em qualquer ordem: "arroz integral" encontra "Arroz, integral, cozido". O texto é calculado no
 * PHP (sync()) para qualquer linha com search_text nulo, inclusive as que chegam pelo SQL manual da TACO.
 *
 * Ordem dos resultados (sem pontuação escondida, sempre a mesma regra):
 *   1. nome começa com o termo inteiro como palavra ("maçã" → "Maçã, Argentina…");
 *   2. nome começa com o termo ("maçã" → "Macarrão…" só depois);
 *   3. o primeiro termo começa o nome e todos os termos começam palavras ("leite integral" → "Leite, de vaca, integral");
 *   4. todos os termos começam palavras do nome;
 *   5. os demais; dentro de cada grupo, nome mais curto primeiro e depois ordem alfabética.
 * Desde a v43 os sinônimos contam nos grupos e há desempates de favorito, preparo e uso (ver sql()).
 */
final class FoodSearchIndex
{
    public const MAX_TERMS = 6;
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

    /**
     * NI2-520 (v42): nomes regionais do mesmo alimento. Só sinônimos de nome (linguagem), nenhum dado nutricional.
     * Já no formato de termos (normalizados e no singular). Lista curta de propósito; ampliar com revisão.
     */
    public const SYNONYMS = [
        ['mandioca', 'aipim', 'macaxeira'],
        ['abobora', 'jerimum'],
        ['tangerina', 'mexerica', 'bergamota'],
    ];

    /** Flexões de gênero e grafia equivalentes na busca SQL (sem poluir o rótulo de sinônimo regional). */
    private const MORPH_VARIANTS = [
        ['moido', 'moida'],
        ['cozido', 'cozida'],
        ['grelhado', 'grelhada'],
        ['assado', 'assada'],
        ['frito', 'frita'],
        ['cru', 'crua'],
        ['refogado', 'refogada'],
        ['desfiado', 'desfiada'],
        ['mozarela', 'mussarela', 'mucarela'],
    ];

    /** Conectivos ignorados na quebra em termos quando há palavras principais na busca. */
    private const STOP_WORDS = ['de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'ao', 'aos', 'a', 'e', 'o'];

    /**
     * NI2-846 (UX-002): partes anatômicas / cortes exibidos como destaque rápido no resultado da busca.
     */
    private const PART_HIGHLIGHTS = [
        'coracao' => 'coração',
        'figado' => 'fígado',
        'moela' => 'moela',
        'lingua' => 'língua',
        'miudos' => 'miúdos',
        'miudo' => 'miúdos',
        'bucho' => 'bucho',
        'tripa' => 'tripa',
        'dobradinha' => 'dobradinha',
        'pescoco' => 'pescoço',
        'cabeca' => 'cabeça',
        'rabo' => 'rabo',
        'orelha' => 'orelha',
        'rim' => 'rim',
        'tutano' => 'tutano',
        'peito' => 'peito',
        'file' => 'filé',
        'sobrecoxa' => 'sobrecoxa',
        'coxa' => 'coxa',
        'asa' => 'asa',
        'patinho' => 'patinho',
        'acem' => 'acém',
        'alcatra' => 'alcatra',
        'mignon' => 'filé mignon',
        'musculo' => 'músculo',
        'costela' => 'costela',
        'lombo' => 'lombo',
        'pernil' => 'pernil',
        'clara' => 'clara',
        'gema' => 'gema',
    ];

    /**
     * NI2-846 (UX-002): miúdos, partes secundárias e derivados processados que só sobem ao topo
     * quando o nutricionista digita explicitamente o termo correspondente.
     */
    public const SECONDARY_PARTS = [
        'coracao', 'figado', 'moela', 'lingua', 'miudo', 'miudos', 'bucho', 'tripa',
        'dobradinha', 'pescoco', 'cabeca', 'rabo', 'orelha', 'rim', 'tutano', 'asa',
        'gema', 'linguica', 'coxinha', 'empada', 'pastel', 'salpicao', 'estrogonofe',
        'mortadela', 'salsicha', 'almondega', 'charque', 'caldo', 'milanesa',
        'condensado', 'achocolatado', 'dende', 'pure', 'extrato', 'calda', 'doce',
        'pacoca', 'moleque', 'broto', 'tropeiro', 'baiao',
    ];

    /**
     * NI2-846: retorna o destaque de parte/corte do alimento ("coração", "peito", "coxa", etc.) ou null.
     */
    public static function detectPartHighlight(string $name): ?string
    {
        $words = explode(' ', self::normalize($name));
        foreach ($words as $w) {
            if (isset(self::PART_HIGHLIGHTS[$w])) {
                return self::PART_HIGHLIGHTS[$w];
            }
        }
        return null;
    }

    /** Grafia para mostrar os termos normalizados dos sinônimos (só os que têm acento). */
    private const SYNONYM_DISPLAY = ['abobora' => 'abóbora'];

    /** Palavras de preparo que aparecem no nome dos alimentos do catálogo (rótulo exibido no resultado). */
    private const PREPARATIONS = [
        'cru' => 'Cru', 'crua' => 'Cru', 'cozido' => 'Cozido', 'cozida' => 'Cozido', 'grelhado' => 'Grelhado', 'grelhada' => 'Grelhado',
        'frito' => 'Frito', 'frita' => 'Frito', 'assado' => 'Assado', 'assada' => 'Assado', 'refogado' => 'Refogado', 'refogada' => 'Refogado',
        'torrado' => 'Torrado', 'torrada' => 'Torrado', 'desidratado' => 'Desidratado', 'desidratada' => 'Desidratado',
        'congelado' => 'Congelado', 'congelada' => 'Congelado', 'enlatado' => 'Enlatado', 'enlatada' => 'Enlatado',
        'conserva' => 'Em conserva', 'empanado' => 'Empanado', 'empanada' => 'Empanado', 'ensopado' => 'Ensopado',
        'braseado' => 'Braseado', 'braseada' => 'Braseado', 'vapor' => 'No vapor',
    ];

    /** Forma de preparo lida do nome ("Arroz, integral, cozido" → "Cozido"); null quando o nome não informa. */
    public static function preparation(string $name): ?string
    {
        $found = [];
        foreach (explode(' ', self::normalize($name)) as $w) {
            if (isset(self::PREPARATIONS[$w]) && !in_array(self::PREPARATIONS[$w], $found, true)) {
                $found[] = self::PREPARATIONS[$w];
            }
        }
        return $found === [] ? null : implode(' / ', array_slice($found, 0, 2));
    }

    /** Categoria para exibir: espaços normalizados e primeira letra maiúscula ("  cereais e  derivados" → "Cereais e derivados"). */
    public static function categoryLabel(?string $category): string
    {
        $c = trim(preg_replace('/\s+/u', ' ', (string)$category) ?? '');
        if ($c === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($c, 0, 1, 'UTF-8'), 'UTF-8') . mb_strtolower(mb_substr($c, 1, null, 'UTF-8'), 'UTF-8');
    }

    /** @return list<string> o termo e os sinônimos dele (o próprio termo primeiro). */
    public static function synonymsOf(string $term): array
    {
        foreach (self::SYNONYMS as $group) {
            if (in_array($term, $group, true)) {
                return array_values(array_unique(array_merge([$term], $group)));
            }
        }
        return [$term];
    }

    /** @return list<string> o termo, sinônimos regionais (se habilitado) e flexões morfológicas. */
    public static function matchVariantsOf(string $term, bool $useSynonyms): array
    {
        $base = $useSynonyms ? self::synonymsOf($term) : [$term];
        $out = $base;
        foreach ($base as $b) {
            foreach (self::MORPH_VARIANTS as $group) {
                if (in_array($b, $group, true)) {
                    $out = array_merge($out, $group);
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Texto normalizado, sem espaços nas pontas: "Feijão, carioca (cozido)" → "feijao carioca cozido". */
    public static function normalize(string $text): string
    {
        $t = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
        return trim($t);
    }

    /** Valor guardado em foods.search_text (com espaço nas pontas para achar começo de palavra). */
    public static function searchText(string $name): string
    {
        return ' ' . substr(self::normalize($name), 0, 252) . ' ';
    }

    /**
     * Termos da busca: até 6, sem repetição. Plural simples vira singular ("ovos" → "ovo") para casar com os dois.
     * Preposições ("de", "do", "da") são ignoradas quando há termos substantivos na busca.
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $raw = [];
        foreach (explode(' ', self::normalize($query)) as $t) {
            if ($t === '') {
                continue;
            }
            $raw[] = self::singular($t);
        }
        $nonStop = array_values(array_filter($raw, static fn(string $w): bool => !in_array($w, self::STOP_WORDS, true)));
        $source = $nonStop !== [] ? $nonStop : $raw;
        $out = [];
        foreach ($source as $t) {
            $out[$t] = true;
            if (count($out) >= self::MAX_TERMS) {
                break;
            }
        }
        return array_map('strval', array_keys($out)); // termo numérico ("100") vira chave int no PHP
    }

    /**
     * Singular aproximado de um termo já normalizado (sem acento). A busca é por trecho do nome, então cortar a
     * mais nunca perde resultado ("flor" acha "flores"); só os plurais em -ões/-ães precisam trocar letras.
     *   feijoes/limoes/paes → feijao/limao/pao · integrais → integral · pasteis → pastel · pudins → pudim
     *   nozes/flores → noz/flor · ovos → ovo
     */
    public static function singular(string $t): string
    {
        $n = strlen($t);
        if ($n >= 4 && (str_ends_with($t, 'oes') || str_ends_with($t, 'aes'))) {
            return substr($t, 0, -3) . 'ao';
        }
        if ($n >= 5 && str_ends_with($t, 'ais')) {
            return substr($t, 0, -2) . 'l';
        }
        if ($n >= 5 && (str_ends_with($t, 'eis') || str_ends_with($t, 'ois'))) {
            return substr($t, 0, -2) . 'l';
        }
        if ($n >= 5 && str_ends_with($t, 'ns')) {
            return substr($t, 0, -2) . 'm';
        }
        if ($n >= 5 && preg_match('/[rzs]es$/', $t)) {
            return substr($t, 0, -2);
        }
        if ($n > 3 && str_ends_with($t, 's') && !str_ends_with($t, 'ss')) {
            return substr($t, 0, -1);
        }
        return $t;
    }

    /** Preenche search_text das linhas que ainda não têm (lotes de 500). Retorna quantas atualizou. */
    public static function sync(PDO $db, int $max = 20000): int
    {
        $sel = $db->prepare('SELECT id, name FROM foods WHERE search_text IS NULL LIMIT 500');
        // v43: com a migração 63, o preparo lido do nome vai junto (foods.preparation); sem ela, só search_text.
        $withPrep = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($db, 'foods', 'preparation');
        $upd = $withPrep
            ? $db->prepare('UPDATE foods SET search_text = ?, preparation = COALESCE(preparation, ?) WHERE id = ? AND search_text IS NULL')
            : $db->prepare('UPDATE foods SET search_text = ? WHERE id = ? AND search_text IS NULL');
        $done = 0;
        while ($done < $max) {
            $sel->execute();
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            $sel->closeCursor();
            if (!$rows) {
                break;
            }
            // Um commit por lote (antes: um por linha). No MySQL, 5.000 linhas novas custavam ~5 s no primeiro pedido.
            $own = !$db->inTransaction();
            if ($own) {
                $db->beginTransaction();
            }
            try {
                foreach ($rows as $r) {
                    $upd->execute($withPrep
                        ? [self::searchText((string)$r['name']), self::preparation((string)$r['name']), (int)$r['id']]
                        : [self::searchText((string)$r['name']), (int)$r['id']]);
                }
                if ($own) {
                    $db->commit();
                }
            } catch (\Throwable $e) {
                if ($own && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            $done += count($rows);
        }
        return $done;
    }

    /** Preparos que indicam alimento pronto para consumo; sobem na ordem quando a busca não cita preparo (v43). */
    public const READY_PREPARATIONS = ['cozido', 'cozida', 'grelhado', 'grelhada', 'assado', 'assada', 'refogado', 'refogada', 'vapor', 'ensopado', 'braseado', 'braseada'];

    /** A busca cita uma forma de preparo ("arroz cru", "frango grelhado")? */
    public static function queryHasPreparation(array $terms): bool
    {
        foreach ($terms as $t) {
            if (isset(self::PREPARATIONS[$t]) || isset(self::PREPARATIONS[$t . 'a']) || isset(self::PREPARATIONS[$t . 'o'])) {
                return true;
            }
        }
        return false;
    }

    /** Frases alternativas com sinônimos (o termo digitado primeiro). Limite de 12 para não explodir o SQL. */
    public static function phraseVariants(array $terms, bool $useSynonyms): array
    {
        $variants = [[]];
        foreach ($terms as $t) {
            $alts = $useSynonyms ? self::synonymsOf($t) : [$t];
            $next = [];
            foreach ($variants as $v) {
                foreach ($alts as $a) {
                    $next[] = array_merge($v, [$a]);
                    if (count($next) >= 12) {
                        break 2;
                    }
                }
            }
            $variants = $next;
        }
        return array_map(static fn(array $v): string => implode(' ', $v), $variants);
    }

    /**
     * Pedaços de SQL para WHERE e ORDER BY. A coluna precisa ser search_text (ou alias.search_text).
     *
     * v43 (NI2-520): os sinônimos valem também na ordem. "aipim" procura "mandioca" e "macaxeira" nos mesmos grupos
     * de relevância, então "Mandioca, cozida" (nome começa com o sinônimo) vem antes de "Bolo, pronto, aipim".
     * Depois do grupo entram, nesta ordem: $options['favorite'] (expressão 0/1, favoritos da clínica primeiro),
     * a intenção de preparo (sem preparo na busca, alimento cozido, grelhado ou assado vem antes do cru e do
     * industrializado do mesmo grupo; com preparo na busca o WHERE já exige a palavra), $options['tie_break']
     * (uso pela clínica), tamanho do nome e ordem alfabética.
     *
     * @return array{where:string, order:string, where_params:array<string,string>, order_params:array<string,string>}
     */
    public static function sql(array $terms, string $column = 'search_text', string $nameColumn = 'name', ?PDO $db = null, array $options = []): array
    {
        $useSynonyms = !empty($options['synonyms']);
        $tieBreak = isset($options['tie_break']) && is_string($options['tie_break']) && $options['tie_break'] !== '' ? $options['tie_break'] . ', ' : '';
        $favorite = isset($options['favorite']) && is_string($options['favorite']) && $options['favorite'] !== '' ? $options['favorite'] . ' DESC, ' : '';
        $intentOn = !array_key_exists('preparation_intent', $options) || $options['preparation_intent'];
        // Desempate por tamanho em caracteres: no MySQL LENGTH conta bytes (acento = 2) e a ordem mudava entre bancos.
        $len = $db !== null && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';
        if ($terms === []) {
            return ['where' => '1=1', 'order' => "{$favorite}{$nameColumn} ASC", 'where_params' => [], 'order_params' => []];
        }
        $esc = static fn(string $t): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t);
        $where = [];
        $wp = [];
        $op = [];
        $startsW = [];
        $startsV = [];
        foreach ($terms as $i => $t) {
            $alts = self::matchVariantsOf($t, $useSynonyms);
            $or = [];
            $orW = [];
            $orV = [];
            foreach ($alts as $k => $alt) {
                $key = $k === 0 ? "fs_t{$i}" : "fs_t{$i}s{$k}";
                $or[] = "{$column} LIKE :{$key} ESCAPE '!'";
                $wp[$key] = '%' . $esc($alt) . '%';
                // MySQL não aceita o mesmo nome de parâmetro duas vezes: um nome por uso.
                $orW[] = "{$column} LIKE :fs_w{$i}a{$k} ESCAPE '!'";
                $orV[] = "{$column} LIKE :fs_v{$i}a{$k} ESCAPE '!'";
                $op["fs_w{$i}a{$k}"] = '% ' . $esc($alt) . '%';
                $op["fs_v{$i}a{$k}"] = '% ' . $esc($alt) . '%';
            }
            $where[] = count($or) === 1 ? $or[0] : '(' . implode(' OR ', $or) . ')';
            $startsW[] = count($orW) === 1 ? $orW[0] : '(' . implode(' OR ', $orW) . ')';
            $startsV[] = count($orV) === 1 ? $orV[0] : '(' . implode(' OR ', $orV) . ')';
        }
        $exact = [];
        $prefix = [];
        foreach (self::phraseVariants($terms, $useSynonyms) as $n => $phrase) {
            $exact[] = "{$column} LIKE :fs_exact{$n} ESCAPE '!'";
            $prefix[] = "{$column} LIKE :fs_prefix{$n} ESCAPE '!'";
            $op["fs_exact{$n}"] = ' ' . $esc($phrase) . ' %';
            $op["fs_prefix{$n}"] = ' ' . $esc($phrase) . '%';
        }
        $first = [];
        foreach (self::matchVariantsOf($terms[0], $useSynonyms) as $k => $alt) {
            $first[] = "{$column} LIKE :fs_first{$k} ESCAPE '!'";
            $op["fs_first{$k}"] = ' ' . $esc($alt) . '%';
        }

        // NI2-846 (UX-002): penaliza miúdos/partes secundárias e derivados quando não foram digitados na busca
        $secConds = [];
        $secIdx = 0;
        foreach (self::SECONDARY_PARTS as $secWord) {
            if (!in_array($secWord, $terms, true)) {
                $secConds[] = "{$column} LIKE :fs_sec{$secIdx}";
                $op["fs_sec{$secIdx}"] = '% ' . $secWord . ' %';
                $secIdx++;
            }
        }
        // Quando busca "pao" sem "queijo", "Pão, de queijo" fica abaixo de pães convencionais
        if (in_array('pao', $terms, true) && !in_array('queijo', $terms, true)) {
            $secConds[] = "{$column} LIKE :fs_sec_paoq";
            $op['fs_sec_paoq'] = '% queijo %';
        }
        // Quando busca "leite" sem "coco"/"cabra"/"po", prioriza leite fluido de vaca
        if (in_array('leite', $terms, true) && !in_array('coco', $terms, true) && !in_array('cabra', $terms, true) && !in_array('po', $terms, true)) {
            $secConds[] = "{$column} LIKE :fs_sec_leite_coco";
            $op['fs_sec_leite_coco'] = '% coco %';
            $secConds[] = "{$column} LIKE :fs_sec_leite_cabra";
            $op['fs_sec_leite_cabra'] = '% cabra %';
            $secConds[] = "{$column} LIKE :fs_sec_leite_po";
            $op['fs_sec_leite_po'] = '% po %';
        }
        // Quando busca fruta in natura sem "suco", suco fica depois da fruta
        if (!in_array('suco', $terms, true)) {
            $secConds[] = "{$column} LIKE :fs_sec_suco";
            $op['fs_sec_suco'] = '% suco %';
        }
        // Quando busca carne/ave/peixe sem pedir "pele", prioriza cortes sem pele
        if (!in_array('pele', $terms, true)) {
            $secConds[] = "{$column} LIKE :fs_sec_com_pele";
            $op['fs_sec_com_pele'] = '% com pele %';
        }
        $secondaryPenalty = $secConds !== [] ? 'CASE WHEN ' . self::any($secConds) . ' THEN 1 ELSE 0 END, ' : '';

        // NI2-846 (UX-002): prioridade para cortes/variedades de consumo clínico cotidiano
        $stapleConds = [];
        if (in_array('frango', $terms, true) && !array_intersect($terms, ['coxa', 'sobrecoxa', 'asa', 'coracao', 'figado', 'inteiro', 'caipira'])) {
            $stapleConds[] = "{$column} LIKE '% peito %'";
            $stapleConds[] = "{$column} LIKE '% file %'";
        }
        if (in_array('pao', $terms, true) && !array_intersect($terms, ['queijo', 'aveia', 'soja', 'milho', 'gluten'])) {
            $stapleConds[] = "{$column} LIKE '% frances %'";
            $stapleConds[] = "{$column} LIKE '% trigo forma %'";
        }
        if (in_array('feijao', $terms, true) && !array_intersect($terms, ['preto', 'fradinho', 'jalo', 'roxo', 'rajado', 'rosinha', 'broto'])) {
            $stapleConds[] = "{$column} LIKE '% carioca %'";
            $stapleConds[] = "{$column} LIKE '% preto %'";
        }
        if (in_array('queijo', $terms, true) && !array_intersect($terms, ['prato', 'ricota', 'parmesao', 'petit', 'requeijao', 'tofu'])) {
            $stapleConds[] = "{$column} LIKE '% minas frescal %'";
            $stapleConds[] = "{$column} LIKE '% mozarela %'";
        }
        if (in_array('azeite', $terms, true) && !in_array('dende', $terms, true)) {
            $stapleConds[] = "{$column} LIKE '% oliva %'";
        }
        if (in_array('tomate', $terms, true) && !array_intersect($terms, ['molho', 'extrato', 'pure'])) {
            $stapleConds[] = "{$column} LIKE '% salada %'";
            $stapleConds[] = "{$column} LIKE '% semente cru %'";
        }
        $stapleBoost = $stapleConds !== [] ? 'CASE WHEN ' . self::any($stapleConds) . ' THEN 0 ELSE 1 END, ' : '';

        $groupCase = 'CASE WHEN ' . self::any($exact) . ' THEN 0 WHEN ' . self::any($prefix) . ' THEN 1 WHEN ' . self::any($first)
            . ' AND ' . implode(' AND ', $startsW) . ' THEN 2 WHEN ' . implode(' AND ', $startsV) . ' THEN 3 ELSE 4 END, ';
        $intent = '';
        $skipReadyIntent = (bool)array_intersect($terms, ['pao', 'queijo', 'leite', 'iogurte', 'aveia', 'azeite', 'manteiga', 'banana', 'maca', 'mamao', 'laranja', 'abacate', 'alface', 'tomate']);
        if ($intentOn && !$skipReadyIntent && !self::queryHasPreparation($terms)) {
            $ready = [];
            foreach (self::READY_PREPARATIONS as $k => $w) {
                $ready[] = "{$column} LIKE :fs_ready{$k}";
                $op["fs_ready{$k}"] = '% ' . $w . ' %';
            }
            $intent = 'CASE WHEN ' . self::any($ready) . ' THEN 0 ELSE 1 END, ';
        }
        $order = "{$secondaryPenalty}{$favorite}{$stapleBoost}{$groupCase}{$intent}{$tieBreak}{$len}({$nameColumn}) ASC, {$nameColumn} ASC";
        return ['where' => '(' . implode(' AND ', $where) . ')', 'order' => $order, 'where_params' => $wp, 'order_params' => $op];
    }

    /**
     * v44: termos que só casaram pelo sinônimo, para mostrar ao lado do resultado ("aipim = mandioca").
     * Mesma regra do WHERE de sql(): o termo (ou o sinônimo) aparece como trecho do nome normalizado.
     * @return list<array{typed:string, matched:string}>
     */
    public static function synonymHits(array $terms, string $name): array
    {
        $text = self::searchText($name);
        $hits = [];
        foreach ($terms as $t) {
            $t = (string)$t;
            if ($t === '' || str_contains($text, $t)) {
                continue;
            }
            foreach (self::synonymsOf($t) as $alt) {
                if ($alt !== $t && str_contains($text, $alt)) {
                    $hits[] = ['typed' => $t, 'matched' => $alt];
                    break;
                }
            }
        }
        return $hits;
    }

    /** Texto curto da correspondência por sinônimo ("aipim = mandioca"); null quando o nome tem o termo digitado. */
    public static function synonymLabel(array $terms, string $name): ?string
    {
        $hits = self::synonymHits($terms, $name);
        if ($hits === []) {
            return null;
        }
        $show = static fn(string $t): string => self::SYNONYM_DISPLAY[$t] ?? $t;
        return implode('; ', array_map(static fn(array $h): string => $show($h['typed']) . ' = ' . $show($h['matched']), $hits));
    }

    private static function any(array $conds): string
    {
        return count($conds) === 1 ? $conds[0] : '(' . implode(' OR ', $conds) . ')';
    }
}
