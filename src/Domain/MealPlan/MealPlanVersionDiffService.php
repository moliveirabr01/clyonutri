<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use InvalidArgumentException;
use PDO;

/**
 * NI2-526: Comparação determinística entre duas versões publicadas (snapshots imutáveis)
 * de planos alimentares do mesmo paciente para apoiar a consulta de retorno.
 *
 * Lê estritamente de meal_plan_versions.payload_json — nunca recalcula com base no catálogo
 * vivo (foods), preservando a integridade histórica do que foi efetivamente prescrito.
 */
final class MealPlanVersionDiffService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Lista todas as versões publicadas (vigente + substituídas) do paciente no tenant ativo.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublishedVersions(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT id, meal_plan_id, patient_id, professional_id, version_number,
                   title, daily_energy_kcal, status, published_at, created_at
            FROM meal_plan_versions
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ORDER BY version_number DESC, id DESC
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Compara duas versões publicadas do paciente a partir de seus snapshots congelados.
     */
    public function compareVersions(int $patientId, ?int $versionNumberA = null, ?int $versionNumberB = null): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT *
            FROM meal_plan_versions
            WHERE clinic_id = :clinic_id AND patient_id = :patient_id
            ORDER BY version_number ASC, id ASC
        ");
        $stmt->execute(['clinic_id' => $clinicId, 'patient_id' => $patientId]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($all) < 2) {
            throw new InvalidArgumentException("O paciente precisa ter pelo menos 2 versões publicadas de plano alimentar para realizar a comparação.");
        }

        $byVer = [];
        foreach ($all as $row) {
            $byVer[(int)$row['version_number']] = $row;
        }

        if ($versionNumberA === null || $versionNumberB === null) {
            $versions = array_keys($byVer);
            $count = count($versions);
            $versionNumberA = $versions[$count - 2];
            $versionNumberB = $versions[$count - 1];
        }

        if (!isset($byVer[$versionNumberA]) || !isset($byVer[$versionNumberB])) {
            throw new InvalidArgumentException("Versão selecionada não encontrada para este paciente.");
        }
        if ($versionNumberA === $versionNumberB) {
            throw new InvalidArgumentException("Selecione duas versões diferentes para comparar.");
        }

        $rowA = $byVer[$versionNumberA];
        $rowB = $byVer[$versionNumberB];

        $payloadA = json_decode((string)$rowA['payload_json'], true);
        $payloadB = json_decode((string)$rowB['payload_json'], true);
        if (!is_array($payloadA) || !is_array($payloadB)) {
            throw new InvalidArgumentException("Snapshot da versão selecionada está inválido.");
        }

        $metaA = self::extractVersionMeta($rowA, $payloadA);
        $metaB = self::extractVersionMeta($rowB, $payloadB);

        $nutrientKeys = ['energy_kcal', 'protein_g', 'carbohydrate_g', 'lipid_g', 'fiber_g'];
        $totalsDiff = [];
        foreach ($nutrientKeys as $k) {
            $from = (float)($metaA[$k] ?? 0.0);
            $to = (float)($metaB[$k] ?? 0.0);
            $delta = round($to - $from, 2);
            $totalsDiff[$k] = [
                'from' => $from,
                'to' => $to,
                'delta' => $delta,
                'pct' => $from > 0.0 ? round((($to - $from) / $from) * 100.0, 1) : null,
            ];
        }

        $mealsA = is_array($payloadA['meals'] ?? null) ? $payloadA['meals'] : [];
        $mealsB = is_array($payloadB['meals'] ?? null) ? $payloadB['meals'] : [];

        $indexedA = [];
        foreach ($mealsA as $idx => $mA) {
            $key = self::normalizeKey((string)($mA['name'] ?? "refeicao_{$idx}"));
            if (isset($indexedA[$key])) {
                $key .= '#' . $idx;
            }
            $indexedA[$key] = $mA;
        }

        $indexedB = [];
        foreach ($mealsB as $idx => $mB) {
            $key = self::normalizeKey((string)($mB['name'] ?? "refeicao_{$idx}"));
            if (isset($indexedB[$key])) {
                $key .= '#' . $idx;
            }
            $indexedB[$key] = $mB;
        }

        $allMealKeys = array_values(array_unique(array_merge(array_keys($indexedA), array_keys($indexedB))));
        $mealsDiff = [];
        $summaryCounts = [
            'meals_added' => 0,
            'meals_removed' => 0,
            'meals_modified' => 0,
            'items_added' => 0,
            'items_removed' => 0,
            'items_modified' => 0,
        ];

        $patientChangesList = [];

        foreach ($allMealKeys as $mKey) {
            $mA = $indexedA[$mKey] ?? null;
            $mB = $indexedB[$mKey] ?? null;

            $tA = self::extractMealTotals($mA);
            $tB = self::extractMealTotals($mB);
            $mealName = (string)($mB['name'] ?? $mA['name'] ?? 'Refeição');

            $itemDiff = self::diffMealItems(
                is_array($mA['items'] ?? null) ? $mA['items'] : [],
                is_array($mB['items'] ?? null) ? $mB['items'] : []
            );

            $summaryCounts['items_added'] += count($itemDiff['added']);
            $summaryCounts['items_removed'] += count($itemDiff['removed']);
            $summaryCounts['items_modified'] += count($itemDiff['modified']);

            if ($mA === null) {
                $mealStatus = 'added';
                $summaryCounts['meals_added']++;
            } elseif ($mB === null) {
                $mealStatus = 'removed';
                $summaryCounts['meals_removed']++;
            } elseif (!empty($itemDiff['added']) || !empty($itemDiff['removed']) || !empty($itemDiff['modified']) || abs($tB['energy_kcal'] - $tA['energy_kcal']) >= 0.01) {
                $mealStatus = 'modified';
                $summaryCounts['meals_modified']++;
            } else {
                $mealStatus = 'unchanged';
            }

            foreach ($itemDiff['added'] as $ad) {
                $patientChangesList[] = "{$mealName}: incluído {$ad['food_name']} ({$ad['amount']} {$ad['measure_unit']})";
            }
            foreach ($itemDiff['removed'] as $rm) {
                $patientChangesList[] = "{$mealName}: retirado {$rm['food_name']}";
            }
            foreach ($itemDiff['modified'] as $md) {
                $patientChangesList[] = "{$mealName}: ajustado {$md['food_name']} de {$md['amount_from']} {$md['unit_from']} para {$md['amount_to']} {$md['unit_to']}";
            }

            $mealsDiff[] = [
                'meal_name' => $mealName,
                'time_a' => $mA['time_of_day'] ?? null,
                'time_b' => $mB['time_of_day'] ?? null,
                'status' => $mealStatus,
                'totals_a' => $tA,
                'totals_b' => $tB,
                'delta_kcal' => round($tB['energy_kcal'] - $tA['energy_kcal'], 2),
                'delta_protein_g' => round($tB['protein_g'] - $tA['protein_g'], 2),
                'delta_carbohydrate_g' => round($tB['carbohydrate_g'] - $tA['carbohydrate_g'], 2),
                'delta_lipid_g' => round($tB['lipid_g'] - $tA['lipid_g'], 2),
                'delta_fiber_g' => round($tB['fiber_g'] - $tA['fiber_g'], 2),
                'items_added' => $itemDiff['added'],
                'items_removed' => $itemDiff['removed'],
                'items_modified' => $itemDiff['modified'],
                'items_unchanged' => $itemDiff['unchanged'],
            ];
        }

        $deltaKcalSign = $totalsDiff['energy_kcal']['delta'] > 0 ? '+' : '';
        $safeHeadline = sprintf(
            'Evolução do Plano Alimentar (v%d → v%d): %s → %s kcal (%s%s kcal)',
            $metaA['version_number'],
            $metaB['version_number'],
            number_format($metaA['energy_kcal'], 0, ',', '.'),
            number_format($metaB['energy_kcal'], 0, ',', '.'),
            $deltaKcalSign,
            number_format($totalsDiff['energy_kcal']['delta'], 0, ',', '.')
        );

        return [
            'patient_id' => $patientId,
            'version_a' => $metaA,
            'version_b' => $metaB,
            'totals_diff' => $totalsDiff,
            'meals_diff' => $mealsDiff,
            'summary_counts' => $summaryCounts,
            'patient_safe_summary' => [
                'headline' => $safeHeadline,
                'changes' => $patientChangesList,
                'text' => $safeHeadline . (!empty($patientChangesList) ? "\n- " . implode("\n- ", $patientChangesList) : "\n- Mantida a estrutura de alimentos e porções."),
            ],
        ];
    }

    private static function extractVersionMeta(array $row, array $payload): array
    {
        return [
            'version_number' => (int)$row['version_number'],
            'meal_plan_id' => (int)$row['meal_plan_id'],
            'title' => (string)($payload['title'] ?? $row['title'] ?? ''),
            'status' => (string)$row['status'],
            'published_at' => (string)$row['published_at'],
            'energy_kcal' => round((float)($payload['daily_energy_kcal'] ?? $row['daily_energy_kcal'] ?? 0), 2),
            'protein_g' => round((float)($payload['protein_g'] ?? 0), 2),
            'carbohydrate_g' => round((float)($payload['carbohydrate_g'] ?? 0), 2),
            'lipid_g' => round((float)($payload['lipid_g'] ?? 0), 2),
            'fiber_g' => round((float)($payload['fiber_g'] ?? 0), 2),
            'energy_target' => is_array($payload['energy_target'] ?? null) ? $payload['energy_target'] : null,
        ];
    }

    private static function extractMealTotals(?array $meal): array
    {
        if ($meal === null) {
            return ['energy_kcal' => 0.0, 'protein_g' => 0.0, 'carbohydrate_g' => 0.0, 'lipid_g' => 0.0, 'fiber_g' => 0.0];
        }
        if (is_array($meal['totals'] ?? null)) {
            return [
                'energy_kcal' => round((float)($meal['totals']['energy_kcal'] ?? 0), 2),
                'protein_g' => round((float)($meal['totals']['protein_g'] ?? 0), 2),
                'carbohydrate_g' => round((float)($meal['totals']['carbohydrate_g'] ?? 0), 2),
                'lipid_g' => round((float)($meal['totals']['lipid_g'] ?? 0), 2),
                'fiber_g' => round((float)($meal['totals']['fiber_g'] ?? 0), 2),
            ];
        }
        $kcal = 0.0;
        $prot = 0.0;
        $carb = 0.0;
        $lip = 0.0;
        $fib = 0.0;
        foreach (($meal['items'] ?? []) as $it) {
            $kcal += (float)($it['energy_kcal'] ?? 0);
            $prot += (float)($it['protein_g'] ?? 0);
            $carb += (float)($it['carbohydrate_g'] ?? 0);
            $lip += (float)($it['lipid_g'] ?? 0);
            $fib += (float)($it['fiber_g'] ?? 0);
        }
        return [
            'energy_kcal' => round($kcal, 2),
            'protein_g' => round($prot, 2),
            'carbohydrate_g' => round($carb, 2),
            'lipid_g' => round($lip, 2),
            'fiber_g' => round($fib, 2),
        ];
    }

    /**
     * @param list<array<string, mixed>> $itemsA
     * @param list<array<string, mixed>> $itemsB
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, modified: list<array<string, mixed>>, unchanged: list<array<string, mixed>>}
     */
    private static function diffMealItems(array $itemsA, array $itemsB): array
    {
        $mapA = [];
        foreach ($itemsA as $idx => $it) {
            $k = self::itemKey($it, $idx, $mapA);
            $mapA[$k] = self::sanitizeSnapshotItem($it);
        }
        $mapB = [];
        foreach ($itemsB as $idx => $it) {
            $k = self::itemKey($it, $idx, $mapB);
            $mapB[$k] = self::sanitizeSnapshotItem($it);
        }

        $added = [];
        $removed = [];
        $modified = [];
        $unchanged = [];

        foreach ($mapB as $k => $bItem) {
            if (!isset($mapA[$k])) {
                $added[] = $bItem;
                continue;
            }
            $aItem = $mapA[$k];
            $amountChanged = abs($bItem['amount'] - $aItem['amount']) >= 0.01;
            $unitChanged = mb_strtolower($bItem['measure_unit']) !== mb_strtolower($aItem['measure_unit']);
            $kcalChanged = abs($bItem['energy_kcal'] - $aItem['energy_kcal']) >= 0.05;

            if ($amountChanged || $unitChanged || $kcalChanged) {
                $modified[] = [
                    'food_id' => $bItem['food_id'],
                    'food_name' => $bItem['food_name'],
                    'amount_from' => $aItem['amount'],
                    'unit_from' => $aItem['measure_unit'],
                    'kcal_from' => $aItem['energy_kcal'],
                    'amount_to' => $bItem['amount'],
                    'unit_to' => $bItem['measure_unit'],
                    'kcal_to' => $bItem['energy_kcal'],
                    'delta_kcal' => round($bItem['energy_kcal'] - $aItem['energy_kcal'], 2),
                ];
            } else {
                $unchanged[] = $bItem;
            }
        }

        foreach ($mapA as $k => $aItem) {
            if (!isset($mapB[$k])) {
                $removed[] = $aItem;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'unchanged' => $unchanged,
        ];
    }

    private static function itemKey(array $it, int $idx, array $existing): string
    {
        $foodId = (int)($it['food_id'] ?? 0);
        $base = $foodId > 0 ? 'food:' . $foodId : 'name:' . self::normalizeKey((string)($it['food_name'] ?? "item_{$idx}"));
        $key = $base;
        $occ = 2;
        while (isset($existing[$key])) {
            $key = $base . '#' . $occ++;
        }
        return $key;
    }

    private static function sanitizeSnapshotItem(array $it): array
    {
        return [
            'food_id' => (int)($it['food_id'] ?? 0),
            'food_name' => (string)($it['food_name'] ?? 'Alimento'),
            'amount' => round((float)($it['amount'] ?? 0), 2),
            'measure_unit' => trim((string)($it['measure_unit'] ?? 'g')),
            'energy_kcal' => round((float)($it['energy_kcal'] ?? 0), 2),
            'protein_g' => round((float)($it['protein_g'] ?? 0), 2),
            'carbohydrate_g' => round((float)($it['carbohydrate_g'] ?? 0), 2),
            'lipid_g' => round((float)($it['lipid_g'] ?? 0), 2),
            'fiber_g' => round((float)($it['fiber_g'] ?? 0), 2),
        ];
    }

    private static function normalizeKey(string $text): string
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');
        $noAccents = strtr($lower, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return trim((string)preg_replace('/\s+/', ' ', $noAccents));
    }
}
