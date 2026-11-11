<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Food;

use RuntimeException;

/**
 * Leitura do USDA FoodData Central, Foundation Foods, formato CSV (NI2-520, v43).
 *
 * Licença: dados de domínio público, CC0 1.0 (https://fdc.nal.usda.gov/api-guide/). Citação sugerida pelo USDA:
 * "U.S. Department of Agriculture, Agricultural Research Service. FoodData Central, [ano]. fdc.nal.usda.gov".
 * Os nomes vêm em inglês: a importação é opcional (decisão da clínica/produto) e nunca roda sozinha.
 *
 * Só linhas com data_type = foundation_food. Valores por 100 g. Energia: nutriente 1008 (Energy); sem ele,
 * 2048 (Atwater específico) e depois 2047 (Atwater geral), com a origem anotada em nutrient_flags.
 * Gordura: 1004; sem ela, 1085 (Total fat NLEA). Carboidrato: 1005; sem ele, 1050 (por soma). A troca fica anotada.
 * Alimento sem nenhum valor de energia não entra (o sistema não calcula energia que a fonte não publicou); a contagem
 * sai no relatório como sem_energia. Nutriente ausente fica 0 com marcação "em_branco" (mesma convenção da TACO). O grupo do USDA é convertido para
 * o grupo da TACO mais próximo só quando a correspondência é direta; nos demais casos fica o nome original.
 */
final class UsdaFdcImporter
{
    public const SOURCE = 'USDA FDC';
    private const NUTRIENTS = [1003 => 'protein_g', 1004 => 'lipid_g', 1005 => 'carbohydrate_g', 1079 => 'fiber_g',
        1008 => 'energy_1008', 2048 => 'energy_2048', 2047 => 'energy_2047', 1085 => 'lipid_nlea', 1050 => 'carb_soma'];
    /** Código do grupo USDA => grupo TACO. 0100 (Dairy and Egg) é resolvido pelo nome. */
    private const GROUPS = [
        '0400' => 'Gorduras e óleos', '0500' => 'Carnes e derivados', '0700' => 'Carnes e derivados', '1000' => 'Carnes e derivados',
        '1300' => 'Carnes e derivados', '1700' => 'Carnes e derivados', '0900' => 'Frutas e derivados',
        '1100' => 'Verduras, hortaliças e derivados', '1200' => 'Nozes e sementes', '1400' => 'Bebidas (alcoólicas e não alcoólicas)',
        '1410' => 'Bebidas (alcoólicas e não alcoólicas)', '1500' => 'Pescados e frutos do mar', '1600' => 'Leguminosas e derivados',
        '1900' => 'Produtos açucarados', '0800' => 'Cereais e derivados', '2000' => 'Cereais e derivados', '1800' => 'Cereais e derivados',
    ];

    /** Versão a partir do nome da pasta (FoodData_Central_foundation_food_csv_AAAA-MM-DD). */
    public static function versionFromDir(string $dir): string
    {
        if (preg_match('/foundation_food_csv_(\d{4}-\d{2}-\d{2})/', $dir, $m)) {
            return 'Foundation Foods ' . $m[1];
        }
        throw new RuntimeException('Não reconheci a versão pela pasta. Use a pasta original do download (FoodData_Central_foundation_food_csv_AAAA-MM-DD).');
    }

    /** @return list<array<string,mixed>> linhas no formato do FoodSourceImporter (sem as que não têm energia) */
    public static function rows(string $dir): array
    {
        return self::read($dir)['rows'];
    }

    /** @return array{rows:list<array<string,mixed>>,sem_energia:int,sem_energia_exemplos:list<string>} */
    public static function read(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $cats = [];
        foreach (self::csv($dir . '/food_category.csv') as $c) {
            $cats[$c['id']] = ['code' => $c['code'], 'description' => $c['description']];
        }
        $foods = [];
        foreach (self::csv($dir . '/food.csv') as $f) {
            if (($f['data_type'] ?? '') === 'foundation_food') {
                $foods[$f['fdc_id']] = $f;
            }
        }
        if ($foods === []) {
            throw new RuntimeException('Nenhum alimento foundation_food em food.csv.');
        }
        $vals = [];
        foreach (self::csv($dir . '/food_nutrient.csv') as $n) {
            $id = $n['fdc_id'];
            $nid = (int)$n['nutrient_id'];
            if (isset($foods[$id], self::NUTRIENTS[$nid]) && $n['amount'] !== '' && is_numeric($n['amount'])) {
                $vals[$id][self::NUTRIENTS[$nid]] = (float)$n['amount'];
            }
        }
        $out = [];
        $noEnergy = [];
        foreach ($foods as $id => $f) {
            $v = $vals[$id] ?? [];
            $flags = [];
            if (isset($v['energy_1008'])) {
                $energy = $v['energy_1008'];
            } elseif (isset($v['energy_2048'])) {
                $energy = $v['energy_2048'];
                $flags[] = 'energy_kcal=atwater_especifico';
            } elseif (isset($v['energy_2047'])) {
                $energy = $v['energy_2047'];
                $flags[] = 'energy_kcal=atwater_geral';
            } else {
                $noEnergy[] = $id . ' ' . $f['description'];
                continue;
            }
            if (!isset($v['lipid_g']) && isset($v['lipid_nlea'])) {
                $v['lipid_g'] = $v['lipid_nlea'];
                $flags[] = 'lipid_g=gordura_total_nlea';
            }
            if (!isset($v['carbohydrate_g']) && isset($v['carb_soma'])) {
                $v['carbohydrate_g'] = $v['carb_soma'];
                $flags[] = 'carbohydrate_g=por_soma';
            }
            foreach (['protein_g', 'lipid_g', 'carbohydrate_g', 'fiber_g'] as $k) {
                if (!isset($v[$k])) {
                    $flags[] = $k . '=em_branco';
                }
            }
            $cat = $cats[$f['food_category_id']] ?? null;
            $out[] = [
                'source_code' => (string)$id,
                'name' => (string)$f['description'],
                'category' => self::group($cat, (string)$f['description']),
                'energy_kcal' => $energy,
                'protein_g' => $v['protein_g'] ?? 0.0,
                'lipid_g' => $v['lipid_g'] ?? 0.0,
                'carbohydrate_g' => $v['carbohydrate_g'] ?? 0.0,
                'fiber_g' => $v['fiber_g'] ?? 0.0,
                'nutrient_flags' => implode(';', $flags),
            ];
        }
        return ['rows' => $out, 'sem_energia' => count($noEnergy), 'sem_energia_exemplos' => array_slice($noEnergy, 0, 10)];
    }

    private static function group(?array $cat, string $name): ?string
    {
        if ($cat === null) {
            return null;
        }
        if ($cat['code'] === '0100') {
            return preg_match('/\begg/i', $name) ? 'Ovos e derivados' : 'Leite e derivados';
        }
        return self::GROUPS[$cat['code']] ?? mb_substr('USDA: ' . $cat['description'], 0, 100);
    }

    /** @return \Generator<array<string,string>> */
    private static function csv(string $path): \Generator
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('Arquivo não encontrado: ' . basename($path));
        }
        try {
            $head = fgetcsv($h, 0, ',', '"', '');
            if (!is_array($head)) {
                throw new RuntimeException('Arquivo vazio: ' . basename($path));
            }
            while (($l = fgetcsv($h, 0, ',', '"', '')) !== false) {
                if ($l === [null] || count($l) !== count($head)) {
                    continue;
                }
                yield array_combine($head, $l);
            }
        } finally {
            fclose($h);
        }
    }
}
