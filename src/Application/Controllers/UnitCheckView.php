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
            . '<div class="unit-check__nutrition" data-unit-nutrition style="margin-bottom: 8px; padding: 6px 10px; background: var(--semantic-success-light, #E3F4F2); border: 1px solid var(--semantic-success-border, #B7DFDC); border-radius: 6px; font-size: 0.85rem; color: var(--semantic-success-text, #0A5F63);" hidden></div>'
            . '<p class="unit-check__measures" data-unit-measures data-testid="unit-measures" hidden></p>'
            . '<p class="unit-check__msg" data-unit-check-msg aria-live="polite">Se a medida não tiver peso cadastrado para o alimento, o cálculo usa um peso padrão (ex.: 1 xícara = 150 g) e você precisa confirmar.</p>'
            . '<label class="unit-check__confirm" for="' . $id . '" data-unit-check-confirm><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"> Confirmo o peso estimado</label>'
            . '</div>';
    }

    /**
     * Unidades oferecidas no seletor. NI2-609 (UX-R3-08): "unidade" e "fatia" saíram da lista geral, porque o peso
     * padrão (100 g / 30 g) era igual para qualquer alimento. Elas aparecem só no grupo "Medidas deste alimento",
     * que o unit-check.js monta com as medidas cadastradas (clínica, base geral ou porção do alimento) e a fonte.
     * Colheres e xícara continuam como peso padrão, sempre com a confirmação explícita.
     */
    public static function unitOptions(): string
    {
        $groups = [
            ['Unidades gerais', 'data-unit-general', ['g' => 'g (gramas)', 'ml' => 'ml (mililitros)']],
            ['Utensílios (peso padrão, pede confirmação)', 'data-unit-utensil', ['colher de sopa' => 'colher de sopa', 'colher de sobremesa' => 'colher de sobremesa',
                'colher de chá' => 'colher de chá', 'xícara' => 'xícara']],
        ];
        $html = '';
        foreach ($groups as [$label, $attr, $opts]) {
            $html .= '<optgroup label="' . htmlspecialchars($label, ENT_QUOTES) . '" ' . $attr . '>';
            foreach ($opts as $v => $l) {
                $html .= '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '">' . htmlspecialchars($l) . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    }
}
