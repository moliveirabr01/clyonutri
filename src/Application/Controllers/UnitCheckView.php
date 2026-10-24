<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

/**
 * NI2-74: bloco "peso da medida" ao lado da unidade. O unit-check.js mostra o peso que será usado e, quando a
 * medida não tem peso cadastrado para o alimento, exige marcar a confirmação. Sem JavaScript o texto e a caixa
 * ficam visíveis e o servidor faz a mesma verificação.
 */
final class UnitCheckView
{
    public static function block(string $id, string $name = 'confirm_estimated'): string
    {
        $id = htmlspecialchars($id, ENT_QUOTES);
        $name = htmlspecialchars($name, ENT_QUOTES);
        return '<div class="unit-check" data-unit-check>'
            . '<p class="unit-check__msg" data-unit-check-msg aria-live="polite">Se a medida não tiver peso cadastrado para o alimento, o cálculo usa um peso padrão (ex.: 1 xícara = 150 g) e você precisa confirmar.</p>'
            . '<label class="unit-check__confirm" for="' . $id . '" data-unit-check-confirm><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"> Confirmo o peso estimado</label>'
            . '</div>';
    }

    /** Unidades oferecidas no seletor (as medidas do alimento entram por JavaScript no grupo "Deste alimento"). */
    public static function unitOptions(): string
    {
        $opts = ['g' => 'g (gramas)', 'ml' => 'ml (mililitros)', 'colher de sopa' => 'colher de sopa', 'colher de sobremesa' => 'colher de sobremesa',
            'colher de chá' => 'colher de chá', 'xícara' => 'xícara', 'unidade' => 'unidade', 'fatia' => 'fatia'];
        $html = '<optgroup label="Unidades gerais" data-unit-general>';
        foreach ($opts as $v => $l) {
            $html .= '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '">' . htmlspecialchars($l) . '</option>';
        }
        return $html . '</optgroup>';
    }
}
