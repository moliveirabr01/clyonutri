<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Crm;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class PracticeCrmService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function createPackage(string $name, int $totalSessions, int $priceCents): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            INSERT INTO consultation_packages (clinic_id, name, total_sessions, price_cents)
            VALUES (:clinic_id, :name, :sessions, :price)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'name' => trim($name),
            'sessions' => $totalSessions,
            'price' => $priceCents,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'name' => $name,
            'total_sessions' => $totalSessions,
            'price_cents' => $priceCents,
        ];
    }

    public function assignPackageToPatient(int $patientId, int $packageId): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            INSERT INTO patient_packages (clinic_id, patient_id, package_id)
            VALUES (:clinic_id, :patient_id, :package_id)
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'package_id' => $packageId,
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'patient_id' => $patientId,
            'package_id' => $packageId,
            'sessions_used' => 0,
        ];
    }

    public function getPracticeMetrics(): array
    {
        $clinicId = TenantContext::getClinicId();

        // 1. Total de pacientes cadastrados
        $pStmt = $this->db->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = :clinic_id");
        $pStmt->execute(['clinic_id' => $clinicId]);
        $totalPatients = (int)$pStmt->fetchColumn();

        // 2. Total de consultas realizadas
        $aStmt = $this->db->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = :clinic_id AND status = 'completed'");
        $aStmt->execute(['clinic_id' => $clinicId]);
        $completedAppointments = (int)$aStmt->fetchColumn();

        // 3. Faturamento total acumulado (em centavos)
        $fStmt = $this->db->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE clinic_id = :clinic_id");
        $fStmt->execute(['clinic_id' => $clinicId]);
        $revenueCents = (int)$fStmt->fetchColumn();

        // 4. Taxa de retorno de pacientes (pacientes com mais de 1 consulta)
        $rStmt = $this->db->prepare("
            SELECT COUNT(*) FROM (
                SELECT patient_id FROM appointments
                WHERE clinic_id = :clinic_id
                GROUP BY patient_id HAVING COUNT(*) > 1
            )
        ");
        $rStmt->execute(['clinic_id' => $clinicId]);
        $returningPatients = (int)$rStmt->fetchColumn();

        $returnRatePct = $totalPatients > 0 ? round(($returningPatients / $totalPatients) * 100, 1) : 0.0;

        return [
            'total_patients' => $totalPatients,
            'completed_appointments' => $completedAppointments,
            'revenue_cents' => $revenueCents,
            'revenue_formatted' => 'R$ ' . number_format($revenueCents / 100, 2, ',', '.'),
            'returning_patients' => $returningPatients,
            'return_rate_pct' => $returnRatePct,
        ];
    }
}
