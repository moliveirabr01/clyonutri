<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\TenantGuard;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Plano alimentar para impressão (documento entregue ao paciente).
 *
 * - Plano publicado (ou substituído): usa o snapshot imutável da publicação
 *   (meal_plan_versions), ou seja, exatamente o que o paciente recebeu.
 * - Rascunho: usa os dados atuais e o documento sai marcado "não publicado".
 * - Valores nutricionais (kcal e macros) são opcionais e saem desligados por
 *   padrão: muitos nutricionistas não entregam números ao paciente.
 * - Cada impressão gera evento de auditoria.
 */
class MealPlanPrintService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function build(int $planId, int $userId, bool $showNutrition): array
    {
        $clinicId = TenantContext::getClinicId();
        TenantGuard::assertMealPlanBelongsToClinic($planId, $clinicId, $this->db);

        $current = (new MealPlanService($this->db))->getPlanWithDetails($planId);
        if ($current === null) {
            throw new \InvalidArgumentException('Plano alimentar não encontrado.');
        }

        $isDraft = ($current['status'] ?? '') === 'draft';
        $plan = $current;
        $versionNumber = null;
        $publishedAt = null;
        if (!$isDraft) {
            $stmt = $this->db->prepare('
                SELECT version_number, payload_json, published_at FROM meal_plan_versions
                WHERE meal_plan_id = :id AND clinic_id = :c ORDER BY version_number DESC LIMIT 1
            ');
            $stmt->execute(['id' => $planId, 'c' => $clinicId]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($version) {
                $snapshot = json_decode((string)$version['payload_json'], true);
                if (is_array($snapshot) && isset($snapshot['meals'])) {
                    $plan = $snapshot;
                }
                $versionNumber = (int)$version['version_number'];
                $publishedAt = (string)$version['published_at'];
            }
        }

        $patient = $this->fetchOne('SELECT id, name, birth_date FROM patients WHERE id = ? AND clinic_id = ?', [(int)$current['patient_id'], $clinicId]);
        $clinic = $this->fetchOne('SELECT name, email FROM clinics WHERE id = ?', [$clinicId]);
        // Assinatura: o profissional autor do plano (é dele a prescrição).
        $author = $this->fetchOne('
            SELECT u.name, p.crn, p.crn_region FROM professionals p JOIN users u ON u.id = p.user_id WHERE p.id = ?',
            [(int)$current['professional_id']]) ?? ['name' => (string)($current['professional_name'] ?? ''), 'crn' => null, 'crn_region' => null];
        $printer = $this->fetchOne('SELECT name FROM users WHERE id = ?', [$userId]) ?? ['name' => 'Usuário'];

        $meals = [];
        foreach ($plan['meals'] ?? [] as $meal) {
            $items = [];
            foreach ($meal['items'] ?? [] as $item) {
                $subs = [];
                foreach ($item['substitutions'] ?? [] as $sub) {
                    $subs[] = [
                        'food' => (string)$sub['food_name'],
                        'quantity' => $this->quantity(
                            $sub['amount'] ?? null,
                            $sub['measure_unit'] ?? null,
                            isset($sub['weight_grams']) && $sub['weight_grams'] !== null ? (float)$sub['weight_grams'] : null,
                            !empty($sub['weight_is_estimated'])
                        ),
                        'notes' => trim((string)($sub['notes'] ?? '')),
                    ];
                }
                $items[] = [
                    'food' => (string)$item['food_name'],
                    'quantity' => $this->quantity(
                        $item['amount'] ?? null,
                        $item['measure_unit'] ?? null,
                        isset($item['weight_grams']) && $item['weight_grams'] !== null ? (float)$item['weight_grams'] : null,
                        !empty($item['weight_is_estimated'])
                    ),
                    'notes' => trim((string)($item['notes'] ?? '')),
                    'energy_kcal' => (float)($item['energy_kcal'] ?? 0),
                    'substitutions' => $subs,
                ];
            }
            $meals[] = [
                'name' => (string)$meal['name'],
                'time' => $this->time($meal['time_of_day'] ?? null),
                'notes' => trim((string)($meal['notes'] ?? '')),
                'items' => $items,
                'totals' => $meal['totals'] ?? null,
            ];
        }

        $tz = new DateTimeZone(date_default_timezone_get());
        $this->audit($clinicId, $userId, $planId, (int)$current['patient_id'], [
            'status' => $current['status'],
            'version_number' => $versionNumber,
            'from_snapshot' => !$isDraft && $versionNumber !== null,
            'show_nutrition' => $showNutrition,
        ]);

        return [
            'clinic' => $clinic,
            'patient' => $patient,
            'author' => $author,
            'printer' => $printer,
            'plan' => [
                'id' => $planId,
                'title' => (string)($plan['title'] ?? $current['title']),
                'status' => (string)$current['status'],
                'is_draft' => $isDraft,
                'version_number' => $versionNumber,
                'published_at_local' => $publishedAt ? (new DateTimeImmutable($publishedAt, $tz))->format('d/m/Y') : null,
                'notes' => trim((string)($plan['notes'] ?? '')),
                'totals' => [
                    'energy_kcal' => (float)($plan['daily_energy_kcal'] ?? 0),
                    'protein_g' => (float)($plan['protein_g'] ?? 0),
                    'carbohydrate_g' => (float)($plan['carbohydrate_g'] ?? 0),
                    'lipid_g' => (float)($plan['lipid_g'] ?? 0),
                    'fiber_g' => (float)($plan['fiber_g'] ?? 0),
                ],
            ],
            'meals' => $meals,
            'show_nutrition' => $showNutrition,
            'printed_at_local' => (new DateTimeImmutable('now', $tz))->format('d/m/Y \à\s H:i'),
        ];
    }

    /**
     * Quantidade como prescrita + peso em gramas gravado no cálculo (o mesmo usado nas calorias).
     * Medida caseira genérica (não cadastrada para o alimento) aparece como "peso estimado".
     */
    private function quantity(mixed $amount, ?string $unit, ?float $weightGrams = null, bool $estimated = false): string
    {
        if ($amount === null || $amount === '' || (float)$amount <= 0) {
            return trim((string)$unit);
        }
        $n = (float)$amount;
        $formatted = fmod($n, 1.0) === 0.0 ? number_format($n, 0, ',', '.') : rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
        $unitStr = trim((string)$unit);
        $out = trim($formatted . ' ' . $unitStr);
        if ((bool)preg_match('/^(g|gramas?)$/i', $unitStr)) {
            return $out;
        }
        if ($weightGrams !== null && $weightGrams > 0) {
            $out .= ' (~' . number_format(round($weightGrams), 0, ',', '.') . 'g' . ($estimated ? ', peso estimado' : '') . ')';
        } elseif (preg_match('/\((\d+(?:[.,]\d+)?)\s*g\)/i', $unitStr, $m) && $n != 1.0) {
            // Planos publicados antes do peso persistido: usa o peso unitário escrito na própria medida.
            $out .= ' (~' . number_format(round($n * (float)str_replace(',', '.', $m[1])), 0, ',', '.') . 'g)';
        }
        return $out;
    }

    private function time(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        return preg_match('/^(\d{1,2}):(\d{2})/', $value, $m) ? sprintf('%02d:%s', (int)$m[1], $m[2]) : $value;
    }

    private function audit(int $clinicId, int $userId, int $planId, int $patientId, array $meta): void
    {
        $this->db->prepare("
            INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details)
            VALUES (:c, :u, 'MEAL_PLAN_PRINTED', 'meal_plans', :r, :d)
        ")->execute([
            'c' => $clinicId,
            'u' => $userId,
            'r' => (string)$planId,
            'd' => json_encode(['patient_id' => $patientId] + $meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
