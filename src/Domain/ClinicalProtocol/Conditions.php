<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\ClinicalProtocol;

use InvalidArgumentException;

/**
 * NI2-635: condições de exibição e de alerta.
 *  {"question": "k", "equals": "sim"}       {"question": "k", "in": ["a","b"]}
 *  {"question": "k", "includes": "x"}       (multi)
 *  {"question": "k", "gt"|"gte"|"lt"|"lte": 7}
 *  {"question": "k", "answered": true}
 *  {"all": [ … ]}  {"any": [ … ]}
 * Pergunta não respondida nunca satisfaz comparação (sem palpite).
 */
final class Conditions
{
    private const OPS = ['equals', 'in', 'includes', 'gt', 'gte', 'lt', 'lte', 'answered'];

    public static function assertValid(array $c, int $depth = 0): void
    {
        if ($depth > 5) {
            throw new InvalidArgumentException('condição aninhada demais');
        }
        if (isset($c['all']) || isset($c['any'])) {
            $list = $c['all'] ?? $c['any'];
            if (!is_array($list) || $list === []) {
                throw new InvalidArgumentException('all/any precisa de uma lista');
            }
            foreach ($list as $sub) {
                self::assertValid((array)$sub, $depth + 1);
            }
            return;
        }
        if (!isset($c['question']) || !is_string($c['question'])) {
            throw new InvalidArgumentException('condição sem question');
        }
        $ops = array_values(array_intersect(array_keys($c), self::OPS));
        if (count($ops) !== 1) {
            throw new InvalidArgumentException('condição precisa de exatamente um operador (' . implode(', ', self::OPS) . ')');
        }
        if (in_array($ops[0], ['gt', 'gte', 'lt', 'lte'], true) && !is_numeric($c[$ops[0]])) {
            throw new InvalidArgumentException('comparação numérica com valor não numérico');
        }
    }

    /** @return list<string> */
    public static function referencedKeys(array $c): array
    {
        if (isset($c['all']) || isset($c['any'])) {
            $out = [];
            foreach ((array)($c['all'] ?? $c['any']) as $sub) {
                $out = array_merge($out, self::referencedKeys((array)$sub));
            }
            return array_values(array_unique($out));
        }
        return isset($c['question']) ? [(string)$c['question']] : [];
    }

    /** @param callable(string): mixed $answerOf  devolve null quando não respondida */
    public static function holds(array $c, callable $answerOf): bool
    {
        if (isset($c['all'])) {
            foreach ($c['all'] as $sub) {
                if (!self::holds((array)$sub, $answerOf)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($c['any'])) {
            foreach ($c['any'] as $sub) {
                if (self::holds((array)$sub, $answerOf)) {
                    return true;
                }
            }
            return false;
        }
        $a = $answerOf((string)$c['question']);
        $empty = $a === null || $a === '' || $a === [];
        if (array_key_exists('answered', $c)) {
            return $c['answered'] ? !$empty : $empty;
        }
        if ($empty) {
            return false;
        }
        if (array_key_exists('equals', $c)) {
            return (string)$a === (string)$c['equals'];
        }
        if (array_key_exists('in', $c)) {
            return in_array((string)$a, array_map('strval', (array)$c['in']), true);
        }
        if (array_key_exists('includes', $c)) {
            return in_array((string)$c['includes'], array_map('strval', (array)$a), true);
        }
        if (!is_numeric($a)) {
            return false;
        }
        $n = (float)$a;
        foreach (['gt' => fn($v) => $n > $v, 'gte' => fn($v) => $n >= $v, 'lt' => fn($v) => $n < $v, 'lte' => fn($v) => $n <= $v] as $op => $fn) {
            if (array_key_exists($op, $c)) {
                return $fn((float)$c[$op]);
            }
        }
        return false;
    }
}
