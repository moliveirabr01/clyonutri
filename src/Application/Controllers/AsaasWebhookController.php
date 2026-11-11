<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Domain\Billing\AsaasWebhookService;

/**
 * Endpoint público do webhook Asaas (NI2-727). Sem sessão: a autenticação é o
 * header asaas-access-token conferido em tempo constante contra ASAAS_WEBHOOK_TOKEN.
 * Não registra o corpo bruto (pode conter dados pessoais do pagador).
 */
final class AsaasWebhookController
{
    public function __construct(private ?AsaasWebhookService $service = null)
    {
    }

    public function receive(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $service = $this->service ??= new AsaasWebhookService();

        $token = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? null;
        if (!$service->tokenIsValid(is_string($token) ? $token : null)) {
            http_response_code(401);
            echo json_encode(['error' => 'Token de webhook inválido ou não configurado.']);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        if (strlen($raw) > 262144) {
            http_response_code(413);
            echo json_encode(['error' => 'Payload excede o limite.']);
            return;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON inválido.']);
            return;
        }

        try {
            $result = $service->handle($payload);
            http_response_code(200);
            echo json_encode(['received' => true, 'status' => $result['status']]);
        } catch (\DomainException $e) {
            // 422: o Asaas reenvia; evento inconsistente nunca ativa plano.
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('asaas_webhook_failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao processar o evento.']);
        }
    }
}
