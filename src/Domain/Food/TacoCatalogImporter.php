<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Food;

use PDO;
use RuntimeException;

/**
 * Importa a TACO completa (NEPA/UNICAMP, 4ª edição revisada e ampliada, 2011) como alimentos globais.
 *
 * Fonte dos dados: database/seeds/taco/taco_4a_edicao_tabela1.csv (Tabela 1, por 100 g de parte comestível),
 * extraído do PDF oficial e conferido alimento a alimento (ver RELATORIO-TACO-COMPLETA.md).
 * Citação obrigatória: NEPA-UNICAMP. Tabela Brasileira de Composição de Alimentos – TACO. 4. ed. rev. e ampl. Campinas, 2011.
 *
 * Idempotente e conservador:
 * - nunca apaga linhas e nunca toca alimentos de clínica (clinic_id NOT NULL ou is_custom = 1);
 * - os alimentos de referência já existentes (seedTacoReference) são vinculados pelo nome ao número TACO
 *   e só recebem os nutrientes oficiais; nome, porção, medidas e alergênicos ficam como estão;
 * - alimentos cuja energia/macros estão marcados "*" (análises em reavaliação) não são inseridos;
 *   se já existirem vinculados, mantêm seus valores e só recebem a marcação.
 * Semântica: Tr (traço) e NA (não aplicável) => 0; "*" e célula em branco => 0 com marcação em nutrient_flags.
 * v43 (NI2-520): com a migração 63, só atualiza linhas com source = 'TACO' e versão vazia ou igual a VERSION; um
 * alimento de outra base ou de outra versão nunca é sobrescrito. Linhas novas já entram com fonte, versão, código e data.
 */
class TacoCatalogImporter
{
    /** Alimentos de referência que já existiam (FoodCatalogService::seedTacoReference) => número TACO. */
    public const LINKS = [
        'Arroz branco cozido' => 3,
        'Arroz integral cozido' => 1,
        'Feijão carioca cozido' => 561,
        'Peito de frango grelhado' => 410,
        'Ovo de galinha cozido' => 488,
        'Banana prata crua' => 182,
        'Aveia em flocos' => 7,
        'Leite desnatado' => 457,
        'Azeite de oliva extravirgem' => 260,
        'Castanha-do-Pará' => 589,
        'Alface americana crua' => 77,
        'Tomate com semente cru' => 157,
    ];

    public const VERSION = '4ª ed. rev. e ampl. (2011)';

    private PDO $db;
    private string $csv;

    public function __construct(PDO $db, ?string $csvPath = null)
    {
        $this->db = $db;
        $this->csv = $csvPath ?? dirname(__DIR__, 3) . '/database/seeds/taco/taco_4a_edicao_tabela1.csv';
    }

    public function hasSchema(): bool
    {
        try {
            $this->db->query('SELECT taco_code, nutrient_flags FROM foods WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{inserted:int, updated:int, linked:int, skipped:int} */
    public function run(): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'linked' => 0, 'skipped' => 0];
        if (!$this->hasSchema()) {
            return $stats; // migration 34 ainda não aplicada
        }
        $rows = $this->rows();
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $byCode = $this->db->prepare('SELECT id FROM foods WHERE taco_code = :c');
            $find = $this->db->prepare("SELECT MIN(id) FROM foods WHERE clinic_id IS NULL AND is_custom = 0 AND source = 'TACO'
                AND taco_code IS NULL AND name = :n");
            $link = $this->db->prepare('UPDATE foods SET taco_code = :c WHERE id = :id AND clinic_id IS NULL AND taco_code IS NULL');
            foreach (self::LINKS as $name => $code) {
                $byCode->execute(['c' => $code]);
                if ($byCode->fetchColumn() !== false) {
                    continue;
                }
                $find->execute(['n' => $name]);
                $id = $find->fetchColumn();
                if ($id) {
                    $link->execute(['c' => $code, 'id' => $id]);
                    $stats['linked']++;
                }
            }

            $v43 = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'foods', 'source_version');
            $guard = $v43 ? " AND source = 'TACO' AND (source_version IS NULL OR source_version = :ver)" : '';
            $upd = $this->db->prepare("UPDATE foods SET energy_kcal = :e, protein_g = :p, lipid_g = :l, carbohydrate_g = :c,
                fiber_g = :f, nutrient_flags = :fl, category = COALESCE(category, :cat)
                WHERE taco_code = :code AND clinic_id IS NULL AND is_custom = 0{$guard}");
            $flagOnly = $this->db->prepare("UPDATE foods SET nutrient_flags = :fl WHERE taco_code = :code AND clinic_id IS NULL AND is_custom = 0{$guard}");
            $ins = $v43
                ? $this->db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, lipid_g, carbohydrate_g,
                    fiber_g, serving_size_g, serving_unit, is_custom, taco_code, nutrient_flags, source_version, source_code, source_imported_at, preparation, is_current)
                    VALUES (NULL, :n, :cat, 'TACO', :e, :p, :l, :c, :f, 100, 'g', 0, :code, :fl, :ver, :scode, :at, :prep, 1)")
                : $this->db->prepare("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, lipid_g, carbohydrate_g,
                    fiber_g, serving_size_g, serving_unit, is_custom, taco_code, nutrient_flags)
                    VALUES (NULL, :n, :cat, 'TACO', :e, :p, :l, :c, :f, 100, 'g', 0, :code, :fl)");
            $ver = $v43 ? ['ver' => self::VERSION] : [];
            foreach ($rows as $r) {
                $byCode->execute(['c' => $r['taco_code']]);
                $exists = $byCode->fetchColumn() !== false;
                $fl = $r['nutrient_flags'] !== '' ? $r['nutrient_flags'] : null;
                if ($r['import'] !== '1') {
                    if ($exists) {
                        $flagOnly->execute(['fl' => $fl, 'code' => $r['taco_code']] + $ver);
                    }
                    $stats['skipped']++;
                    continue;
                }
                $vals = ['e' => $r['energy_kcal'], 'p' => $r['protein_g'], 'l' => $r['lipid_g'], 'c' => $r['carbohydrate_g'],
                    'f' => $r['fiber_g'], 'fl' => $fl, 'cat' => $r['category'], 'code' => $r['taco_code']];
                if ($exists) {
                    $upd->execute($vals + $ver);
                    $stats['updated']++;
                } else {
                    $extra = $v43 ? $ver + ['scode' => (string)$r['taco_code'], 'at' => date('Y-m-d H:i:s'), 'prep' => FoodSearchIndex::preparation($r['name'])] : [];
                    $ins->execute($vals + ['n' => $r['name']] + $extra);
                    $stats['inserted']++;
                }
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

    /** @return list<array<string,string>> */
    public function rows(): array
    {
        $h = @fopen($this->csv, 'rb');
        if ($h === false) {
            throw new RuntimeException('Arquivo da TACO não encontrado: ' . $this->csv);
        }
        $head = fgetcsv($h, 0, ',', '"', '');
        $out = [];
        while (($l = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($l === [null]) {
                continue;
            }
            $out[] = array_combine($head, $l);
        }
        fclose($h);
        if (count($out) !== 597) {
            throw new RuntimeException('Arquivo da TACO incompleto: ' . count($out) . ' linhas (esperado 597).');
        }
        return $out;
    }
}
