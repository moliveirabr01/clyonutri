<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Clinical;

use InvalidArgumentException;

/**
 * NI2-220 (v54.25): dado clínico recusado antes de qualquer gravação. Leva os erros por campo
 * (nome do campo => mensagem em português) para a tela mostrar cada um ao lado do campo.
 */
final class ClinicalInputException extends InvalidArgumentException
{
    /** @param array<string,string> $fieldErrors */
    public function __construct(private array $fieldErrors)
    {
        parent::__construct(count($fieldErrors) === 1
            ? (string)reset($fieldErrors)
            : 'Revise os campos destacados: ' . implode(' ', $fieldErrors));
    }

    /** @return array<string,string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * Número em faixa fechada, aceitando vírgula decimal. Vazio vira null (campo opcional).
     * Fora da faixa ou não numérico: registra o erro e devolve null; nunca corta o valor para caber.
     *
     * @param array<string,string> $errors
     */
    public static function number(mixed $raw, string $field, string $label, float $min, float $max, string $unit, array &$errors, bool $integer = false): ?float
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_scalar($raw) || is_bool($raw)) {
            $errors[$field] = "{$label}: valor inválido.";
            return null;
        }
        $s = str_replace(',', '.', trim((string)$raw));
        if (!is_numeric($s) || !is_finite((float)$s)) {
            $errors[$field] = "{$label}: informe um número.";
            return null;
        }
        $v = (float)$s;
        $fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',');
        if ($integer && floor($v) !== $v) {
            $errors[$field] = "{$label}: informe um número inteiro.";
            return null;
        }
        if ($v < $min || $v > $max) {
            $errors[$field] = "{$label}: informe um valor entre {$fmt($min)} e {$fmt($max)} {$unit}.";
            return null;
        }
        return $v;
    }
}
