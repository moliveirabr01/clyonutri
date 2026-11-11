<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Subscription;

use ClyoNutri\Core\Database\Connection;
use PDO;

final class SubscriptionService
{
    private PDO $db;

    public const DEFAULT_TRIAL_DAYS = 14;
    public const DEFAULT_TRIAL_PLAN = 'PRO-02';

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

    public function getOnboardingPlans(): array
    {
        return $this->db->query("SELECT * FROM saas_plans WHERE is_active = 1 ORDER BY price_cents, id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function canChooseOnboardingPlan(int $clinicId, int $userId): bool
    {
        if (!$this->canManageSubscription($clinicId, $userId)) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT status FROM clinic_subscriptions WHERE clinic_id = ?");
        $stmt->execute([$clinicId]);
        $status = $stmt->fetchColumn();
        return $status === false || $status === 'trialing';
    }

    /** Called inside the onboarding transaction; never renews the trial or creates invoices. */
    public function chooseOnboardingPlan(int $clinicId, int $userId, string $code): void
    {
        if (!$this->canChooseOnboardingPlan($clinicId, $userId)) {
            throw new \DomainException('A seleção de plano exige um titular ou administrador em período de teste.');
        }
        $stmt = $this->db->prepare("SELECT * FROM saas_plans WHERE code = ? AND is_active = 1");
        $stmt->execute([$code]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new \InvalidArgumentException('Selecione um plano disponível da Clyo Nutri.');
        }
        $stmt = $this->db->prepare("SELECT id FROM clinic_subscriptions WHERE clinic_id = ?");
        $stmt->execute([$clinicId]);
        if (!$stmt->fetchColumn()) {
            $this->initializeDefaultSubscription($clinicId, $code);
            return;
        }
        $stmt = $this->db->prepare("UPDATE clinic_subscriptions SET plan_id = ?, billing_cycle = ?, price_cents = ?, updated_at = CURRENT_TIMESTAMP WHERE clinic_id = ? AND status = 'trialing'");
        $stmt->execute([$plan['id'], $plan['billing_cycle'], $plan['price_cents'], $clinicId]);
        if ($stmt->rowCount() === 0) {
            $check = $this->db->prepare("SELECT plan_id FROM clinic_subscriptions WHERE clinic_id = ? AND status = 'trialing'");
            $check->execute([$clinicId]);
            if ((int)$check->fetchColumn() !== (int)$plan['id']) {
                throw new \DomainException('A assinatura mudou. Recarregue a página antes de selecionar o plano.');
            }
        }
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
            // v34: teste criado sob demanda (fora do cadastro) avisa o backoffice. Nunca lança.
            if (!$this->db->inTransaction()) {
                (new \ClyoNutri\Domain\Notification\StaffNotificationService($this->db))->notifyTrialStarted($clinicId);
            }
        }

        // Informações e cálculo do período de teste (trial)
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $sub['is_trial'] = ($sub['status'] === 'trialing');
        $sub['days_left_in_trial'] = 0;

        if (!empty($sub['trial_ends_at'])) {
            $trialEnd = new \DateTimeImmutable($sub['trial_ends_at'], new \DateTimeZone('America/Sao_Paulo'));
            if ($trialEnd > $now) {
                $interval = $now->diff($trialEnd);
                $sub['days_left_in_trial'] = (int)$interval->days + ($interval->h > 0 || $interval->i > 0 ? 1 : 0);
            }
        }

        // Métricas de uso real (sem dados de saúde)
        $sub['usage'] = $this->getUsageMetrics($clinicId);
        $sub['invoices'] = $this->getInvoices($clinicId, (int)$sub['id']);

        return $sub;
    }

    /**
     * Inicializa a assinatura padrão em período de avaliação de 14 dias (PRO-02).
     * Não gera fatura fictícia: cobrança só nasce após contratação real via gateway (NI2-450).
     */
    public function initializeDefaultSubscription(
        int $clinicId,
        string $planCode = self::DEFAULT_TRIAL_PLAN,
        int $trialDays = self::DEFAULT_TRIAL_DAYS
    ): array {
        $stmtPlan = $this->db->prepare("SELECT * FROM saas_plans WHERE code = :code");
        $stmtPlan->execute(['code' => $planCode]);
        $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            throw new \RuntimeException("Plano SaaS '{$planCode}' não encontrado.");
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $currentStart = $now->format('Y-m-d H:i:s');
        $trialEndsAt = $now->modify("+{$trialDays} days")->format('Y-m-d H:i:s');
        $currentEnd = $trialEndsAt;

        $stmtInsert = $this->db->prepare("
            INSERT INTO clinic_subscriptions 
                (clinic_id, plan_id, status, billing_cycle, price_cents, started_at, current_period_start, current_period_end, trial_ends_at)
            VALUES 
                (:clinic_id, :plan_id, 'trialing', :billing_cycle, :price_cents, :started_at, :period_start, :period_end, :trial_ends_at)
        ");
        $stmtInsert->execute([
            'clinic_id' => $clinicId,
            'plan_id' => $plan['id'],
            'billing_cycle' => $plan['billing_cycle'],
            'price_cents' => $plan['price_cents'],
            'started_at' => $currentStart,
            'period_start' => $currentStart,
            'period_end' => $currentEnd,
            'trial_ends_at' => $trialEndsAt,
        ]);

        $subId = (int)$this->db->lastInsertId();

        // Nenhuma fatura paga fictícia é gerada. Faturas só nascem com webhook de pagamento real.

        return [
            'id' => $subId,
            'clinic_id' => $clinicId,
            'plan_id' => $plan['id'],
            'plan_code' => $plan['code'],
            'plan_name' => $plan['name'],
            'status' => 'trialing',
            'is_trial' => true,
            'days_left_in_trial' => $trialDays,
            'trial_ends_at' => $trialEndsAt,
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
     * Verifica se a clínica pode criar novos dados clínicos (pacientes, planos, consultas).
     * Durante o trial ativo ou plano ativo: permitido.
     * Trial expirado ou cancelado: bloqueia novas mutações mas mantém leitura e exportação.
     */
    public function canMutateClinicalData(int $clinicId): bool
    {
        $stmt = $this->db->prepare("SELECT status, trial_ends_at FROM clinic_subscriptions WHERE clinic_id = :clinic_id");
        $stmt->execute(['clinic_id' => $clinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return true;
        }

        if ($row['status'] === 'active') {
            return true;
        }

        if ($row['status'] === 'trialing') {
            if (empty($row['trial_ends_at'])) {
                return true;
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
            $trialEnd = new \DateTimeImmutable($row['trial_ends_at'], new \DateTimeZone('America/Sao_Paulo'));
            return $trialEnd >= $now;
        }

        return false;
    }

    /**
     * Rotina executada periodicamente (cron_scheduler) para expirar períodos de avaliação vencidos.
     * Retorna a quantidade de assinaturas atualizadas de 'trialing' para 'expired'.
     */
    public function expireTrials(): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            UPDATE clinic_subscriptions 
            SET status = 'expired', updated_at = :now
            WHERE status = 'trialing' 
              AND trial_ends_at IS NOT NULL 
              AND trial_ends_at < :now
        ");
        $stmt->execute(['now' => $now]);

        return $stmt->rowCount();
    }

    /**
     * Saneamento contábil e de segurança para bancos existentes (NI2-450):
     * 1. Marca faturas fictícias antigas geradas automaticamente como 'void'.
     * 2. Ajusta assinaturas sem pagamento real para 'trialing' ou 'expired'.
     */
    public function sanitizeLegacySubscriptions(): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

        // 1. Anula faturas fictícias geradas pelo código legado (sem gateway)
        $stmtVoid = $this->db->prepare("
            UPDATE subscription_invoices
            SET status = 'void'
            WHERE status = 'paid'
        ");
        $stmtVoid->execute();
        $voidedInvoices = $stmtVoid->rowCount();

        // 2. Identificar assinaturas legadas ativas sem pagamento real
        $stmtSubs = $this->db->query("
            SELECT cs.id, cs.started_at, cs.status 
            FROM clinic_subscriptions cs
            WHERE cs.status = 'active'
        ");
        $subsToSanitize = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

        $trialingCount = 0;
        $expiredCount = 0;

        $stmtUpdateSub = $this->db->prepare("
            UPDATE clinic_subscriptions 
            SET status = :status,
                trial_ends_at = :trial_ends_at,
                current_period_end = :current_period_end,
                updated_at = :updated_at
            WHERE id = :id
        ");

        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        foreach ($subsToSanitize as $sub) {
            $startedDt = new \DateTimeImmutable($sub['started_at'], new \DateTimeZone('America/Sao_Paulo'));
            $trialEndDt = $startedDt->modify('+14 days');

            if ($trialEndDt > $nowDt) {
                // Ainda dentro dos 14 dias
                $status = 'trialing';
                $trialingCount++;
            } else {
                // Já passou de 14 dias
                $status = 'expired';
                $expiredCount++;
            }

            $stmtUpdateSub->execute([
                'status' => $status,
                'trial_ends_at' => $trialEndDt->format('Y-m-d H:i:s'),
                'current_period_end' => $trialEndDt->format('Y-m-d H:i:s'),
                'updated_at' => $now,
                'id' => $sub['id'],
            ]);
        }

        return [
            'voided_invoices' => $voidedInvoices,
            'trialing_subscriptions' => $trialingCount,
            'expired_subscriptions' => $expiredCount,
            'total_sanitized' => count($subsToSanitize),
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
        // v54.51: junta o recibo do Asaas (mesma clínica) para expor receipt_url à view.
        try {
            $stmt = $this->db->prepare("
                SELECT i.*, r.asaas_payment_id, r.receipt_url
                FROM subscription_invoices i
                LEFT JOIN asaas_payment_receipts r ON r.invoice_id = i.id AND r.clinic_id = i.clinic_id
                WHERE i.clinic_id = :clinic_id AND i.subscription_id = :sub_id
                ORDER BY i.due_date DESC, i.id DESC
            ");
            $stmt->execute(['clinic_id' => $clinicId, 'sub_id' => $subscriptionId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Banco sem a migração 89/90: lista as faturas sem link (não quebra a página).
            $stmt = $this->db->prepare("
                SELECT * FROM subscription_invoices
                WHERE clinic_id = :clinic_id AND subscription_id = :sub_id
                ORDER BY due_date DESC, id DESC
            ");
            $stmt->execute(['clinic_id' => $clinicId, 'sub_id' => $subscriptionId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $lookups = 0;
        foreach ($rows as &$row) {
            $url = isset($row['receipt_url']) ? (string)$row['receipt_url'] : '';
            $pid = isset($row['asaas_payment_id']) ? (string)$row['asaas_payment_id'] : '';
            if ($url === '' && $pid !== '' && $lookups < 3) {
                $lookups++;
                $url = (string)($this->resolveReceiptUrl($clinicId, $pid) ?? '');
            }
            $row['receipt_url'] = \ClyoNutri\Domain\Billing\AsaasReceiptUrl::isSafe($url) ? $url : null;
            unset($row['asaas_payment_id']);
        }
        unset($row);
        return $rows;
    }

    /** @var (callable(string):array)|null */
    private $paymentFetcher = null;

    /** Permite injetar a consulta GET /payments/{id} (testes). Padrão: AsaasClient com a chave do ambiente. */
    public function setPaymentFetcher(?callable $fetcher): void
    {
        $this->paymentFetcher = $fetcher;
    }

    /** Fallback: consulta o Asaas e grava a URL se o gateway devolver uma válida. Falha = sem link. */
    private function resolveReceiptUrl(int $clinicId, string $paymentId): ?string
    {
        if (preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId) !== 1) {
            return null; // ids locais/de teste (ex.: pay_test_3) não existem no gateway
        }
        try {
            $fetch = $this->paymentFetcher;
            if ($fetch === null) {
                $cfg = \ClyoNutri\Domain\Billing\AsaasConfig::fromEnv();
                if (!$cfg->hasApiKey()) {
                    return null;
                }
                $client = new \ClyoNutri\Domain\Billing\AsaasClient($cfg);
                $fetch = static fn(string $id): array => $client->getPayment($id);
            }
            $url = \ClyoNutri\Domain\Billing\AsaasReceiptUrl::pick((array)$fetch($paymentId));
            if ($url !== null) {
                $u = $this->db->prepare('UPDATE asaas_payment_receipts SET receipt_url = ? WHERE asaas_payment_id = ? AND clinic_id = ?');
                $u->execute([$url, $paymentId, $clinicId]);
            }
            return $url;
        } catch (\Throwable $e) {
            return null;
        }
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

        // v34: aviso ao backoffice depois do commit (nunca lança; o cancelamento já está gravado)
        (new \ClyoNutri\Domain\Notification\StaffNotificationService($this->db))
            ->notifySubscriptionCanceled($clinicId, $protocol, (string)$subscription['current_period_end']);

        $effectiveUntilFormatted = (new \DateTimeImmutable($subscription['current_period_end']))->format('d/m/Y');

        return [
            'success' => true,
            'already_canceled' => false,
            'protocol' => $protocol,
            'status' => 'canceled',
            'canceled_at' => $canceledAt,
            'effective_until' => $subscription['current_period_end'],
            'message' => 'Assinatura cancelada. Seu acesso continuará liberado até ' . $effectiveUntilFormatted . ', fim do ciclo faturado.'
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
