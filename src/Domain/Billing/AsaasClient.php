<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Billing;

/**
 * Cliente HTTP mínimo do Asaas v3 (NI2-727 slice 1). Sem dependência nova: cURL do PHP.
 * Autenticação no header access_token, conforme a referência oficial.
 * Nunca registra a chave; respostas de erro viram AsaasException com a descrição do gateway.
 */
final class AsaasClient
{
    public function __construct(private AsaasConfig $config)
    {
        $config->assertCoherent();
    }

    public static function fromEnv(): self
    {
        return new self(AsaasConfig::fromEnv());
    }

    /** @param array<string,mixed> $payload */
    public function createCustomer(array $payload): array
    {
        return $this->request('POST', '/customers', $payload);
    }

    /** @param array<string,mixed> $payload */
    public function createCheckout(array $payload): array
    {
        return $this->request('POST', '/checkouts', $payload);
    }

    /** Atualiza dados cadastrais do cliente (telefone exigido pelo checkout recorrente). */
    public function updateCustomer(string $customerId, array $data): array
    {
        return $this->request('POST', '/customers/' . rawurlencode($customerId), $data);
    }

    public function getPayment(string $paymentId): array
    {
        if (preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId) !== 1) {
            throw new \InvalidArgumentException('Identificador de cobrança inválido.');
        }
        return $this->request('GET', '/payments/' . $paymentId);
    }

    /** @param array<string,mixed>|null $payload */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!$this->config->hasApiKey()) {
            throw new AsaasException('ASAAS_API_KEY não configurada no servidor.', 0);
        }

        $ch = curl_init($this->config->baseUrl . $path);
        if ($ch === false) {
            throw new AsaasException('Não foi possível iniciar a conexão com o Asaas.', 0);
        }
        $headers = [
            'access_token: ' . $this->config->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ClyoNutri/54.49 (NI2-727 sandbox)',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new AsaasException('Falha de comunicação com o Asaas (cURL ' . $errno . '). Nada foi cobrado.', 0);
        }
        $decoded = json_decode((string)$body, true);
        if ($status >= 400) {
            $description = '';
            if (is_array($decoded) && isset($decoded['errors'][0]['description'])) {
                $description = (string)$decoded['errors'][0]['description'];
            } elseif (is_array($decoded) && isset($decoded['errors'][0]['code'])) {
                $description = (string)$decoded['errors'][0]['code'];
            }
            throw new AsaasException(
                'Asaas recusou a operação (HTTP ' . $status . ')' . ($description !== '' ? ': ' . $description : '.'),
                $status
            );
        }
        if (!is_array($decoded)) {
            throw new AsaasException('Resposta inesperada do Asaas (HTTP ' . $status . ').', $status);
        }

        return $decoded;
    }
}
