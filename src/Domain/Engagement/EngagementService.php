<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Engagement;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class EngagementService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function logHydration(int $patientId, int $amountMl): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            INSERT INTO hydration_logs (clinic_id, patient_id, amount_ml, logged_at)
            VALUES (:clinic_id, :patient_id, :amount_ml, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'amount_ml' => $amountMl,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'amount_ml' => $amountMl,
        ];
    }

    public function recordCheckin(int $patientId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            INSERT INTO checkins (clinic_id, patient_id, energy_level, hunger_level, meal_adherence_pct, notes, checkin_date)
            VALUES (:clinic_id, :patient_id, :energy, :hunger, :adherence, :notes, :date)
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'energy' => $data['energy_level'] ?? null,
            'hunger' => $data['hunger_level'] ?? null,
            'adherence' => $data['meal_adherence_pct'] ?? null,
            'notes' => $data['notes'] ?? null,
            'date' => $data['checkin_date'] ?? date('Y-m-d'),
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'patient_id' => $patientId,
            'meal_adherence_pct' => $data['meal_adherence_pct'] ?? null,
        ];
    }

    public function getPatientAdherenceSummary(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        // Total de água registrada hoje
        $today = date('Y-m-d');
        $hStmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_ml), 0) FROM hydration_logs
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id AND date(logged_at) = :today
        ");
        $hStmt->execute([
            'patient_id' => $patientId,
            'clinic_id' => $clinicId,
            'today' => $today,
        ]);
        $waterToday = (int)$hStmt->fetchColumn();

        // Média de adesão dos últimos check-ins
        $cStmt = $this->db->prepare("
            SELECT AVG(meal_adherence_pct) FROM checkins
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
        ");
        $cStmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);
        $avgAdherence = round((float)$cStmt->fetchColumn(), 1);

        return [
            'patient_id' => $patientId,
            'water_intake_today_ml' => $waterToday,
            'average_adherence_pct' => $avgAdherence,
        ];
    }

    /**
     * Análise longitudinal semanal (NI2-117)
     */
    public function getLongitudinalTrends(int $patientId, int $weeks = 4): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT checkin_date, energy_level, hunger_level, meal_adherence_pct, notes
            FROM checkins
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
            ORDER BY checkin_date ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':patient_id', $patientId, PDO::PARAM_INT);
        $stmt->bindValue(':clinic_id', $clinicId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $weeks * 7, PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll();

        return [
            'patient_id' => $patientId,
            'period_weeks' => $weeks,
            'total_checkins' => count($records),
            'history' => $records,
        ];
    }

    /**
     * Retorna métricas de adesão alimentar consolidada da clínica para o Dashboard (NI2-297 / PX-04).
     * Elimina fallbacks fictícios (como 94.0%) e deriva estritamente de dados elegíveis reais.
     */
    public function getClinicAdherenceMetrics(int $clinicId, int $days = 30): array
    {
        if ($clinicId <= 0) {
            throw new \InvalidArgumentException("Clinic ID inválido para cálculo de adesão.");
        }

        // População elegível: total de pacientes cadastrados no tenant
        $stmtPatients = $this->db->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = :cid");
        $stmtPatients->execute(['cid' => $clinicId]);
        $totalEligiblePatients = (int)$stmtPatients->fetchColumn();

        // Limite da janela temporal em dias corridos
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) AS total_checkins,
                COUNT(meal_adherence_pct) AS adherence_responses_count,
                COUNT(DISTINCT patient_id) AS responding_patients_count,
                AVG(meal_adherence_pct) AS avg_adherence
            FROM checkins 
            WHERE clinic_id = :cid 
              AND checkin_date >= :start_date
              AND meal_adherence_pct IS NOT NULL
        ");
        $stmt->execute(['cid' => $clinicId, 'start_date' => $startDate]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $responsesCount = (int)($row['adherence_responses_count'] ?? 0);
        $respondingPatients = (int)($row['responding_patients_count'] ?? 0);
        $rawAvg = $row['avg_adherence'] !== null ? (float)$row['avg_adherence'] : null;

        $hasData = ($responsesCount > 0 && $rawAvg !== null);

        return [
            'has_data' => $hasData,
            'average_pct' => $hasData ? round($rawAvg, 1) : null,
            'formatted_value' => $hasData ? number_format(round($rawAvg, 1), 1, ',', '.') . '%' : 'Sem dados',
            'responses_count' => $responsesCount,
            'responding_patients_count' => $respondingPatients,
            'total_eligible_patients' => $totalEligiblePatients,
            'period_days' => $days,
            'period_label' => "Últimos {$days} dias",
        ];
    }
}
