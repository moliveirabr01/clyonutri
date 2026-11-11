<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Billing;

use ClyoNutri\Core\Database\Connection;
use PDO;

/**
 * NI2-727 slice 1 — recepção e reconciliação dos webhooks do Asaas.
 *
 * Somente eventos FINANCEIROS confirmados ativam o plano:
 *   PAYMENT_CONFIRMED, PAYMENT_RECEIVED e CHECKOUT_PAID.
 * PAYMENT_CREATED / CHECKOUT_CREATED / redirecionamento do successUrl NUNCA ativam nada.
 *
 * Cada evento é autenticado (asaas-access-token), deduplicado por id, reconciliado pelo
 * externalReference persistido antes da chamada ao gateway e conferido em valor e plano.
 * Nada é estornado/rebaixado neste slice: eventos de estorno ficam registrados para o backoffice.
 */
final class AsaasWebhookService
{
    private const ACTIVATING_EVENTS = ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED', 'CHECKOUT_PAID'];

    public function __construct(private ?PDO $db = null, private ?AsaasConfig $config = null)
    {
        $this->db = $this->db ?? Connection::getInstance();
        $this->config = $this->config ?? AsaasConfig::fromEnv();
    }

    /** Comparação em tempo constante do header asaas-access-token. */
    public function tokenIsValid(?string $provided): bool
    {
        // Lê o ambiente a cada chamada: rotação de ASAAS_WEBHOOK_TOKEN sem reiniciar o processo.
        $token = AsaasConfig::fromEnv()->webhookToken;
        if (strlen($token) < 32 || $provided === null || $provided === '') {
            return false;
        }
        return hash_equals($token, $provided);
    }

    /**
     * Processa um evento já autenticado. Retorna ['status' => ..., 'detail' => ...].
     * Lança DomainException para evento inconsistente (o endpoint responde 422 para o Asaas reenviar).
     */
    public function handle(array $payload): array
    {
        $eventId = trim((string)($payload['id'] ?? ''));
        $eventType = strtoupper(trim((string)($payload['event'] ?? '')));
        if ($eventId === '' || $eventType === '') {
            throw new \DomainException('Evento sem id ou tipo.');
        }

        // Idempotência: o mesmo event_id nunca é processado duas vezes.
        $st = $this->db->prepare('SELECT processing_status, detail FROM asaas_webhook_events WHERE event_id = ?');
        $st->execute([$eventId]);
        $seen = $st->fetch(PDO::FETCH_ASSOC);
        if ($seen) {
            return ['status' => 'duplicate', 'detail' => (string)($seen['detail'] ?? 'Evento já processado.')];
        }

        $object = (array)($payload['payment'] ?? $payload['checkout'] ?? $payload['subscription'] ?? []);
        $externalReference = trim((string)($object['externalReference'] ?? ''));
        $paymentId = isset($object['id']) && str_starts_with((string)$object['id'], 'pay_') ? (string)$object['id'] : null;

        $result = $this->dispatch($eventType, $externalReference, $object, $eventId);

        $ins = $this->db->prepare('INSERT INTO asaas_webhook_events
            (event_id, event_type, external_reference, clinic_id, checkout_id, payment_id, processing_status, detail, processed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([
            $eventId, $eventType, $externalReference !== '' ? $externalReference : null,
            $result['clinic_id'] ?? null, $result['checkout_id'] ?? null, $paymentId,
            $result['status'], mb_substr((string)$result['detail'], 0, 255),
            (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
        ]);

        return $result;
    }

    private function dispatch(string $eventType, string $externalReference, array $object, string $eventId): array
    {
        // Eventos que não ativam nada: apenas registrados (inclui PAYMENT_CREATED e CHECKOUT_CREATED).
        if (!in_array($eventType, self::ACTIVATING_EVENTS, true)) {
            $ref = $externalReference !== '' ? $externalReference : null;
            $clinicId = null;
            if ($ref !== null && ($checkout = $this->findCheckoutByReference($ref)) !== null) {
                $clinicId = (int)$checkout['clinic_id'];
                if (in_array($eventType, ['CHECKOUT_CANCELED', 'CHECKOUT_EXPIRED'], true)
                    && in_array((string)$checkout['status'], ['pending', 'awaiting_payment'], true)) {
                    $this->db->prepare('UPDATE asaas_checkouts SET status = ?, updated_at = ? WHERE id = ?')
                        ->execute([$eventType === 'CHECKOUT_CANCELED' ? 'canceled' : 'expired',
                            (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
                            (int)$checkout['id']]);
                }
            }
            return ['status' => 'ignored', 'detail' => 'Evento ' . $eventType . ' registrado sem ativação de plano.',
                'clinic_id' => $clinicId];
        }

        if ($externalReference === '') {
            throw new \DomainException('Evento financeiro sem externalReference: impossível reconciliar.');
        }
        $checkout = $this->findCheckoutByReference($externalReference);
        if ($checkout === null) {
            throw new \DomainException('Nenhum checkout registrado para esta referência.');
        }

        $clinicId = (int)$checkout['clinic_id'];
        $checkoutId = (int)$checkout['id'];
        if ((string)$checkout['status'] === 'active') {
            return ['status' => 'already_active', 'detail' => 'Plano já ativo para este checkout.',
                'clinic_id' => $clinicId, 'checkout_id' => $checkoutId];
        }

        // Claim atômico: se outra entrega concorrente já ativou este checkout, não repetimos ativação/fatura.
        $claim = $this->db->prepare("UPDATE asaas_checkouts SET last_event_id = ?, updated_at = ?
            WHERE id = ? AND status <> 'active'");
        $claim->execute([$eventId,
            (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'), $checkoutId]);
        if ($claim->rowCount() === 0) {
            return ['status' => 'already_active', 'detail' => 'Plano já ativo para este checkout.',
                'clinic_id' => $clinicId, 'checkout_id' => $checkoutId];
        }

        // Reconciliação de valor: o pago precisa cobrir o valor do plano contratado.
        // Sem value no payload NÃO ativamos: o valor contratado não é prova de pagamento (fail-closed),
        // e o Asaas reenvia o evento completo ao receber 422.
        $paidCents = isset($object['value']) ? (int)round(((float)$object['value']) * 100) : 0;
        if ($paidCents < (int)$checkout['amount_cents']) {
            throw new \DomainException('Valor do evento diverge do valor do plano contratado.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
        $nowStr = $now->format('Y-m-d H:i:s');
        $paidAt = (string)($object['paymentDate'] ?? $object['confirmedDate'] ?? $object['dateCreated'] ?? $nowStr);
        $periodEnd = $now->modify('+1 month')->format('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            // Ativa o plano contratado. Uma vez ativo, eventos antigos de outros checkouts não rebaixam.
            $current = $this->db->prepare('SELECT status FROM clinic_subscriptions WHERE id = ? AND clinic_id = ?');
            $current->execute([(int)$checkout['subscription_id'], $clinicId]);
            if ($current->fetchColumn() === false) {
                throw new \DomainException('Assinatura da clínica não encontrada.');
            }
            $upd = $this->db->prepare('UPDATE clinic_subscriptions SET plan_id = ?, price_cents = ?, billing_cycle = ?,
                status = \'active\', current_period_start = ?, current_period_end = ?, updated_at = ?
                WHERE id = ? AND clinic_id = ?');
            $upd->execute([
                (int)$checkout['plan_id'], (int)$checkout['amount_cents'], (string)$checkout['billing_cycle'],
                $nowStr, $periodEnd, $nowStr, (int)$checkout['subscription_id'], $clinicId,
            ]);

            $updCo = $this->db->prepare('UPDATE asaas_checkouts SET status = \'active\', activated_at = ?, last_event_id = ?,
                updated_at = ? WHERE id = ? AND clinic_id = ? AND status <> \'active\'');
            $updCo->execute([$nowStr, $eventId, $nowStr, $checkoutId, $clinicId]);

            // Fatura REAL, originada do evento financeiro (NI2-450). Número = id do pagamento no gateway.
            $invoiceNumber = 'ASAAS-' . ($object['id'] ?? $eventId);
            $invoiceId = $this->insertInvoice($clinicId, (int)$checkout['subscription_id'], $invoiceNumber,
                $paidCents > 0 ? $paidCents : (int)$checkout['amount_cents'],
                (string)($object['dueDate'] ?? $now->format('Y-m-d')), $paidAt,
                (string)($object['billingType'] ?? 'CREDIT_CARD'));

            if (isset($object['id']) && str_starts_with((string)$object['id'], 'pay_')) {
                $rec = $this->db->prepare('INSERT INTO asaas_payment_receipts
                    (clinic_id, checkout_id, invoice_id, asaas_payment_id, asaas_subscription_id, event_type, amount_cents, paid_at, receipt_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $rec->execute([
                    $clinicId, $checkoutId, $invoiceId, (string)$object['id'],
                    isset($object['subscription']) ? (string)$object['subscription'] : null,
                    $eventType, $paidCents > 0 ? $paidCents : (int)$checkout['amount_cents'], $paidAt,
                    AsaasReceiptUrl::pick($object), // v54.51: link do recibo/fatura, só https em host Asaas
                ]);
            }

            $aud = $this->db->prepare('INSERT INTO audit_logs (clinic_id, user_id, action, resource, resource_id, details, created_at)
                VALUES (?, NULL, \'subscription.asaas_activated\', \'clinic_subscriptions\', ?, ?, ?)');
            $aud->execute([
                $clinicId, (string)$checkout['subscription_id'],
                json_encode([
                    'event' => $eventType, 'event_id' => $eventId,
                    'plan_id' => (int)$checkout['plan_id'], 'amount_cents' => $paidCents,
                    'external_reference' => $externalReference,
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

        return ['status' => 'activated', 'detail' => 'Plano ativado após confirmação financeira (' . $eventType . ').',
            'clinic_id' => $clinicId, 'checkout_id' => $checkoutId];
    }

    private function insertInvoice(int $clinicId, int $subscriptionId, string $invoiceNumber, int $amountCents, string $dueDate, string $paidAt, string $method): int
    {
        // Reenvio com outro event_id mas mesmo pagamento: devolve a fatura já criada.
        $st = $this->db->prepare('SELECT id FROM subscription_invoices WHERE invoice_number = ?');
        $st->execute([$invoiceNumber]);
        $existing = $st->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }
        $st = $this->db->prepare('INSERT INTO subscription_invoices
            (clinic_id, subscription_id, invoice_number, amount_cents, status, due_date, paid_at, payment_method)
            VALUES (?, ?, ?, ?, \'paid\', ?, ?, ?)');
        $st->execute([$clinicId, $subscriptionId, mb_substr($invoiceNumber, 0, 50), $amountCents, $dueDate, $paidAt, mb_substr($method, 0, 50)]);
        return (int)$this->db->lastInsertId();
    }

    private function findCheckoutByReference(string $reference): ?array
    {
        if (strlen($reference) > 100) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM asaas_checkouts WHERE external_reference = ?');
        $st->execute([$reference]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
