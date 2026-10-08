<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Finance;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class FinanceService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function createReceivable(int $patientId, string $description, int $amountCents, string $dueDate, ?int $appointmentId = null): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        if ($appointmentId !== null) {
            \ClyoNutri\Core\Security\TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        }

        $stmt = $this->db->prepare("
            INSERT INTO receivables (clinic_id, patient_id, appointment_id, description, amount_cents, due_date, status)
            VALUES (:clinic_id, :patient_id, :appointment_id, :description, :amount_cents, :due_date, 'pending')
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'description' => trim($description),
            'amount_cents' => $amountCents,
            'due_date' => $dueDate,
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'description' => $description,
            'amount_cents' => $amountCents,
            'status' => 'pending',
            'due_date' => $dueDate,
        ];
    }

    public function recordPayment(int $receivableId, int $amountCents, string $method): array
    {
        $clinicId = TenantContext::getClinicId();
        $receivable = \ClyoNutri\Core\Security\TenantGuard::assertReceivableBelongsToClinic($receivableId, $clinicId, $this->db);

        $receiptCode = 'REC-' . strtoupper(bin2hex(random_bytes(4)));

        $this->db->beginTransaction();
        try {
            $pStmt = $this->db->prepare("
                INSERT INTO payments (clinic_id, receivable_id, amount_cents, payment_method, paid_at, receipt_code)
                VALUES (:clinic_id, :receivable_id, :amount_cents, :method, CURRENT_TIMESTAMP, :receipt_code)
            ");
            $pStmt->execute([
                'clinic_id' => $clinicId,
                'receivable_id' => $receivableId,
                'amount_cents' => $amountCents,
                'method' => $method,
                'receipt_code' => $receiptCode,
            ]);

            // Total pago até o momento
            $totStmt = $this->db->prepare("SELECT SUM(amount_cents) FROM payments WHERE receivable_id = :id");
            $totStmt->execute(['id' => $receivableId]);
            $totalPaid = (int)$totStmt->fetchColumn();

            $newStatus = ($totalPaid >= (int)$receivable['amount_cents']) ? 'paid' : 'partially_paid';

            $upStmt = $this->db->prepare("UPDATE receivables SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upStmt->execute(['status' => $newStatus, 'id' => $receivableId]);

            $this->db->commit();

            return [
                'receivable_id' => $receivableId,
                'receipt_code' => $receiptCode,
                'status' => $newStatus,
                'amount_paid_cents' => $amountCents,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function generateReceipt(string $receiptCode): ?array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT p.*, r.description, r.amount_cents AS total_amount_cents,
                   pat.name AS patient_name, pat.email AS patient_email
            FROM payments p
            JOIN receivables r ON r.id = p.receivable_id
            JOIN patients pat ON pat.id = r.patient_id
            WHERE p.receipt_code = :code AND p.clinic_id = :clinic_id
        ");
        $stmt->execute(['code' => $receiptCode, 'clinic_id' => $clinicId]);
        $receipt = $stmt->fetch();

        return $receipt ?: null;
    }
}
