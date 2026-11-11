<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Billing;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Database\SchemaInfo;
use ClyoNutri\Domain\Subscription\PlanChangeService;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use PDO;

/**
 * NI2-727 slice 1 — contratação de plano pago via Checkout hospedado do Asaas (cartão, RECURRENT).
 *
 * Invariantes:
 *  - A tentativa é persistida ANTES de chamar o gateway, com chave de idempotência e externalReference.
 *  - Criar checkout NÃO altera o plano. O upgrade só acontece em AsaasWebhookService, com evento financeiro.
 *  - Nenhum dado de cartão passa por aqui: o pagador usa a página hospedada do Asaas.
 *  - RBAC: somente titular/administrador (SubscriptionService::canManageSubscription), isolado por clinic_id.
 */
final class AsaasCheckoutService
{
    public function __construct(
        private ?PDO $db = null,
        private ?SubscriptionService $subscriptions = null,
        private ?AsaasConfig $config = null
    ) {
        $this->db = $this->db ?? Connection::getInstance();
        $this->subscriptions = $this->subscriptions ?? new SubscriptionService($this->db);
        $this->config = $this->config ?? AsaasConfig::fromEnv();
    }

    public static function available(PDO $db): bool
    {
        return SchemaInfo::hasTable($db, 'asaas_checkouts');
    }

    public function isEnabled(): bool
    {
        return $this->config->canStartCheckout() && self::available($this->db);
    }

    /** Último checkout da clínica (para a UI mostrar "aguardando pagamento" vs. ativo). */
    public function latestForClinic(int $clinicId): ?array
    {
        if (!self::available($this->db)) {
            return null;
        }
        $st = $this->db->prepare('SELECT c.*, p.name AS plan_name, p.code AS plan_code FROM asaas_checkouts c
            JOIN saas_plans p ON p.id = c.plan_id WHERE c.clinic_id = ? ORDER BY c.id DESC LIMIT 1');
        $st->execute([$clinicId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Inicia a contratação. Retorna ['hosted_url' => ..., 'external_reference' => ..., 'reused' => bool].
     * NUNCA muda plan_id/status da assinatura: isso é exclusivo do webhook financeiro.
     */
    public function startCheckout(int $clinicId, int $userId, int $planId, ?string $idempotencyKey = null, ?string $ip = null, ?string $userAgent = null): array
    {
        if (!$this->subscriptions->canManageSubscription($clinicId, $userId)) {
            throw new \DomainException('Apenas os titulares ou administradores do consultório podem assinar um plano.');
        }
        if (!self::available($this->db)) {
            throw new \DomainException('A contratação online ainda não está disponível neste ambiente. Fale com o suporte.');
        }
        $sub = $this->subscriptions->getClinicSubscription($clinicId);
        if (!in_array((string)$sub['status'], ['trialing', 'active', 'expired'], true)) {
            throw new \DomainException('A assinatura não está em uma situação que permita contratar agora. Fale com o suporte.');
        }
        if (!$this->config->isSandbox() || !$this->config->hasApiKey()) {
            throw new \DomainException('A contratação via Asaas não está configurada neste ambiente.');
        }
        $this->config->assertCoherent();
        if ($this->config->appUrl === '') {
            throw new \DomainException('Configure APP_URL antes de iniciar um checkout (URLs de retorno do Asaas).');
        }

        $key = $idempotencyKey !== null && preg_match('/^[a-f0-9]{16,64}$/', $idempotencyKey) === 1 ? $idempotencyKey : null;
        if ($key !== null && ($existing = $this->findByKey($clinicId, $key)) !== null) {
            return $this->resultFromRow($existing, true);
        }

        $plan = null;
        foreach ((new PlanChangeService($this->db, $this->subscriptions))->plans() as $p) {
            if ((int)$p['id'] === $planId) {
                $plan = $p;
                break;
            }
        }
        if ($plan === null) {
            throw new \DomainException('Este plano não está disponível.');
        }
        if ((int)$plan['id'] === (int)$sub['plan_id'] && (string)$sub['status'] === 'active') {
            throw new \DomainException('Você já está neste plano com a assinatura ativa.');
        }

        // Já existe checkout aguardando pagamento para este plano: reutiliza o link, não duplica no gateway.
        $pending = $this->pendingForClinicPlan($clinicId, (int)$plan['id']);
        if ($pending !== null && !empty($pending['hosted_url'])) {
            return $this->resultFromRow($pending, true);
        }

        // Recusa cadastro incompleto antes de persistir tentativa ou chamar o gateway.
        $customerPayload = $this->customerPayload($clinicId, $userId);

        $externalReference = 'clyo-c' . $clinicId . '-p' . $plan['id'] . '-' . bin2hex(random_bytes(8));
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

        // 1) Persiste a tentativa ANTES do gateway.
        $ins = $this->db->prepare('INSERT INTO asaas_checkouts
            (clinic_id, subscription_id, plan_id, requested_by, idempotency_key, external_reference, amount_cents,
             billing_cycle, status, from_plan_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?)');
        $ins->execute([
            $clinicId, (int)$sub['id'], (int)$plan['id'], $userId, $key, $externalReference,
            (int)$plan['price_cents'], (string)$plan['billing_cycle'], (int)$sub['plan_id'], $now, $now,
        ]);
        $checkoutId = (int)$this->db->lastInsertId();

        try {
            // 2) Cliente Asaas (1 por clínica, reutilizado entre tentativas).
            $asaasCustomerId = $this->ensureCustomer($clinicId, $customerPayload);

            // 3) Checkout hospedado, cartão, cobrança recorrente mensal. Primeira cobrança amanhã.
            $nextDue = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
            $payload = [
                'billingTypes' => ['CREDIT_CARD'],
                'chargeTypes' => ['RECURRENT'],
                'minutesToExpire' => 1440,
                'externalReference' => $externalReference,
                'customer' => $asaasCustomerId,
                'callback' => [
                    'successUrl' => $this->config->appUrl . '/configuracoes/assinatura?asaas=retorno',
                    'cancelUrl' => $this->config->appUrl . '/configuracoes/assinatura?asaas=cancelado',
                    'expiredUrl' => $this->config->appUrl . '/configuracoes/assinatura?asaas=expirado',
                ],
                'items' => [[
                    'name' => mb_substr('Clyo Nutri ' . $plan['code'], 0, 30),
                    'description' => mb_substr('Assinatura mensal ' . $plan['name'], 0, 150),
                    'quantity' => 1,
                    'value' => round(((int)$plan['price_cents']) / 100, 2),
                    'externalReference' => $externalReference,
                ]],
                'subscription' => [
                    'cycle' => 'MONTHLY',
                    'nextDueDate' => $nextDue,
                ],
            ];
            $response = (new AsaasClient($this->config))->createCheckout($payload);
            $hostedUrl = (string)($response['link'] ?? '');
            $asaasCheckoutId = (string)($response['id'] ?? '');
            if ($hostedUrl === '' || $asaasCheckoutId === '') {
                throw new AsaasException('O Asaas não devolveu o link do checkout.', 200);
            }
            if (!str_starts_with($hostedUrl, 'https://sandbox.asaas.com/') && !str_starts_with($hostedUrl, 'https://www.asaas.com/')) {
                throw new AsaasException('Link de checkout fora do domínio esperado do Asaas.', 200);
            }

            $upd = $this->db->prepare('UPDATE asaas_checkouts SET asaas_customer_id = ?, asaas_checkout_id = ?, hosted_url = ?,
                status = \'awaiting_payment\', updated_at = ? WHERE id = ? AND clinic_id = ?');
            $upd->execute([$asaasCustomerId, $asaasCheckoutId, $hostedUrl, $now, $checkoutId, $clinicId]);

            $this->audit($clinicId, $userId, 'subscription.asaas_checkout_created', (string)$checkoutId, [
                'external_reference' => $externalReference,
                'plan_code' => (string)$plan['code'],
                'amount_cents' => (int)$plan['price_cents'],
                'asaas_checkout_id' => $asaasCheckoutId,
            ], $ip, $userAgent);

            $row = $this->findById($checkoutId);
            return $this->resultFromRow($row ?? [], false);
        } catch (\Throwable $e) {
            $this->db->prepare('UPDATE asaas_checkouts SET status = \'failed\', updated_at = ? WHERE id = ?')
                ->execute([$now, $checkoutId]);
            if ($e instanceof \DomainException || $e instanceof AsaasException) {
                throw $e;
            }
            throw new AsaasException('Não foi possível criar o checkout no Asaas. Nada foi cobrado.', 0);
        }
    }

    /** @param array<string,mixed> $payload Dados já validados antes de persistir a tentativa. */
    private function ensureCustomer(int $clinicId, array $payload): string
    {
        $client = new AsaasClient($this->config);
        $st = $this->db->prepare('SELECT asaas_customer_id FROM asaas_customers WHERE clinic_id = ?');
        $st->execute([$clinicId]);
        $existing = $st->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            // O cliente pode ter sido criado por uma versão anterior, sem telefone/endereço.
            // Toda nova tentativa atualiza o cadastro antes de criar o checkout.
            $client->updateCustomer($existing, $payload);
            return $existing;
        }

        $payload['notificationDisabled'] = true;
        $customer = $client->createCustomer($payload);
        $id = (string)($customer['id'] ?? '');
        if ($id === '') {
            throw new AsaasException('O Asaas não devolveu o identificador do cliente.', 200);
        }
        $ins = $this->db->prepare('INSERT INTO asaas_customers (clinic_id, asaas_customer_id) VALUES (?, ?)');
        $ins->execute([$clinicId, $id]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function customerPayload(int $clinicId, int $userId): array
    {
        $st = $this->db->prepare('SELECT name, document, email, phone, address_street, address_number, address_complement,
            address_neighborhood, address_city, address_state, address_zip FROM clinics WHERE id = ?');
        $st->execute([$clinicId]);
        $clinic = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $phone = preg_replace('/\D+/', '', (string)($clinic['phone'] ?? ''));
        if ($phone === '') {
            // O perfil antigo só tinha o celular do profissional. Reutiliza-o sem exigir redigitação.
            $st = $this->db->prepare("SELECT u.phone FROM users u JOIN clinic_members cm ON cm.user_id = u.id
                WHERE u.id = ? AND cm.clinic_id = ? AND u.status = 'active' AND cm.status = 'active' LIMIT 1");
            $st->execute([$userId, $clinicId]);
            $phone = preg_replace('/\D+/', '', (string)$st->fetchColumn());
        }
        $document = preg_replace('/\D+/', '', (string)($clinic['document'] ?? ''));
        $email = trim((string)($clinic['email'] ?? ''));
        $zip = preg_replace('/\D+/', '', (string)($clinic['address_zip'] ?? ''));
        $address = [
            'address' => trim((string)($clinic['address_street'] ?? '')),
            'addressNumber' => trim((string)($clinic['address_number'] ?? '')),
            'province' => trim((string)($clinic['address_neighborhood'] ?? '')),
            'city' => trim((string)($clinic['address_city'] ?? '')),
            'state' => strtoupper(trim((string)($clinic['address_state'] ?? ''))),
            'postalCode' => $zip,
        ];
        $missing = [];
        if (!in_array(strlen($document), [11, 14], true)) $missing[] = 'CPF/CNPJ';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $missing[] = 'e-mail válido';
        if (strlen($phone) < 10 || strlen($phone) > 15) $missing[] = 'telefone com DDD';
        $labels = ['address' => 'rua', 'addressNumber' => 'número', 'province' => 'bairro', 'city' => 'cidade', 'state' => 'UF', 'postalCode' => 'CEP'];
        foreach ($address as $field => $value) {
            if ($value === '' || ($field === 'state' && strlen($value) !== 2) || ($field === 'postalCode' && strlen($value) !== 8)) {
                $missing[] = $labels[$field];
            }
        }
        if ($missing !== []) {
            throw new \DomainException('Para assinar, complete em Configurações → Perfil → Dados do consultório para cobrança: '
                . implode(', ', $missing) . '. Nenhuma cobrança foi criada.');
        }

        return [
            'name' => mb_substr((string)($clinic['name'] ?? ('Clinica ' . $clinicId)), 0, 100),
            'cpfCnpj' => $document,
            'email' => $email,
            'phone' => $phone,
            'mobilePhone' => $phone,
            'address' => $address['address'],
            'addressNumber' => $address['addressNumber'],
            'complement' => trim((string)($clinic['address_complement'] ?? '')),
            'province' => $address['province'],
            'city' => $address['city'],
            'state' => $address['state'],
            'postalCode' => $zip,
            'externalReference' => 'clyo-clinic-' . $clinicId,
        ];
    }

    private function pendingForClinicPlan(int $clinicId, int $planId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM asaas_checkouts WHERE clinic_id = ? AND plan_id = ?
            AND status IN (\'pending\', \'awaiting_payment\') ORDER BY id DESC LIMIT 1');
        $st->execute([$clinicId, $planId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findByKey(int $clinicId, string $key): ?array
    {
        $st = $this->db->prepare('SELECT * FROM asaas_checkouts WHERE clinic_id = ? AND idempotency_key = ?');
        $st->execute([$clinicId, $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findById(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM asaas_checkouts WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function resultFromRow(array $row, bool $reused): array
    {
        return [
            'success' => true,
            'reused' => $reused,
            'hosted_url' => (string)($row['hosted_url'] ?? ''),
            'external_reference' => (string)($row['external_reference'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'message' => $reused
                ? 'Este checkout já estava aberto. Continue o pagamento na página segura do Asaas.'
                : 'Checkout criado. Conclua o pagamento na página segura do Asaas; o plano só muda após a confirmação do pagamento.',
        ];
    }

    private function audit(int $clinicId, ?int $userId, string $action, string $resourceId, array $details, ?string $ip, ?string $userAgent): void
    {
        $st = $this->db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, ip_address, user_agent, details, created_at)
            VALUES (?, ?, ?, \'asaas_checkouts\', ?, ?, ?, ?, ?)');
        $st->execute([
            $clinicId, $userId, $action, $resourceId, $ip,
            $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
        ]);
    }
}
