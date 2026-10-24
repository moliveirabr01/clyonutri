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
 */
final class FoodSearchIndex
{
    public const MAX_TERMS = 6;
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

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
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($query)) as $t) {
            if ($t === '') {
                continue;
            }
            $t = self::singular($t);
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
        $upd = $db->prepare('UPDATE foods SET search_text = ? WHERE id = ? AND search_text IS NULL');
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
                    $upd->execute([self::searchText((string)$r['name']), (int)$r['id']]);
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

    /**
     * Pedaços de SQL para WHERE e ORDER BY. A coluna precisa ser search_text (ou alias.search_text).
     * @return array{where:string, order:string, where_params:array<string,string>, order_params:array<string,string>}
     */
    public static function sql(array $terms, string $column = 'search_text', string $nameColumn = 'name', ?PDO $db = null): array
    {
        // Desempate por tamanho em caracteres: no MySQL LENGTH conta bytes (acento = 2) e a ordem mudava entre bancos.
        $len = $db !== null && $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';
        if ($terms === []) {
            return ['where' => '1=1', 'order' => "{$nameColumn} ASC", 'where_params' => [], 'order_params' => []];
        }
        $esc = static fn(string $t): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $t);
        $where = [];
        $starts = [];
        $wp = [];
        $op = [];
        foreach ($terms as $i => $t) {
            $where[] = "{$column} LIKE :fs_t{$i} ESCAPE '!'";
            $wp["fs_t{$i}"] = '%' . $esc($t) . '%';
            $starts[] = "{$column} LIKE :fs_w{$i} ESCAPE '!'";
            $op["fs_w{$i}"] = '% ' . $esc($t) . '%';
        }
        $phrase = $esc(implode(' ', $terms));
        $op['fs_exact'] = ' ' . $phrase . ' %';
        $op['fs_prefix'] = ' ' . $phrase . '%';
        $op['fs_first'] = ' ' . $esc($terms[0]) . '%';
        $allStart = implode(' AND ', $starts);
        $order = "CASE WHEN {$column} LIKE :fs_exact ESCAPE '!' THEN 0 WHEN {$column} LIKE :fs_prefix ESCAPE '!' THEN 1 WHEN {$column} LIKE :fs_first ESCAPE '!' AND "
            . $allStart . ' THEN 2 WHEN ' . str_replace(':fs_w', ':fs_v', $allStart) . " THEN 3 ELSE 4 END, {$len}({$nameColumn}) ASC, {$nameColumn} ASC";
        foreach ($terms as $i => $t) {
            $op["fs_v{$i}"] = $op["fs_w{$i}"]; // o mesmo valor com outro nome (MySQL não aceita nome repetido)
        }
        return ['where' => '(' . implode(' AND ', $where) . ')', 'order' => $order, 'where_params' => $wp, 'order_params' => $op];
    }
}
