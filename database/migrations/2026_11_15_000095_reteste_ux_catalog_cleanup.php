<?php
declare(strict_types=1);

/**
 * v54.63 · Migração 95 (reteste UX v54.62, R-004).
 *
 * 1. Tira da busca a cópia "Frango, peito, sem pele, grelhado" que a v54.62 criava como cadastro interno com selo TACO
 *    (sem nº TACO e com fibra 0). O item do TACO 410 ("Peito de frango grelhado") continua. A cópia não é apagada:
 *    planos que já a usam continuam íntegros (is_current = 0, igual a uma versão antiga de fonte).
 * 2. Marca como estimativa as medidas caseiras semeadas pela v54.62 por inferência de nome (source nulo).
 * 3. Remove essas medidas estimadas de grãos, leguminosas e massas crus (concha/escumadeira são de alimento pronto).
 *    O peso dos itens já prescritos fica gravado no item; nada muda nos planos existentes.
 *
 * Idempotente, roda em SQLite, MariaDB e MySQL 5.7 (sem UPDATE com subconsulta na mesma tabela).
 */
final class RetesteUxCatalogCleanup
{
    public function up(PDO $db): void
    {
        $schema = \ClyoNutri\Core\Database\SchemaInfo::class;
        $estimated = \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::ESTIMATED_SOURCE;

        if ($schema::hasColumn($db, 'foods', 'is_current') && $schema::hasColumn($db, 'foods', 'taco_code')) {
            $taco = $db->query("SELECT id FROM foods WHERE clinic_id IS NULL AND taco_code = 410 LIMIT 1")->fetchColumn();
            if ($taco !== false) {
                $copies = $db->query("SELECT id FROM foods WHERE clinic_id IS NULL AND taco_code IS NULL
                    AND name = 'Frango, peito, sem pele, grelhado' AND COALESCE(is_current, 1) = 1")->fetchAll(PDO::FETCH_COLUMN);
                $hide = $db->prepare('UPDATE foods SET is_current = 0 WHERE id = ?');
                foreach ($copies as $id) {
                    $hide->execute([(int)$id]);
                }
            }
        }

        if (!$schema::hasColumn($db, 'food_household_measures', 'source')) {
            return;
        }
        // Marca só o que a semeadura da v54.62 produziu: medida global sem fonte, criada a partir de 15/11/2026,
        // cujo par (medida, gramas) é exatamente o que a inferência por nome gera para aquele alimento.
        // Medidas curadas antigas (aveia, azeite, arroz do catálogo de referência) não são tocadas.
        $cands = $db->query("SELECT m.id, m.measure_name, m.grams_equivalent, f.name FROM food_household_measures m
            JOIN foods f ON f.id = m.food_id WHERE m.source IS NULL AND m.created_at >= '2026-11-15 00:00:00'")->fetchAll(PDO::FETCH_ASSOC);
        $tag = $db->prepare("UPDATE food_household_measures SET source = ?, source_version = 'v54.62' WHERE id = ?");
        foreach ($cands as $c) {
            $norm = ' ' . \ClyoNutri\Domain\Food\FoodSearchIndex::normalize((string)$c['name']) . ' ';
            foreach (self::inferIgnoringRaw($norm) as [$mName, $grams]) {
                if ($mName === (string)$c['measure_name'] && abs($grams - (float)$c['grams_equivalent']) < 0.001) {
                    $tag->execute([$estimated, (int)$c['id']]);
                    break;
                }
            }
        }

        $rows = $db->prepare('SELECT m.id, f.name FROM food_household_measures m JOIN foods f ON f.id = m.food_id WHERE m.source = ?');
        $rows->execute([$estimated]);
        $del = $db->prepare('DELETE FROM food_household_measures WHERE id = ?');
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $norm = ' ' . \ClyoNutri\Domain\Food\FoodSearchIndex::normalize((string)$r['name']) . ' ';
            if (\ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::inferCommonMeasuresForNormalizedName($norm) === []
                && self::inferIgnoringRaw($norm) !== []) {
                $del->execute([(int)$r['id']]);
            }
        }
    }

    /** O que a v54.62 inferia (sem a regra nova de alimento cru), para reconhecer as linhas que ela gravou. */
    private static function inferIgnoringRaw(string $norm): array
    {
        $cooked = preg_replace('/ (cru|crua|crus|cruas) /', ' ', $norm) ?? $norm;
        return \ClyoNutri\Domain\Nutrition\HouseholdMeasureConverter::inferCommonMeasuresForNormalizedName($cooked);
    }
}
