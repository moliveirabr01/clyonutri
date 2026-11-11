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

    /** v54.37: categorias sugeridas para Entrada (o texto livre fica na descrição). */
    public const ENTRY_CATEGORIES = ['Consulta', 'Plano', 'Outros'];

    public function createReceivable(int $patientId, string $description, int $amountCents, string $dueDate, ?int $appointmentId = null, ?string $category = null): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertPatientBelongsToClinic($patientId, $clinicId, $this->db);
        if ($appointmentId !== null) {
            \ClyoNutri\Core\Security\TenantGuard::assertAppointmentBelongsToClinic($appointmentId, $clinicId, $this->db);
        }

        $description = trim($description);
        if ($description === '') {
            throw new \InvalidArgumentException("A descrição do lançamento financeiro é obrigatória.");
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("O valor do recebível deve ser maior que zero.");
        }
        $dueDate = trim($dueDate);
        if ($dueDate === '') {
            throw new \InvalidArgumentException("A data de vencimento é obrigatória.");
        }

        $category = $category !== null ? trim($category) : '';
        if ($category !== '' && !in_array($category, self::ENTRY_CATEGORIES, true)) {
            throw new \InvalidArgumentException("Categoria de entrada inválida.");
        }
        $hasCategory = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'receivables', 'category');
        $params = [];
        if ($hasCategory) {
            $stmt = $this->db->prepare("
                INSERT INTO receivables (clinic_id, patient_id, appointment_id, description, amount_cents, due_date, status, category)
                VALUES (:clinic_id, :patient_id, :appointment_id, :description, :amount_cents, :due_date, 'pending', :category)
            ");
            $params['category'] = $category !== '' ? $category : null;
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO receivables (clinic_id, patient_id, appointment_id, description, amount_cents, due_date, status)
                VALUES (:clinic_id, :patient_id, :appointment_id, :description, :amount_cents, :due_date, 'pending')
            ");
        }

        $stmt->execute($params + [
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'description' => $description,
            'amount_cents' => $amountCents,
            'due_date' => $dueDate,
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'description' => $description,
            'amount_cents' => $amountCents,
            'status' => 'pending',
            'due_date' => $dueDate,
        ];
    }

    public function recordPayment(int $receivableId, int $amountCents, string $method = 'pix'): array
    {
        $clinicId = TenantContext::getClinicId();
        $receivable = \ClyoNutri\Core\Security\TenantGuard::assertReceivableBelongsToClinic($receivableId, $clinicId, $this->db);

        if ($receivable['status'] === 'paid') {
            throw new \InvalidArgumentException("Este recebível já está totalmente quitado.");
        }
        if ($receivable['status'] === 'canceled' || $receivable['status'] === 'cancelled') {
            throw new \InvalidArgumentException("Não é possível registrar pagamento em um recebível cancelado.");
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("O valor do pagamento deve ser maior que zero.");
        }

        $method = trim($method);
        if ($method === '') {
            $method = 'pix';
        }

        $receiptCode = 'REC-' . strtoupper(bin2hex(random_bytes(4)));

        $this->db->beginTransaction();
        try {
            // v54.37: guarda quem deu a baixa (CRN no recibo), se a migração 88 já rodou.
            $recorder = \ClyoNutri\Core\Database\SchemaInfo::hasColumn($this->db, 'payments', 'recorded_by_user_id')
                ? TenantContext::getUserId() : null;
            $pStmt = $this->db->prepare($recorder !== null ? "
                INSERT INTO payments (clinic_id, receivable_id, amount_cents, payment_method, paid_at, receipt_code, recorded_by_user_id)
                VALUES (:clinic_id, :receivable_id, :amount_cents, :method, CURRENT_TIMESTAMP, :receipt_code, :recorder)
            " : "
                INSERT INTO payments (clinic_id, receivable_id, amount_cents, payment_method, paid_at, receipt_code)
                VALUES (:clinic_id, :receivable_id, :amount_cents, :method, CURRENT_TIMESTAMP, :receipt_code)
            ");
            $pStmt->execute(($recorder !== null ? ['recorder' => $recorder] : []) + [
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

            $upStmt = $this->db->prepare("UPDATE receivables SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :clinic_id");
            $upStmt->execute(['status' => $newStatus, 'id' => $receivableId, 'clinic_id' => $clinicId]);

            $this->db->commit();

            return [
                'receivable_id' => $receivableId,
                'receipt_code' => $receiptCode,
                'status' => $newStatus,
                'amount_paid_cents' => $amountCents,
                'total_paid_cents' => $totalPaid,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancelReceivable(int $receivableId): array
    {
        $clinicId = TenantContext::getClinicId();
        $receivable = \ClyoNutri\Core\Security\TenantGuard::assertReceivableBelongsToClinic($receivableId, $clinicId, $this->db);

        if ($receivable['status'] === 'paid') {
            throw new \InvalidArgumentException("Não é possível cancelar um recebível já quitado.");
        }
        if ($receivable['status'] === 'canceled' || $receivable['status'] === 'cancelled') {
            return $receivable;
        }

        $stmt = $this->db->prepare("UPDATE receivables SET status = 'canceled', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND clinic_id = :clinic_id");
        $stmt->execute(['id' => $receivableId, 'clinic_id' => $clinicId]);

        $receivable['status'] = 'canceled';
        return $receivable;
    }

    public function getReceivablesSummary(): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_count,
                COALESCE(SUM(CASE WHEN status IN ('pending', 'partially_paid') THEN amount_cents ELSE 0 END), 0) as total_pending_cents,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_cents ELSE 0 END), 0) as total_paid_cents,
                COALESCE(SUM(amount_cents), 0) as total_amount_cents
            FROM receivables
            WHERE clinic_id = :clinic_id
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $summary = $stmt->fetch() ?: [];

        $stmtPay = $this->db->prepare("
            SELECT COALESCE(SUM(amount_cents), 0)
            FROM payments
            WHERE clinic_id = :clinic_id
        ");
        $stmtPay->execute(['clinic_id' => $clinicId]);
        $actualPayments = (int)$stmtPay->fetchColumn();

        return [
            'total_count' => (int)($summary['total_count'] ?? 0),
            'total_pending_cents' => (int)($summary['total_pending_cents'] ?? 0),
            'total_paid_cents' => max((int)($summary['total_paid_cents'] ?? 0), $actualPayments),
            'total_amount_cents' => (int)($summary['total_amount_cents'] ?? 0),
        ];
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
