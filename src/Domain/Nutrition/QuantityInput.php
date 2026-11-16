<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Nutrition;

/**
 * Reteste UX v54.62 (R-001): quantidade de alimento digitada em pt-BR.
 *
 * Aceita "1,5", "1.5", "0,25", "1.000,5" e "1000". Antes, `(float)"1,5"` virava 1
 * no servidor e `input type=number` transformava "1,5" em 15 no navegador.
 * Devolve null quando o texto não é um número inteiro ou decimal simples.
 */
final class QuantityInput
{
    /** Acima disso o item pede conferência (não bloqueia). */
    public const PLAUSIBLE_MAX_GRAMS = 1000.0;
    public const PLAUSIBLE_MAX_KCAL = 1000.0;

    public static function parse(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return is_finite((float)$raw) ? (float)$raw : null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $s = trim(str_replace(["\u{00A0}", ' '], '', $raw));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s)) {      // 1.000,5
            $s = str_replace('.', '', $s);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+\.\d+$/', $s)) { // 1,000.5
            $s = str_replace(',', '', $s);
        }
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d+(\.\d+)?$/', $s) && !preg_match('/^\.\d+$/', $s)) {
            return null;
        }
        $v = (float)$s;
        return is_finite($v) ? $v : null;
    }

    /** Mensagem de conferência quando o item parece grande demais para uma refeição; null quando está ok. */
    public static function plausibilityWarning(string $foodName, float $amount, string $unit, ?float $grams, ?float $kcal): ?string
    {
        $tooHeavy = $grams !== null && $grams > self::PLAUSIBLE_MAX_GRAMS;
        $tooCaloric = $kcal !== null && $kcal > self::PLAUSIBLE_MAX_KCAL;
        if (!$tooHeavy && !$tooCaloric) {
            return null;
        }
        $parts = [];
        if ($grams !== null) {
            $parts[] = '≈ ' . number_format($grams, 0, ',', '.') . ' g';
        }
        if ($kcal !== null) {
            $parts[] = number_format($kcal, 0, ',', '.') . ' kcal';
        }
        return sprintf(
            'Confira a quantidade: %s ficou com %s %s (%s). Se foi engano de vírgula, edite a porção.',
            $foodName,
            self::format($amount),
            self::unitLabel($amount, $unit),
            implode(', ', $parts)
        );
    }

    /**
     * UX-013 (reteste v54.62): "2 unidades", "4 colheres de sopa", "1,5 xícara".
     * Plural a partir de 2, como se fala na prescrição. Gramas, ml e medidas com peso entre parênteses ficam como estão.
     */
    public static function unitLabel(float $amount, string $unit): string
    {
        $unit = trim($unit);
        if ($amount < 2 || $unit === '' || preg_match('/^(g|ml|kg|l|mg|mcg|gramas?|mililitros?)$/i', $unit)) {
            return $unit;
        }
        $suffix = '';
        if (preg_match('/^(.*?)(\s*\(.*\))$/u', $unit, $m)) {
            [$unit, $suffix] = [$m[1], $m[2]];
        }
        $irregular = ['colher' => 'colheres', 'porção' => 'porções', 'pão' => 'pães', 'filé' => 'filés', 'pires' => 'pires',
            'lata' => 'latas', 'copo' => 'copos', 'xícara' => 'xícaras', 'unidade' => 'unidades', 'fatia' => 'fatias',
            'concha' => 'conchas', 'escumadeira' => 'escumadeiras', 'pedaço' => 'pedaços', 'pegador' => 'pegadores',
            'punhado' => 'punhados', 'ramo' => 'ramos', 'folha' => 'folhas', 'dente' => 'dentes', 'cubo' => 'cubos',
            'gomo' => 'gomos', 'bola' => 'bolas', 'pote' => 'potes', 'sachê' => 'sachês', 'medida' => 'medidas',
            'scoop' => 'scoops', 'taça' => 'taças', 'prato' => 'pratos', 'garfada' => 'garfadas', 'rodela' => 'rodelas',
            'tablete' => 'tabletes', 'caixinha' => 'caixinhas', 'embalagem' => 'embalagens', 'colherada' => 'colheradas'];
        $words = explode(' ', $unit, 2);
        $first = mb_strtolower($words[0]);
        // Só o caso seguro: "colher de sopa", "fatia", "unidade". Com adjetivo ("fatia média") a frase fica como está.
        if (!isset($irregular[$first]) || (isset($words[1]) && !preg_match('/^(de|do|da|dos|das)\b/u', $words[1]))) {
            return $unit . $suffix;
        }
        return $irregular[$first] . (isset($words[1]) ? ' ' . $words[1] : '') . $suffix;
    }

    /** "1,5", "100", "0,25": sem separador de milhar, para não reabrir a ambiguidade "1.000" = 1 ou 1000. */
    public static function format(float $v): string
    {
        $s = number_format($v, 2, ',', '');
        return rtrim(rtrim($s, '0'), ',');
    }
}
