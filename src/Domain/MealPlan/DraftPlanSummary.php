<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use PDO;

/**
 * Fonte única para "prescrições a revisar" no Dashboard.
 *
 * Definição (documentada): um paciente conta UMA vez quando possui ao menos um
 * plano alimentar com status 'draft' no tenant atual. Rascunhos repetidos do mesmo
 * paciente (duplicações/versões não publicadas) são agrupados, não somados.
 * Pacientes anonimizados são ignorados. Todas as consultas filtram pelo tenant
 * tanto em meal_plans quanto em patients.
 */
class DraftPlanSummary
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array{patients:int, draft_plans:int} */
    public function totals(int $clinicId): array
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT mp.patient_id) AS patients, COUNT(*) AS draft_plans
            FROM meal_plans mp
            JOIN patients p ON p.id = mp.patient_id AND p.clinic_id = mp.clinic_id
            WHERE mp.clinic_id = :clinic_id
              AND mp.status = 'draft'
              AND p.status <> 'anonymized'
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'patients' => (int)($row['patients'] ?? 0),
            'draft_plans' => (int)($row['draft_plans'] ?? 0),
        ];
    }

    /**
     * Rascunhos agrupados por paciente, do mais antigo para o mais recente.
     *
     * @return list<array{patient_id:int, patient_name:string, draft_count:int, latest_plan_id:int}>
     */
    public function byPatient(int $clinicId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $stmt = $this->db->prepare("
            SELECT mp.patient_id, p.name AS patient_name, COUNT(*) AS draft_count,
                   MAX(mp.id) AS latest_plan_id, MIN(mp.created_at) AS oldest_created_at
            FROM meal_plans mp
            JOIN patients p ON p.id = mp.patient_id AND p.clinic_id = mp.clinic_id
            WHERE mp.clinic_id = :clinic_id
              AND mp.status = 'draft'
              AND p.status <> 'anonymized'
            GROUP BY mp.patient_id, p.name
            ORDER BY oldest_created_at ASC, mp.patient_id ASC
            LIMIT {$limit}
        ");
        $stmt->execute(['clinic_id' => $clinicId]);

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[] = [
                'patient_id' => (int)$row['patient_id'],
                'patient_name' => (string)$row['patient_name'],
                'draft_count' => (int)$row['draft_count'],
                'latest_plan_id' => (int)$row['latest_plan_id'],
            ];
        }

        return $groups;
    }

    /** Destino da revisão: o próprio editor quando há um único rascunho; senão a lista filtrada. */
    public static function reviewUrl(array $group): string
    {
        if ($group['draft_count'] === 1) {
            return '/planos/' . $group['latest_plan_id'];
        }

        return '/planos?status=draft&paciente=' . $group['patient_id'];
    }
}
