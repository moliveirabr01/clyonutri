<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Food;

/**
 * Grupos canônicos de alimentos (NI2-520, v43).
 *
 * A base tem dois vocabulários: os 15 grupos da TACO 4ª edição (NEPA-UNICAMP, 2011) e os nomes curtos dos 12
 * alimentos de referência antigos ("Cereais", "Carnes e Aves", "Leites e Derivados"...). Este mapa leva os nomes
 * antigos e variações de digitação para o grupo TACO, para exibir e filtrar um grupo só ("Carnes" e
 * "Carnes e Aves" aparecem como "Carnes e derivados"). A coluna foods.category não muda: o mapa é aplicado na
 * leitura, então nada se perde e a categoria original continua visível no cadastro.
 *
 * Categorias da clínica que não batem com nenhum grupo ficam como estão (grupo próprio da clínica).
 */
final class FoodCategory
{
    /** Grupos da TACO 4ª edição, na ordem da tabela. */
    public const TACO_GROUPS = [
        'Cereais e derivados',
        'Verduras, hortaliças e derivados',
        'Frutas e derivados',
        'Gorduras e óleos',
        'Pescados e frutos do mar',
        'Carnes e derivados',
        'Leite e derivados',
        'Bebidas (alcoólicas e não alcoólicas)',
        'Ovos e derivados',
        'Produtos açucarados',
        'Miscelâneas',
        'Outros alimentos industrializados',
        'Alimentos preparados',
        'Leguminosas e derivados',
        'Nozes e sementes',
    ];

    /** Nomes antigos ou variações (normalizados por key()) => grupo TACO. */
    private const ALIASES = [
        'cereais' => 'Cereais e derivados',
        'cereal' => 'Cereais e derivados',
        'cereais e graos' => 'Cereais e derivados',
        'paes e cereais' => 'Cereais e derivados',
        'verduras e hortalicas' => 'Verduras, hortaliças e derivados',
        'verduras' => 'Verduras, hortaliças e derivados',
        'hortalicas' => 'Verduras, hortaliças e derivados',
        'legumes e verduras' => 'Verduras, hortaliças e derivados',
        'frutas' => 'Frutas e derivados',
        'fruta' => 'Frutas e derivados',
        'oleos e gorduras' => 'Gorduras e óleos',
        'gorduras' => 'Gorduras e óleos',
        'oleos' => 'Gorduras e óleos',
        'peixes' => 'Pescados e frutos do mar',
        'pescados' => 'Pescados e frutos do mar',
        'peixes e frutos do mar' => 'Pescados e frutos do mar',
        'carnes' => 'Carnes e derivados',
        'carnes e aves' => 'Carnes e derivados',
        'carne' => 'Carnes e derivados',
        'aves' => 'Carnes e derivados',
        'leites e derivados' => 'Leite e derivados',
        'laticinios' => 'Leite e derivados',
        'leite' => 'Leite e derivados',
        'bebidas' => 'Bebidas (alcoólicas e não alcoólicas)',
        'ovos' => 'Ovos e derivados',
        'ovo' => 'Ovos e derivados',
        'acucares e doces' => 'Produtos açucarados',
        'doces' => 'Produtos açucarados',
        'leguminosas' => 'Leguminosas e derivados',
        'feijoes' => 'Leguminosas e derivados',
        'oleaginosas' => 'Nozes e sementes',
        'castanhas' => 'Nozes e sementes',
        'nozes' => 'Nozes e sementes',
    ];

    /** Grupo canônico; categoria sem correspondência volta aparada; vazia vira null. */
    public static function canonical(?string $category): ?string
    {
        $category = trim((string)$category);
        if ($category === '') {
            return null;
        }
        $k = self::key($category);
        foreach (self::TACO_GROUPS as $g) {
            if (self::key($g) === $k) {
                return $g;
            }
        }
        return self::ALIASES[$k] ?? $category;
    }

    public static function isTacoGroup(?string $group): bool
    {
        return $group !== null && in_array($group, self::TACO_GROUPS, true);
    }

    /** Todas as grafias conhecidas que caem nos grupos dados (para filtrar no SQL por category IN (...)). */
    public static function spellingsFor(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $out[$g] = true;
        }
        // As grafias antigas reais da base (FoodCatalogService::seedTacoReference).
        $known = ['Cereais', 'Leguminosas', 'Carnes e Aves', 'Carnes', 'Ovos', 'Frutas', 'Leites e Derivados', 'Laticínios',
            'Óleos e Gorduras', 'Oleaginosas', 'Verduras e Hortaliças', 'Bebidas', 'Peixes'];
        foreach ($known as $k) {
            if (in_array(self::canonical($k), $groups, true)) {
                $out[$k] = true;
            }
        }
        return array_keys($out);
    }

    private static function key(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }
}
