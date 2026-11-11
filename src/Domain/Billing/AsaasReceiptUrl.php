<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Billing;

/**
 * v54.51 — Escolhe e valida a URL pública de recibo/fatura de um objeto payment do Asaas.
 * Prioridade: transactionReceiptUrl (comprovante do pagamento) > invoiceUrl (fatura) > bankSlipUrl (boleto).
 * Só aceita https em hosts do Asaas; nunca monta URL a partir do id (não inventa link).
 */
final class AsaasReceiptUrl
{
    private const FIELDS = ['transactionReceiptUrl', 'invoiceUrl', 'bankSlipUrl'];
    private const HOSTS = ['sandbox.asaas.com', 'www.asaas.com', 'asaas.com'];

    /** @param array<string,mixed> $payment */
    public static function pick(array $payment): ?string
    {
        foreach (self::FIELDS as $f) {
            $u = isset($payment[$f]) && is_string($payment[$f]) ? trim($payment[$f]) : '';
            if ($u !== '' && self::isSafe($u)) {
                return $u;
            }
        }
        return null;
    }

    public static function isSafe(?string $url): bool
    {
        if ($url === null || $url === '' || strlen($url) > 500 || preg_match('/[\s"\'<>]/', $url)) {
            return false;
        }
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }
        return in_array(strtolower((string)($p['host'] ?? '')), self::HOSTS, true);
    }
}
