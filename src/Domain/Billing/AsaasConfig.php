<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Billing;

use ClyoNutri\Core\Security\Env;

/**
 * Configuração do gateway Asaas (NI2-727 slice 1). Segredos SÓ no servidor (.env / ambiente).
 * Fail closed: sandbox nunca aponta para a URL de produção e vice-versa.
 */
final class AsaasConfig
{
    public const SANDBOX_BASE_URL = 'https://api-sandbox.asaas.com/v3';
    public const PRODUCTION_BASE_URL = 'https://api.asaas.com/v3';

    private function __construct(
        public readonly string $env,
        public readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly string $webhookToken,
        public readonly string $appUrl
    ) {
    }

    public static function fromEnv(): self
    {
        $env = strtolower(trim((string)(Env::get('ASAAS_ENV') ?? 'sandbox')));
        $apiKey = trim((string)(Env::get('ASAAS_API_KEY') ?? ''));
        $baseUrl = rtrim(trim((string)(Env::get('ASAAS_BASE_URL') ?? '')), '/');
        if ($baseUrl === '') {
            $baseUrl = $env === 'production' ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
        }

        return new self(
            $env,
            $apiKey,
            $baseUrl,
            trim((string)(Env::get('ASAAS_WEBHOOK_TOKEN') ?? '')),
            rtrim(trim((string)(Env::get('APP_URL') ?? '')), '/')
        );
    }

    /** Slice 1 é sandbox-only: a UI do botão e o endpoint exigem isto. */
    public function isSandbox(): bool
    {
        return $this->env === 'sandbox';
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function hasWebhookToken(): bool
    {
        return strlen($this->webhookToken) >= 32;
    }

    public function canStartCheckout(): bool
    {
        return $this->isSandbox() && $this->hasApiKey() && $this->appUrl !== '';
    }

    /** Recusa configuração incoerente em vez de mandar a chave para o host errado. */
    public function assertCoherent(): void
    {
        $host = (string)parse_url($this->baseUrl, PHP_URL_HOST);
        $isSandboxHost = $host === 'api-sandbox.asaas.com';
        $isProdHost = $host === 'api.asaas.com';
        if (!$isSandboxHost && !$isProdHost) {
            throw new \RuntimeException('ASAAS_BASE_URL deve ser api-sandbox.asaas.com ou api.asaas.com.');
        }
        if ($this->isSandbox() && !$isSandboxHost) {
            throw new \RuntimeException('ASAAS_ENV=sandbox exige a URL do sandbox. Operação recusada.');
        }
        if ($this->env === 'production' && !$isProdHost) {
            throw new \RuntimeException('ASAAS_ENV=production exige a URL de produção. Operação recusada.');
        }
        if ($this->hasApiKey()) {
            $sandboxKey = str_starts_with($this->apiKey, '$aact_hmlg_') || str_contains($this->apiKey, 'hmlg');
            if ($this->isSandbox() && !$sandboxKey) {
                throw new \RuntimeException('A chave configurada não parece ser de sandbox (hmlg). Operação recusada.');
            }
            if ($this->env === 'production' && $sandboxKey) {
                throw new \RuntimeException('Chave de sandbox não pode ser usada em produção. Operação recusada.');
            }
        }
    }
}
