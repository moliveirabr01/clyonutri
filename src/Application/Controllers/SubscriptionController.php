<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\Subscription\SubscriptionService;

final class SubscriptionController
{
    private SubscriptionService $service;

    public function __construct(?SubscriptionService $service = null)
    {
        $this->service = $service ?? new SubscriptionService(Connection::getInstance());
    }

    public function show(): void
    {
        $clinicId = TenantContext::getClinicId();
        $userId = TenantContext::getUserId();

        if ($clinicId <= 0 || $userId <= 0) {
            header('Location: /login');
            exit;
        }

        // Validação de RBAC server-side: Apenas titulares ou administradores
        if (!$this->service->canManageSubscription($clinicId, $userId)) {
            http_response_code(403);
            $title = 'Acesso Restrito';
            $activeNav = 'configuracoes';
            $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
            $userName = $_SESSION['user_name'] ?? '';
            ob_start();
            ?>
            <div class="card" role="alert" style="max-width: 600px; margin: 2rem auto;">
                <h1 style="color: var(--semantic-error); font-size: 1.4rem;">Acesso Restrito à Gestão de Assinatura</h1>
                <p>Apenas os titulares e administradores do consultório têm permissão para visualizar e gerenciar o contrato e a assinatura SaaS.</p>
                <a href="/configuracoes" class="btn btn-primary">Voltar para Configurações</a>
            </div>
            <?php
            $content = ob_get_clean();
            require __DIR__ . '/../../../views/layouts/professional-shell.php';
            return;
        }

        try {
            $subscription = $this->service->getClinicSubscription($clinicId);
            $reasons = $this->service->getCancellationReasons();
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Não foi possível carregar as informações da assinatura: ' . htmlspecialchars($e->getMessage());
            return;
        }

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $title = 'Central de Assinatura & Contrato';
        $activeNav = 'configuracoes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';

        ob_start();
        require __DIR__ . '/../../../views/settings/subscription.php';
        $content = ob_get_clean();

        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function cancel(): void
    {
        $clinicId = TenantContext::getClinicId();
        $userId = TenantContext::getUserId();

        if ($clinicId <= 0 || $userId <= 0) {
            header('Location: /login');
            exit;
        }

        // Validação de CSRF
        $token = $_POST['_csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            http_response_code(403);
            $_SESSION['flash_error'] = 'Token de segurança expirado. Por favor, tente novamente.';
            header('Location: /configuracoes/assinatura');
            exit;
        }

        $reason = !empty($_POST['reason_category']) ? (string)$_POST['reason_category'] : null;
        $feedback = !empty($_POST['feedback']) ? (string)$_POST['feedback'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        try {
            $result = $this->service->cancelSubscription($clinicId, $userId, $reason, $feedback, $ip, $ua);
            $_SESSION['flash_success'] = $result['message'];
        } catch (\DomainException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Não foi possível concluir o cancelamento. Tente novamente.';
        }

        header('Location: /configuracoes/assinatura');
        exit;
    }

    /**
     * Endpoint API JSON de consulta da assinatura
     */
    public function apiGet(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $clinicId = TenantContext::getClinicId();
        $userId = TenantContext::getUserId();

        if (!$this->service->canManageSubscription($clinicId, $userId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado. Apenas titulares e administradores podem consultar a assinatura.']);
            return;
        }

        $subscription = $this->service->getClinicSubscription($clinicId);
        echo json_encode(['success' => true, 'subscription' => $subscription]);
    }

    /**
     * Endpoint API JSON de cancelamento com suporte a idempotência
     */
    public function apiCancel(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $clinicId = TenantContext::getClinicId();
        $userId = TenantContext::getUserId();

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $reason = !empty($input['reason_category']) ? (string)$input['reason_category'] : null;
        $feedback = !empty($input['feedback']) ? (string)$input['feedback'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        try {
            $result = $this->service->cancelSubscription($clinicId, $userId, $reason, $feedback, $ip, $ua);
            echo json_encode($result);
        } catch (\DomainException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Falha no processamento do cancelamento.']);
        }
    }
}
