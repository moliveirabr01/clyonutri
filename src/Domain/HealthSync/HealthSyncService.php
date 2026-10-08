<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\HealthSync;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class HealthSyncService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function syncBiometrics(int $patientId, string $source, array $metrics): int
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            INSERT INTO health_sync_data (clinic_id, patient_id, source, metric_type, value, unit, recorded_at)
            VALUES (:clinic_id, :patient_id, :source, :metric_type, :value, :unit, :recorded_at)
        ");

        $count = 0;
        foreach ($metrics as $m) {
            $stmt->execute([
                'clinic_id' => $clinicId,
                'patient_id' => $patientId,
                'source' => $source,
                'metric_type' => $m['type'],
                'value' => (float)$m['value'],
                'unit' => $m['unit'],
                'recorded_at' => $m['recorded_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $count++;
        }

        return $count;
    }

    public function getLatestSummary(int $patientId): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);

        $stmt = $this->db->prepare("
            SELECT source, metric_type, value, unit, recorded_at
            FROM health_sync_data
            WHERE patient_id = :patient_id AND clinic_id = :clinic_id
            ORDER BY recorded_at DESC
            LIMIT 10
        ");
        $stmt->execute(['patient_id' => $patientId, 'clinic_id' => $clinicId]);

        return $stmt->fetchAll();
    }
}
