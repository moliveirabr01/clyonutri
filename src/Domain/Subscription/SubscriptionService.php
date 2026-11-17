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
        $sub['trial_state'] = $this->trialState($clinicId);
        $sub['expired_notice'] = $this->expiredNotice($clinicId);

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
        
        $stmtFind = $this->db->prepare("
            SELECT cs.clinic_id, cm.user_id, c.name as clinic_name, sp.name as plan_name
            FROM clinic_subscriptions cs
            JOIN clinics c ON c.id = cs.clinic_id
            JOIN clinic_members cm ON cm.clinic_id = cs.clinic_id AND cm.role = 'owner' AND cm.status = 'active'
            LEFT JOIN saas_plans sp ON sp.id = cs.plan_id
            WHERE cs.status = 'trialing' 
              AND cs.trial_ends_at IS NOT NULL 
              AND cs.trial_ends_at < :now
        ");
        $stmtFind->execute(['now' => $now]);
        $rows = $stmtFind->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare("
            UPDATE clinic_subscriptions 
            SET status = 'expired', updated_at = :now
            WHERE status = 'trialing' 
              AND trial_ends_at IS NOT NULL 
              AND trial_ends_at < :now
        ");
        $stmt->execute(['now' => $now]);
        $affected = $stmt->rowCount();

        foreach ($rows as $r) {
            try {
                \ClyoNutri\Core\Auth\ProfessionalSecurityNotifier::make($this->db)->trialExpired(
                    (int)$r['user_id'],
                    (string)$r['clinic_name'],
                    (string)($r['plan_name'] ?? 'Profissional')
                );
            } catch (\Throwable $e) {
                error_log('[SubscriptionService] falha ao notificar trial expirado: ' . $e->getMessage());
            }
        }

        return $affected;
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

    public const EXPIRED_GRACE_DAYS = 3;

    /**
     * NI2-878: regra única de estado do teste/assinatura baseada em data (sem depender de cron).
     * Retorna state: active | trial | grace | read_only | none.
     *
     * @return array{
     *   state:string,
     *   status:string,
     *   trial_ends_at:?string,
     *   grace_ends_at:?string,
     *   current_period_end:?string,
     *   days_remaining:int,
     *   days_left_in_trial:int,
     *   grace_days:int,
     *   expired:bool,
     *   in_grace:bool,
     *   read_only:bool,
     *   reason:?string
     * }
     */
    public function trialState(int $clinicId, ?int $nowTimestamp = null): array
    {
        $graceRaw = \ClyoNutri\Core\Security\Env::get('TRIAL_EXPIRED_GRACE_DAYS');
        $graceDays = is_numeric($graceRaw) ? max(0, (int)$graceRaw) : self::EXPIRED_GRACE_DAYS;
        $nowTs = $nowTimestamp ?? time();

        if (!\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'clinic_subscriptions')) {
            return [
                'state' => 'none',
                'status' => 'none',
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'current_period_end' => null,
                'days_remaining' => 0,
                'days_left_in_trial' => 0,
                'grace_days' => $graceDays,
                'expired' => false,
                'in_grace' => false,
                'read_only' => false,
                'reason' => null,
            ];
        }

        $st = $this->db->prepare('SELECT status, trial_ends_at, current_period_end, updated_at FROM clinic_subscriptions WHERE clinic_id = :c ORDER BY id DESC LIMIT 1');
        $st->execute(['c' => $clinicId]);
        $sub = $st->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            return [
                'state' => 'none',
                'status' => 'none',
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'current_period_end' => null,
                'days_remaining' => 0,
                'days_left_in_trial' => 0,
                'grace_days' => $graceDays,
                'expired' => false,
                'in_grace' => false,
                'read_only' => false,
                'reason' => null,
            ];
        }

        $status = (string)($sub['status'] ?? '');
        $trialEndsAt = !empty($sub['trial_ends_at']) ? (string)$sub['trial_ends_at'] : null;
        $periodEnd = !empty($sub['current_period_end']) ? (string)$sub['current_period_end'] : null;

        if ($status === 'active') {
            return [
                'state' => 'active',
                'status' => $status,
                'trial_ends_at' => $trialEndsAt,
                'grace_ends_at' => null,
                'current_period_end' => $periodEnd,
                'days_remaining' => 0,
                'days_left_in_trial' => 0,
                'grace_days' => $graceDays,
                'expired' => false,
                'in_grace' => false,
                'read_only' => false,
                'reason' => null,
            ];
        }

        if ($status === 'canceled') {
            $periodTs = $periodEnd !== null ? strtotime($periodEnd) : false;
            if ($periodTs !== false && $periodTs >= $nowTs) {
                return [
                    'state' => 'active',
                    'status' => $status,
                    'trial_ends_at' => $trialEndsAt,
                    'grace_ends_at' => null,
                    'current_period_end' => $periodEnd,
                    'days_remaining' => 0,
                    'days_left_in_trial' => 0,
                    'grace_days' => $graceDays,
                    'expired' => false,
                    'in_grace' => false,
                    'read_only' => false,
                    'reason' => null,
                ];
            }
            return [
                'state' => 'read_only',
                'status' => $status,
                'trial_ends_at' => $trialEndsAt,
                'grace_ends_at' => null,
                'current_period_end' => $periodEnd,
                'days_remaining' => 0,
                'days_left_in_trial' => 0,
                'grace_days' => $graceDays,
                'expired' => true,
                'in_grace' => false,
                'read_only' => true,
                'reason' => 'Sua assinatura foi cancelada e o acesso está em modo leitura (leitura e exportação continuam liberadas).',
            ];
        }

        $refRaw = (string)($sub['trial_ends_at'] ?? $sub['current_period_end'] ?? $sub['updated_at'] ?? '');
        $refTs = $refRaw !== '' ? strtotime($refRaw) : false;

        if ($status === 'trialing' && ($refTs === false || $refTs > $nowTs)) {
            $daysLeft = $refTs !== false ? max(1, (int)ceil(($refTs - $nowTs) / 86400)) : 0;
            return [
                'state' => 'trial',
                'status' => $status,
                'trial_ends_at' => $trialEndsAt,
                'grace_ends_at' => null,
                'current_period_end' => $periodEnd,
                'days_remaining' => $daysLeft,
                'days_left_in_trial' => $daysLeft,
                'grace_days' => $graceDays,
                'expired' => false,
                'in_grace' => false,
                'read_only' => false,
                'reason' => null,
            ];
        }

        // Expirado pela data (mesmo que status ainda seja 'trialing') ou status='expired'
        $baseEndTs = $refTs !== false ? $refTs : $nowTs;
        $graceEndTs = $baseEndTs + ($graceDays * 86400);
        $graceEndsAt = date('Y-m-d H:i:s', $graceEndTs);

        if ($graceDays > 0 && $refTs !== false && $nowTs <= $graceEndTs) {
            $daysRemaining = max(1, (int)ceil(($graceEndTs - $nowTs) / 86400));
            return [
                'state' => 'grace',
                'status' => $status,
                'trial_ends_at' => $trialEndsAt,
                'grace_ends_at' => $graceEndsAt,
                'current_period_end' => $periodEnd,
                'days_remaining' => $daysRemaining,
                'days_left_in_trial' => 0,
                'grace_days' => $graceDays,
                'expired' => true,
                'in_grace' => true,
                'read_only' => false,
                'reason' => "Seu período de teste terminou. Você tem {$daysRemaining} dia(s) de carência antes do modo leitura.",
            ];
        }

        return [
            'state' => 'read_only',
            'status' => $status,
            'trial_ends_at' => $trialEndsAt,
            'grace_ends_at' => $graceEndsAt,
            'current_period_end' => $periodEnd,
            'days_remaining' => 0,
            'days_left_in_trial' => 0,
            'grace_days' => $graceDays,
            'expired' => true,
            'in_grace' => false,
            'read_only' => true,
            'reason' => 'Seu período de teste terminou há mais de ' . $graceDays . ' dia(s). A conta está em modo leitura (leitura e exportação de dados continuam liberadas). Assine ou peça ativação de um plano para voltar a criar ou editar registros.',
        ];
    }

    /**
     * NI2-878: aviso único de fim de teste (carência ou modo leitura), independente do cron.
     *
     * @return ?array{
     *   state:string,
     *   title:string,
     *   message:string,
     *   trial_ends_at:?string,
     *   grace_ends_at:?string,
     *   days_remaining:int,
     *   asaas_enabled:bool,
     *   cta_label:string,
     *   cta_href:string
     * }
     */
    public function expiredNotice(int $clinicId, ?bool $asaasEnabled = null, ?int $nowTimestamp = null): ?array
    {
        $ts = $this->trialState($clinicId, $nowTimestamp);
        if (!in_array($ts['state'], ['grace', 'read_only'], true)) {
            return null;
        }
        $asaas = $asaasEnabled ?? (new \ClyoNutri\Domain\Billing\AsaasCheckoutService($this->db, $this))->isEnabled();
        $endDateFmt = !empty($ts['trial_ends_at']) ? date('d/m/Y', strtotime((string)$ts['trial_ends_at'])) : null;
        $graceDateFmt = !empty($ts['grace_ends_at']) ? date('d/m/Y', strtotime((string)$ts['grace_ends_at'])) : null;

        if ($ts['state'] === 'grace') {
            $title = 'Seu período de teste terminou' . ($endDateFmt ? ' em ' . $endDateFmt : '') . ' (carência ativa)';
            $message = 'Você está no período de carência de ' . $ts['days_remaining'] . ' dia(s)'
                . ($graceDateFmt ? ' (até ' . $graceDateFmt . ')' : '')
                . '. Seus dados continuam salvos e o sistema segue liberado temporariamente. '
                . ($asaas ? 'Escolha um plano para assinar agora.' : 'Peça a ativação do seu plano para continuar sem interrupções.');
        } else {
            $title = 'Período de teste encerrado' . ($endDateFmt ? ' em ' . $endDateFmt : '') . ' — modo leitura';
            $message = 'O prazo de carência terminou e a conta entrou em modo leitura. Você ainda pode consultar e exportar todos os seus dados. '
                . ($asaas ? 'Assine um plano para voltar a criar e editar registros.' : 'Peça a ativação de um plano para liberar a criação e edição de registros.');
        }

        return [
            'state' => $ts['state'],
            'title' => $title,
            'message' => $message,
            'trial_ends_at' => $ts['trial_ends_at'],
            'grace_ends_at' => $ts['grace_ends_at'],
            'days_remaining' => $ts['days_remaining'],
            'asaas_enabled' => $asaas,
            'cta_label' => $asaas ? 'Assinar um plano' : 'Pedir ativação',
            'cta_href' => $asaas ? '/configuracoes/assinatura#plano' : '/configuracoes/assinatura#pedir-ativacao',
        ];
    }

    /**
     * NI2-863 (F-05) / NI2-878: regra única de acesso após o fim do teste grátis.
     * @return array{allowed:bool,read_only:bool,in_grace:bool,state:string,status:string,grace_days:int,reason:?string}
     */
    public function checkWriteAccess(int $clinicId, ?int $nowTimestamp = null): array
    {
        $ts = $this->trialState($clinicId, $nowTimestamp);
        return [
            'allowed' => !$ts['read_only'],
            'read_only' => $ts['read_only'],
            'in_grace' => $ts['in_grace'],
            'state' => $ts['state'],
            'status' => $ts['status'],
            'grace_days' => $ts['grace_days'],
            'reason' => $ts['reason'],
        ];
    }

    /**
     * NI2-878: registra pedido de ativação manual quando o Asaas não está configurado,
     * notifica o backoffice (/admin/notificacoes) e envia e-mail ao suporte.
     */
    public function requestManualActivation(
        int $clinicId,
        int $userId,
        int $planId,
        ?string $notes = null,
        ?\ClyoNutri\Core\Mail\Mailer $mailer = null
    ): array {
        if (!$this->canManageSubscription($clinicId, $userId)) {
            throw new \DomainException('Apenas titulares ou administradores podem pedir a ativação do plano.');
        }

        $stPlan = $this->db->prepare('SELECT id, code, name, price_cents FROM saas_plans WHERE id = ? AND is_active = 1 LIMIT 1');
        $stPlan->execute([$planId]);
        $plan = $stPlan->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new \InvalidArgumentException('Plano selecionado não encontrado ou inativo.');
        }

        $cleanNotes = $notes !== null ? trim(mb_substr($notes, 0, 500)) : null;
        if ($cleanNotes === '') {
            $cleanNotes = null;
        }

        $nowStr = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        $ins = $this->db->prepare(
            'INSERT INTO subscription_activation_requests (clinic_id, plan_id, plan_code, requested_by_user_id, notes, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$clinicId, (int)$plan['id'], (string)$plan['code'], $userId, $cleanNotes, 'pending', $nowStr]);
        $requestId = (int)$this->db->lastInsertId();

        $aud = $this->db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $aud->execute([
            $clinicId,
            $userId,
            'SUBSCRIPTION_ACTIVATION_REQUESTED',
            'subscription_activation_requests',
            (string)$requestId,
            json_encode(['plan_id' => (int)$plan['id'], 'plan_code' => $plan['code'], 'notes' => $cleanNotes], JSON_UNESCAPED_UNICODE),
            $nowStr,
        ]);

        (new \ClyoNutri\Domain\Notification\StaffNotificationService($this->db))->notifyActivationRequested($requestId);

        $supportEmail = \ClyoNutri\Core\Security\ContactInfo::supportEmail();
        try {
            $clinicStmt = $this->db->prepare('SELECT name FROM clinics WHERE id = ?');
            $clinicStmt->execute([$clinicId]);
            $clinicName = (string)($clinicStmt->fetchColumn() ?: ('Clínica #' . $clinicId));

            $userStmt = $this->db->prepare('SELECT name, email FROM users WHERE id = ?');
            $userStmt->execute([$userId]);
            $requester = $userStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Usuário #' . $userId, 'email' => ''];

            $textBody = "Pedido #{$requestId}\nClínica: {$clinicName} (ID {$clinicId})\nPlano solicitado: {$plan['name']} ({$plan['code']})\nSolicitante: {$requester['name']} ({$requester['email']})\nObservações: " . ($cleanNotes ?? 'Nenhuma') . "\n\nAtive em /admin/comercial#assinantes.";
            $msg = new \ClyoNutri\Core\Mail\MailMessage(
                toAddress: $supportEmail,
                toName: 'Suporte ClyoNutri',
                subject: "[ClyoNutri] Pedido de ativação de plano — {$clinicName} ({$plan['code']})",
                htmlBody: nl2br(htmlspecialchars($textBody, ENT_QUOTES, 'UTF-8')),
                textBody: $textBody,
                replyTo: !empty($requester['email']) && filter_var($requester['email'], FILTER_VALIDATE_EMAIL) ? (string)$requester['email'] : null
            );
            ($mailer ?? \ClyoNutri\Core\Mail\MailerFactory::fromEnv())->send($msg);
        } catch (\Throwable $e) {
            error_log('activation_request_email_failed: ' . $e->getMessage());
        }

        return [
            'id' => $requestId,
            'clinic_id' => $clinicId,
            'plan_id' => (int)$plan['id'],
            'plan_code' => (string)$plan['code'],
            'plan_name' => (string)$plan['name'],
            'status' => 'pending',
            'created_at' => $nowStr,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function pendingActivationRequestsForClinic(int $clinicId): array
    {
        if (!\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'subscription_activation_requests')) {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT r.*, p.name AS plan_name FROM subscription_activation_requests r
             JOIN saas_plans p ON p.id = r.plan_id
             WHERE r.clinic_id = ? AND r.status = ? ORDER BY r.id DESC LIMIT 5'
        );
        $st->execute([$clinicId, 'pending']);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listActivationRequestsForAdmin(int $limit = 20): array
    {
        if (!\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'subscription_activation_requests')) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $st = $this->db->query(
            "SELECT r.*, c.name AS clinic_name, p.name AS plan_name, u.name AS requester_name, u.email AS requester_email
             FROM subscription_activation_requests r
             JOIN clinics c ON c.id = r.clinic_id
             JOIN saas_plans p ON p.id = r.plan_id
             JOIN users u ON u.id = r.requested_by_user_id
             WHERE r.status = 'pending'
             ORDER BY r.id DESC LIMIT {$limit}"
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * NI2-878: ativação / troca manual de plano pelo console (/admin/comercial).
     * Libera escrita na hora, grava histórico em subscription_plan_changes e audita SUBSCRIPTION_MANUALLY_ACTIVATED.
     */
    public function activateManuallyByAdmin(
        int $clinicId,
        int $planId,
        string $validUntilDate,
        string $reason,
        int $actorUserId,
        string $ip = '127.0.0.1',
        ?string $userAgent = null
    ): array {
        $reasonTrim = trim($reason);
        if ($reasonTrim === '') {
            throw new \InvalidArgumentException('Informe o motivo da ativação manual.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($validUntilDate))) {
            throw new \InvalidArgumentException('Data de validade inválida. Use o formato YYYY-MM-DD.');
        }
        $validDt = \DateTimeImmutable::createFromFormat('Y-m-d', trim($validUntilDate));
        if (!$validDt || $validDt->format('Y-m-d') !== trim($validUntilDate)) {
            throw new \InvalidArgumentException('Data de validade inválida.');
        }
        $periodEndStr = $validDt->format('Y-m-d') . ' 23:59:59';

        $stPlan = $this->db->prepare('SELECT * FROM saas_plans WHERE id = ? LIMIT 1');
        $stPlan->execute([$planId]);
        $plan = $stPlan->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new \InvalidArgumentException('Plano alvo não encontrado.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $stSub = $this->db->prepare(
                'SELECT cs.*, p.code AS plan_code FROM clinic_subscriptions cs
                 LEFT JOIN saas_plans p ON p.id = cs.plan_id
                 WHERE cs.clinic_id = ? LIMIT 1'
            );
            $stSub->execute([$clinicId]);
            $currentSub = $stSub->fetch(PDO::FETCH_ASSOC);

            if (!$currentSub) {
                $ins = $this->db->prepare(
                    'INSERT INTO clinic_subscriptions (clinic_id, plan_id, status, billing_cycle, price_cents, started_at, current_period_start, current_period_end, trial_ends_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $clinicId,
                    (int)$plan['id'],
                    'active',
                    (string)$plan['billing_cycle'],
                    (int)$plan['price_cents'],
                    $nowStr,
                    $nowStr,
                    $periodEndStr,
                    $nowStr,
                    $nowStr,
                ]);
                $subId = (int)$this->db->lastInsertId();
                $fromPlanId = (int)$plan['id'];
                $fromPlanCode = (string)$plan['code'];
                $fromPriceCents = (int)$plan['price_cents'];
                $prevStatus = 'none';
            } else {
                $subId = (int)$currentSub['id'];
                $fromPlanId = (int)$currentSub['plan_id'];
                $fromPlanCode = (string)($currentSub['plan_code'] ?? $plan['code']);
                $fromPriceCents = (int)($currentSub['price_cents'] ?? 0);
                $prevStatus = (string)($currentSub['status'] ?? '');

                $upd = $this->db->prepare(
                    'UPDATE clinic_subscriptions
                     SET plan_id = ?, status = ?, billing_cycle = ?, price_cents = ?,
                         current_period_start = ?, current_period_end = ?,
                         canceled_at = NULL, cancellation_protocol = NULL, cancellation_reason_category = NULL, cancellation_feedback = NULL,
                         updated_at = ?
                     WHERE id = ? AND clinic_id = ?'
                );
                $upd->execute([
                    (int)$plan['id'],
                    'active',
                    (string)$plan['billing_cycle'],
                    (int)$plan['price_cents'],
                    $nowStr,
                    $periodEndStr,
                    $nowStr,
                    $subId,
                    $clinicId,
                ]);
            }

            $protocol = 'ACT-' . $now->format('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'subscription_plan_changes')) {
                $insChange = $this->db->prepare(
                    'INSERT INTO subscription_plan_changes
                     (clinic_id, subscription_id, protocol, change_type, from_plan_id, to_plan_id, from_plan_code, to_plan_code,
                      from_price_cents, to_price_cents, subscription_status, billing_mode, usage_json, idempotency_key, requested_by, effective_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insChange->execute([
                    $clinicId,
                    $subId,
                    $protocol,
                    'manual_activation',
                    $fromPlanId,
                    (int)$plan['id'],
                    $fromPlanCode,
                    (string)$plan['code'],
                    $fromPriceCents,
                    (int)$plan['price_cents'],
                    'active',
                    'manual_admin',
                    json_encode(['reason' => $reasonTrim, 'valid_until' => $validDt->format('Y-m-d')], JSON_UNESCAPED_UNICODE),
                    null,
                    $actorUserId,
                    $nowStr,
                    $nowStr,
                ]);
            }

            if (\ClyoNutri\Core\Database\SchemaInfo::hasTable($this->db, 'subscription_activation_requests')) {
                $updReq = $this->db->prepare(
                    'UPDATE subscription_activation_requests
                     SET status = ?, processed_by_user_id = ?, processed_at = ?
                     WHERE clinic_id = ? AND status = ?'
                );
                $updReq->execute(['activated', $actorUserId, $nowStr, $clinicId, 'pending']);
            }

            $aud = $this->db->prepare(
                'INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $aud->execute([
                $clinicId,
                $actorUserId,
                'SUBSCRIPTION_MANUALLY_ACTIVATED',
                'clinic_subscriptions',
                (string)$subId,
                $ip,
                $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                json_encode([
                    'protocol' => $protocol,
                    'actor_user_id' => $actorUserId,
                    'previous_status' => $prevStatus,
                    'from_plan_code' => $fromPlanCode,
                    'to_plan_code' => (string)$plan['code'],
                    'valid_until' => $validDt->format('Y-m-d'),
                    'reason' => $reasonTrim,
                ], JSON_UNESCAPED_UNICODE),
                $nowStr,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'subscription_id' => $subId,
            'clinic_id' => $clinicId,
            'plan_code' => (string)$plan['code'],
            'plan_name' => (string)$plan['name'],
            'status' => 'active',
            'current_period_end' => $periodEndStr,
            'protocol' => $protocol,
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
