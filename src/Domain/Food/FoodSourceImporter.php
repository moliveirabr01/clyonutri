<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Food;

use ClyoNutri\Core\Database\SchemaInfo;
use PDO;
use RuntimeException;

/**
 * Importação de uma base externa de composição de alimentos sem sobrescrever nada (NI2-520, v43).
 *
 * Regras:
 * - cada linha precisa de source_code e nome; nutrientes por 100 g;
 * - mesma fonte, mesma versão e mesmo código já no banco: pula (idempotente);
 * - mesma fonte e código com outra versão: entra como linha nova; a anterior passa a is_current = 0 e mantém os
 *   valores (planos que a usam guardam a composição no item e continuam iguais);
 * - nome igual a um alimento de outra fonte: entra mesmo assim, como linha separada, e vai para 'name_conflicts'
 *   no relatório; o alimento existente não muda;
 * - nunca toca alimentos de clínica (clinic_id NOT NULL) e nunca apaga linhas.
 * Com $apply = false (padrão no script) só conta o que faria.
 */
final class FoodSourceImporter
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function hasSchema(): bool
    {
        return SchemaInfo::hasColumn($this->db, 'foods', 'source_version') && SchemaInfo::hasColumn($this->db, 'foods', 'is_current');
    }

    /**
     * @param list<array{source_code:string,name:string,category:?string,energy_kcal:float,protein_g:float,lipid_g:float,carbohydrate_g:float,fiber_g:float,nutrient_flags:?string}> $rows
     * @return array{source:string,version:string,apply:bool,rows:int,new:int,skipped_same_version:int,new_version:int,superseded:int,invalid:int,name_conflicts:int,conflict_examples:list<array{source_code:string,name:string,existing_source:string}>}
     */
    public function run(string $source, string $version, array $rows, bool $apply = false): array
    {
        if (!$this->hasSchema()) {
            throw new RuntimeException('A importação de bases externas precisa da migração 63 (v43).');
        }
        $source = trim($source);
        $version = trim($version);
        if ($source === '' || mb_strlen($source) > 50 || $version === '' || mb_strlen($version) > 60) {
            throw new RuntimeException('Fonte (até 50 caracteres) e versão (até 60) são obrigatórias.');
        }
        if (strcasecmp($source, 'TACO') === 0) {
            throw new RuntimeException('A TACO tem importador próprio (TacoCatalogImporter).');
        }
        $stats = ['source' => $source, 'version' => $version, 'apply' => $apply, 'rows' => count($rows), 'new' => 0,
            'skipped_same_version' => 0, 'new_version' => 0, 'superseded' => 0, 'invalid' => 0, 'name_conflicts' => 0, 'conflict_examples' => []];

        $same = $this->db->prepare('SELECT 1 FROM foods WHERE source = ? AND source_version = ? AND source_code = ? AND clinic_id IS NULL');
        $older = $this->db->prepare('SELECT COUNT(*) FROM foods WHERE source = ? AND source_code = ? AND source_version <> ? AND clinic_id IS NULL AND COALESCE(is_current, 1) = 1');
        // Nomes das outras fontes, normalizados em PHP (search_text pode estar vazio em linhas antigas e o dry-run não grava).
        $names = [];
        $ns = $this->db->prepare('SELECT name, source FROM foods WHERE clinic_id IS NULL AND source <> ?');
        $ns->execute([$source]);
        foreach ($ns->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $names[FoodSearchIndex::normalize((string)$n['name'])] ??= (string)$n['source'];
        }
        $supersede = $this->db->prepare('UPDATE foods SET is_current = 0 WHERE source = ? AND source_code = ? AND source_version <> ? AND clinic_id IS NULL AND COALESCE(is_current, 1) = 1');
        $ins = $this->db->prepare('INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, lipid_g, carbohydrate_g, fiber_g,
            serving_size_g, serving_unit, is_custom, nutrient_flags, search_text, source_version, source_code, source_imported_at, preparation, is_current)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 100, \'g\', 0, ?, ?, ?, ?, ?, ?, 1)');

        $own = $apply && !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $seen = [];
            foreach ($rows as $r) {
                $code = trim((string)($r['source_code'] ?? ''));
                $name = trim((string)($r['name'] ?? ''));
                if ($code === '' || mb_strlen($code) > 40 || $name === '' || mb_strlen($name) > 255 || isset($seen[$code])) {
                    $stats['invalid']++;
                    continue;
                }
                $seen[$code] = true;
                $same->execute([$source, $version, $code]);
                if ($same->fetchColumn()) {
                    $stats['skipped_same_version']++;
                    continue;
                }
                $older->execute([$source, $code, $version]);
                $prev = (int)$older->fetchColumn();
                $searchText = FoodSearchIndex::searchText($name);
                $other = $names[FoodSearchIndex::normalize($name)] ?? null;
                if ($other !== null) {
                    $stats['name_conflicts']++;
                    if (count($stats['conflict_examples']) < 20) {
                        $stats['conflict_examples'][] = ['source_code' => $code, 'name' => $name, 'existing_source' => (string)$other];
                    }
                }
                if ($prev > 0) {
                    $stats['new_version']++;
                    $stats['superseded'] += $prev;
                } else {
                    $stats['new']++;
                }
                if (!$apply) {
                    continue;
                }
                if ($prev > 0) {
                    $supersede->execute([$source, $code, $version]);
                }
                $num = static fn($v) => round(max(0.0, (float)$v), 2);
                $ins->execute([$name, $r['category'] ?? null, $source, $num($r['energy_kcal'] ?? 0), $num($r['protein_g'] ?? 0),
                    $num($r['lipid_g'] ?? 0), $num($r['carbohydrate_g'] ?? 0), $num($r['fiber_g'] ?? 0),
                    ($r['nutrient_flags'] ?? '') !== '' ? mb_substr((string)$r['nutrient_flags'], 0, 255) : null,
                    $searchText, $version, $code, date('Y-m-d H:i:s'), FoodSearchIndex::preparation($name)]);
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $stats;
    }
}
