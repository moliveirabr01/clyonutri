<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Finance;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\SchemaInfo;
use PDO;

/**
 * v54.37: Saídas (despesas) da clínica, sem paciente. Tabela finance_expenses (migração 88).
 * Situação padrão ao criar: "paid" (já paga na data informada), porque o caso comum é anotar uma conta
 * que já saiu (internet, aluguel). Desmarcando "Já paga", fica "pending" e aparece "Marcar como paga".
 * Saída não gera recibo nem link para paciente.
 */
final class ExpenseService
{
    public const CATEGORIES = ['Internet', 'Aluguel', 'Material de escritório', 'Software/assinatura', 'Impostos/taxas', 'Marketing', 'Outros'];
    public const STATUS = ['pending' => 'A pagar', 'paid' => 'Paga', 'canceled' => 'Cancelada'];

    public function __construct(private PDO $db) {}

    public function available(): bool
    {
        return SchemaInfo::hasTable($this->db, 'finance_expenses');
    }

    private function requireTable(): void
    {
        if (!$this->available()) {
            throw new \RuntimeException('Saídas indisponíveis: rode o SQL da v54.37 (migração 88).');
        }
    }

    public function create(string $category, string $description, int $amountCents, string $date, bool $alreadyPaid = true, ?string $method = null): array
    {
        $this->requireTable();
        $clinicId = TenantContext::getClinicId();
        $category = trim($category);
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Escolha a categoria da saída.');
        }
        $description = trim($description);
        if ($description === '') {
            $description = $category;
        }
        if (mb_strlen($description) > 255) {
            throw new \InvalidArgumentException('Descrição muito longa (máx. 255).');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('O valor da saída deve ser maior que zero.');
        }
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Informe a data da saída.');
        }
        $method = $method !== null && isset(FinanceDocumentService::METHODS[$method]) ? $method : null;
        $status = $alreadyPaid ? 'paid' : 'pending';
        $st = $this->db->prepare('INSERT INTO finance_expenses (clinic_id, category, description, amount_cents, due_date, status, payment_method, paid_at, created_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $st->execute([$clinicId, $category, $description, $amountCents, $date, $status, $alreadyPaid ? $method : null,
            $alreadyPaid ? $date . ' 12:00:00' : null, TenantContext::getUserId()]);
        return ['id' => (int)$this->db->lastInsertId(), 'clinic_id' => $clinicId, 'status' => $status, 'category' => $category, 'amount_cents' => $amountCents];
    }

    private function find(int $id, int $clinicId): array
    {
        $st = $this->db->prepare('SELECT * FROM finance_expenses WHERE id = ? AND clinic_id = ?');
        $st->execute([$id, $clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \ClyoNutri\Core\Security\TenantSecurityException('Saída não encontrada nesta clínica.');
        }
        return $row;
    }

    public function markPaid(int $id, ?string $method = null): array
    {
        $this->requireTable();
        $clinicId = TenantContext::getClinicId();
        $row = $this->find($id, $clinicId);
        if ($row['status'] !== 'pending') {
            throw new \InvalidArgumentException('Só uma saída "a pagar" pode ser marcada como paga.');
        }
        $method = $method !== null && isset(FinanceDocumentService::METHODS[$method]) ? $method : null;
        $this->db->prepare("UPDATE finance_expenses SET status = 'paid', payment_method = ?, paid_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND clinic_id = ?")
            ->execute([$method, $id, $clinicId]);
        return ['id' => $id, 'status' => 'paid'];
    }

    public function cancel(int $id): array
    {
        $this->requireTable();
        $clinicId = TenantContext::getClinicId();
        $row = $this->find($id, $clinicId);
        if ($row['status'] === 'canceled') {
            throw new \InvalidArgumentException('Saída já cancelada.');
        }
        $this->db->prepare("UPDATE finance_expenses SET status = 'canceled', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND clinic_id = ?")
            ->execute([$id, $clinicId]);
        return ['id' => $id, 'status' => 'canceled'];
    }

    /** @return list<array<string,mixed>> */
    public function list(int $clinicId): array
    {
        if (!$this->available()) return [];
        $st = $this->db->prepare('SELECT * FROM finance_expenses WHERE clinic_id = ? ORDER BY due_date DESC, id DESC');
        $st->execute([$clinicId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{paid_cents:int,pending_cents:int,count:int} */
    public function summary(int $clinicId): array
    {
        if (!$this->available()) return ['paid_cents' => 0, 'pending_cents' => 0, 'count' => 0];
        $st = $this->db->prepare("SELECT COUNT(*) c,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_cents ELSE 0 END), 0) p,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount_cents ELSE 0 END), 0) q
            FROM finance_expenses WHERE clinic_id = ? AND status <> 'canceled'");
        $st->execute([$clinicId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['paid_cents' => (int)($r['p'] ?? 0), 'pending_cents' => (int)($r['q'] ?? 0), 'count' => (int)($r['c'] ?? 0)];
    }
}
