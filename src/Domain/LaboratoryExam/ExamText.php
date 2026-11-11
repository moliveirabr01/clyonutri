<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\LaboratoryExam;

/**
 * Normalização única do módulo de exames (v45): minúsculas, sem acento, espaços simples.
 * Usada dos dois lados de toda comparação (termo buscado x texto do catálogo, nome do exame x lista de
 * procedimentos não laboratoriais), para que "Biópsia", "biopsia" e "BIOPSIA" deem o mesmo resultado.
 */
final class ExamText
{
    private const MAP = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = strtr($t, self::MAP);
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    /** Variante para comparar termos compostos: hífen, barra e pontuação viram espaço. */
    public static function loose(string $text): string
    {
        $t = self::normalize($text);
        $t = (string)preg_replace('/[^a-z0-9]+/', ' ', $t);
        return trim((string)preg_replace('/\s+/', ' ', $t));
    }
}
