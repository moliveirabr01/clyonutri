<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Subscription;

use ClyoNutri\Core\Database\Connection;
use PDO;

final class SubscriptionService
{
    private PDO $db;

    public const VALID_CANCELLATION_REASONS = [
        'cost' => 'Custo ou orçamento',
        'career_change' => 'Mudança de carreira ou transição profissional',
        'missing_feature' => 'Falta de recursos necessários para a minha rotina',
        'technical_issue' => 'Dificuldades técnicas ou de uso',
        'other' => 'Outro motivo'
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Verifica se o usuário tem privilégio de gestão da assinatura do consultório (owner ou admin).
     */
    public function canManageSubscription(int $clinicId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT role FROM clinic_members 
            WHERE clinic_id = :clinic_id AND user_id = :user_id AND status = 'active'
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'user_id' => $userId
        ]);
        $role = $stmt->fetchColumn();

        return in_array($role, ['owner', 'admin'], true);
    }

    /**
     * Retorna a assinatura da clínica com plano, limites e consumo real.
     */
    public function getClinicSubscription(int $clinicId): array
    {
        $stmt = $this->db->prepare("
            SELECT cs.*, p.code as plan_code, p.name as plan_name, p.price_cents as catalog_price_cents,
                   p.max_professionals, p.max_patients, p.storage_mb, p.features_json
            FROM clinic_subscriptions cs
            JOIN saas_plans p ON cs.plan_id = p.id
            WHERE cs.clinic_id = :clinic_id
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            $sub = $this->initializeDefaultSubscription($clinicId);
        }

        // Métricas de uso real (sem dados de saúde)
        $sub['usage'] = $this->getUsageMetrics($clinicId);
        $sub['invoices'] = $this->getInvoices($clinicId, (int)$sub['id']);

        return $sub;
    }

    /**
     * Inicializa a assinatura padrão (PRO-02) para clínicas pré-existentes ou recém-criadas.
     */
    public function initializeDefaultSubscription(int $clinicId, string $planCode = 'PRO-02'): array
    {
        $stmtPlan = $this->db->prepare("SELECT * FROM saas_plans WHERE code = :code");
        $stmtPlan->execute(['code' => $planCode]);
        $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            throw new \RuntimeException("Plano SaaS '{$planCode}' não encontrado.");
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $currentStart = $now->format('Y-m-d H:i:s');
        $currentEnd = $now->modify('+30 days')->format('Y-m-d H:i:s');

        $stmtInsert = $this->db->prepare("
            INSERT INTO clinic_subscriptions 
                (clinic_id, plan_id, status, billing_cycle, price_cents, started_at, current_period_start, current_period_end)
            VALUES 
                (:clinic_id, :plan_id, 'active', :billing_cycle, :price_cents, :started_at, :period_start, :period_end)
        ");
        $stmtInsert->execute([
            'clinic_id' => $clinicId,
            'plan_id' => $plan['id'],
            'billing_cycle' => $plan['billing_cycle'],
            'price_cents' => $plan['price_cents'],
            'started_at' => $currentStart,
            'period_start' => $currentStart,
            'period_end' => $currentEnd,
        ]);

        $subId = (int)$this->db->lastInsertId();

        // Gerar primeira fatura inicial paga
        $invoiceNumber = 'FAT-' . $now->format('Ym') . '-' . str_pad((string)$clinicId, 4, '0', STR_PAD_LEFT);
        $stmtInv = $this->db->prepare("
            INSERT INTO subscription_invoices 
                (clinic_id, subscription_id, invoice_number, amount_cents, status, due_date, paid_at, payment_method)
            VALUES 
                (:clinic_id, :sub_id, :inv_num, :amount, 'paid', :due, :paid, 'credit_card')
        ");
        $stmtInv->execute([
            'clinic_id' => $clinicId,
            'sub_id' => $subId,
            'inv_num' => $invoiceNumber,
            'amount' => $plan['price_cents'],
            'due' => $now->format('Y-m-d'),
            'paid' => $currentStart,
        ]);

        return [
            'id' => $subId,
            'clinic_id' => $clinicId,
            'plan_id' => $plan['id'],
            'plan_code' => $plan['code'],
            'plan_name' => $plan['name'],
            'status' => 'active',
            'billing_cycle' => $plan['billing_cycle'],
            'price_cents' => (int)$plan['price_cents'],
            'catalog_price_cents' => (int)$plan['price_cents'],
            'max_professionals' => (int)$plan['max_professionals'],
            'max_patients' => (int)$plan['max_patients'],
            'storage_mb' => (int)$plan['storage_mb'],
            'features_json' => $plan['features_json'],
            'started_at' => $currentStart,
            'current_period_start' => $currentStart,
            'current_period_end' => $currentEnd,
            'canceled_at' => null,
            'cancellation_protocol' => null,
            'cancellation_reason_category' => null,
            'cancellation_feedback' => null,
        ];
    }

    /**
     * Calcula o uso de recursos da clínica em tempo real.
     */
    public function getUsageMetrics(int $clinicId): array
    {
        // Profissionais ativos
        $stmtProfs = $this->db->prepare("SELECT COUNT(*) FROM clinic_members WHERE clinic_id = :clinic_id AND status = 'active'");
        $stmtProfs->execute(['clinic_id' => $clinicId]);
        $activeProfessionals = (int)$stmtProfs->fetchColumn();

        // Pacientes ativos
        $stmtPatients = $this->db->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = :clinic_id AND status = 'active'");
        $stmtPatients->execute(['clinic_id' => $clinicId]);
        $activePatients = (int)$stmtPatients->fetchColumn();

        // Armazenamento em bytes (documentos)
        $stmtStorage = $this->db->prepare("SELECT COALESCE(SUM(size_bytes), 0) FROM patient_documents WHERE clinic_id = :clinic_id");
        $stmtStorage->execute(['clinic_id' => $clinicId]);
        $storageBytes = (int)$stmtStorage->fetchColumn();
        $storageMb = round($storageBytes / (1024 * 1024), 2);

        return [
            'active_professionals' => $activeProfessionals,
            'active_patients' => $activePatients,
            'storage_bytes' => $storageBytes,
            'storage_mb' => $storageMb,
        ];
    }

    /**
     * Retorna faturas da assinatura.
     */
    public function getInvoices(int $clinicId, int $subscriptionId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM subscription_invoices 
            WHERE clinic_id = :clinic_id AND subscription_id = :sub_id 
            ORDER BY due_date DESC, id DESC
        ");
        $stmt->execute([
            'clinic_id' => $clinicId,
            'sub_id' => $subscriptionId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cancela a assinatura de forma transparente e em autosserviço (sem dark patterns).
     * Suporta idempotência e pesquisa opcional de saída.
     */
    public function cancelSubscription(
        int $clinicId,
        int $userId,
        ?string $reasonCategory = null,
        ?string $feedback = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        // Validação estrita de autorização (apenas titular / admin)
        if (!$this->canManageSubscription($clinicId, $userId)) {
            throw new \DomainException('Apenas os titulares ou administradores do consultório podem cancelar a assinatura.');
        }

        $subscription = $this->getClinicSubscription($clinicId);

        // IDEMPOTÊNCIA: Se já estiver cancelada, não duplica e retorna protocolo existente
        if ($subscription['status'] === 'canceled') {
            return [
                'success' => true,
                'already_canceled' => true,
                'protocol' => $subscription['cancellation_protocol'],
                'status' => 'canceled',
                'canceled_at' => $subscription['canceled_at'],
                'effective_until' => $subscription['current_period_end'],
                'message' => 'Esta assinatura já foi cancelada anteriormente sob o protocolo ' . $subscription['cancellation_protocol'] . '.'
            ];
        }

        // Sanitização opcional da pesquisa
        $cleanReason = null;
        if (!empty($reasonCategory) && isset(self::VALID_CANCELLATION_REASONS[$reasonCategory])) {
            $cleanReason = $reasonCategory;
        }

        $cleanFeedback = null;
        if (!empty($feedback)) {
            $cleanFeedback = mb_substr(trim(strip_tags($feedback)), 0, 1000);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $canceledAt = $now->format('Y-m-d H:i:s');
        
        // Protocolo único e inequívoco de cancelamento: CAN-YYYYMMDD-XXXX
        $randomHex = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $protocol = 'CAN-' . $now->format('Ymd') . '-' . $randomHex;

        $this->db->beginTransaction();
        try {
            // Atualizar status da assinatura
            $stmtUpdate = $this->db->prepare("
                UPDATE clinic_subscriptions 
                SET status = 'canceled',
                    canceled_at = :canceled_at,
                    cancellation_protocol = :protocol,
                    cancellation_reason_category = :reason,
                    cancellation_feedback = :feedback,
                    updated_at = :updated_at
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $stmtUpdate->execute([
                'canceled_at' => $canceledAt,
                'protocol' => $protocol,
                'reason' => $cleanReason,
                'feedback' => $cleanFeedback,
                'updated_at' => $canceledAt,
                'id' => $subscription['id'],
                'clinic_id' => $clinicId,
            ]);

            // Gravar pesquisa de saída (se informada ou não, para métricas e rastreabilidade sem PHI)
            $stmtSurvey = $this->db->prepare("
                INSERT INTO cancellation_surveys
                    (clinic_id, subscription_id, protocol, reason_category, feedback_text, user_id, created_at)
                VALUES
                    (:clinic_id, :sub_id, :protocol, :reason, :feedback, :user_id, :created_at)
            ");
            $stmtSurvey->execute([
                'clinic_id' => $clinicId,
                'sub_id' => $subscription['id'],
                'protocol' => $protocol,
                'reason' => $cleanReason,
                'feedback' => $cleanFeedback,
                'user_id' => $userId,
                'created_at' => $canceledAt,
            ]);

            // Gravar em audit_logs (sem dados sensíveis de saúde / LGPD compliant)
            $stmtAudit = $this->db->prepare("
                INSERT INTO audit_logs 
                    (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                VALUES 
                    (:clinic_id, :user_id, 'subscription.canceled', 'clinic_subscriptions', :resource_id, :ip, :ua, :details, :created_at)
            ");
            $auditDetails = json_encode([
                'protocol' => $protocol,
                'plan_code' => $subscription['plan_code'],
                'reason_category' => $cleanReason,
                'effective_until' => $subscription['current_period_end'],
                'has_feedback' => !empty($cleanFeedback),
            ], JSON_UNESCAPED_UNICODE);

            $stmtAudit->execute([
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'resource_id' => (string)$subscription['id'],
                'ip' => $ipAddress,
                'ua' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'details' => $auditDetails,
                'created_at' => $canceledAt,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $effectiveUntilFormatted = (new \DateTimeImmutable($subscription['current_period_end']))->format('d/m/Y');

        return [
            'success' => true,
            'already_canceled' => false,
            'protocol' => $protocol,
            'status' => 'canceled',
            'canceled_at' => $canceledAt,
            'effective_until' => $subscription['current_period_end'],
            'message' => 'Assinatura cancelada com sucesso. Seu acesso continuará liberado até ' . $effectiveUntilFormatted . ', fim do ciclo faturado.'
        ];
    }

    /**
     * Retorna todas as opções oficiais de motivos de cancelamento.
     */
    public function getCancellationReasons(): array
    {
        return self::VALID_CANCELLATION_REASONS;
    }
}
