<?php

declare(strict_types=1);

namespace ClyoNutri\Core;

/**
 * Nome do produto exibido para quem usa o sistema (v49: "Clyo Nutri", decisão do Maicon em 31/10/2026).
 * O namespace, o domínio, o banco e os arquivos continuam "clyonutri"/"ClyoNutri".
 * textoExibido() troca a marca antiga em textos que vêm do banco (ex.: aviso de cobertura da política 2026.1),
 * sem alterar o registro guardado.
 */
final class Brand
{
    public const NAME = 'Clyo Nutri';

    public static function textoExibido(string $text): string
    {
        return (string)preg_replace('/\bClyoNutri(?:\s+II)?\b(?!\s+(?:Start|Profissional|Clínica Elite))/u', self::NAME, $text);
    }
}
