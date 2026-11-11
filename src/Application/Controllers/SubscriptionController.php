<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\AccountActionRateLimiter;
use ClyoNutri\Domain\Privacy\AccountDeletionRequestService;
use ClyoNutri\Domain\Subscription\PlanChangeBlockedException;
use ClyoNutri\Domain\Subscription\PlanChangeService;
use ClyoNutri\Domain\Subscription\SubscriptionService;
use ClyoNutri\Domain\Billing\AsaasCheckoutService;
use ClyoNutri\Domain\Billing\AsaasException;

final class SubscriptionController
{
    private SubscriptionService $service;
    private ?PlanChangeService $planChanges = null;
    private ?AccountDeletionRequestService $deletions = null;
    private ?AccountActionRateLimiter $limiter = null;
    private ?AsaasCheckoutService $asaas = null;

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
            $this->renderForbidden();
            return;
        }

        try {
            $subscription = $this->service->getClinicSubscription($clinicId);
            $reasons = $this->service->getCancellationReasons();
            $planOverview = $this->planChanges()->overview($clinicId, $userId);
            $deletion = $this->deletions()->current($clinicId);
            $deletionAvailable = AccountDeletionRequestService::available(Connection::getInstance());
            $legalParagraphs = AccountDeletionRequestService::legalNoticeParagraphs();
            $asaasService = $this->asaas();
            $asaasOverview = [
                'enabled' => $asaasService->isEnabled(),
                'sandbox' => $asaasService->isEnabled(),
                'latest' => $asaasService->latestForClinic($clinicId),
                'idempotency_key' => bin2hex(random_bytes(16)),
                'return' => isset($_GET['asaas']) ? (string)$_GET['asaas'] : null,
            ];
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Não foi possível carregar as informações da assinatura: ' . htmlspecialchars($e->getMessage());
            return;
        }

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError = $_SESSION['flash_error'] ?? null;
        $flashBlockers = $_SESSION['flash_blockers'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['flash_blockers']);

        $title = 'Assinatura';
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

        if (($wait = $this->throttle('plan_cancel', $clinicId, $userId)) !== null) {
            $_SESSION['flash_error'] = $wait;
            header('Location: /configuracoes/assinatura#cancelar');
            exit;
        }

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
     * Troca de plano (upgrade ou downgrade). Registrada no sistema; a cobrança é combinada manualmente.
     * Somente titular ou administrador, com CSRF, limite de tentativas e chave de idempotência contra duplo clique.
     */
    public function changePlan(): void
    {
        [$clinicId, $userId] = $this->actor();
        if (!$this->service->canManageSubscription($clinicId, $userId)) {
            $this->renderForbidden();
            return;
        }
        $this->requireCsrf('#plano');
        if (($wait = $this->throttle('plan_change', $clinicId, $userId)) !== null) {
            $this->back('#plano', null, $wait);
        }

        $toPlanId = (int)($_POST['plan_id'] ?? 0);
        $key = isset($_POST['idempotency_key']) ? (string)$_POST['idempotency_key'] : null;
        try {
            $result = $this->planChanges()->changePlan($clinicId, $userId, $toPlanId, $key, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
            $this->back('#plano', (string)$result['message']);
        } catch (PlanChangeBlockedException $e) {
            $_SESSION['flash_blockers'] = ['title' => $e->getMessage(), 'items' => array_map(static fn(array $b): string => (string)$b['message'], $e->getBlockers())];
            $this->back('#plano');
        } catch (\DomainException $e) {
            $this->back('#plano', null, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('plan_change_failed: ' . $e->getMessage());
            $this->back('#plano', null, 'Não foi possível registrar a troca de plano agora. Nada foi alterado. Tente novamente em instantes.');
        }
    }

    /** Pedido de exclusão da conta e dos dados (LGPD). Não apaga nada: registra e avisa a equipe. */
    public function requestAccountDeletion(): void
    {
        [$clinicId, $userId] = $this->actor();
        if (!$this->service->canManageSubscription($clinicId, $userId)) {
            $this->renderForbidden();
            return;
        }
        $this->requireCsrf('#conta');
        if (($wait = $this->throttle('deletion_request', $clinicId, $userId)) !== null) {
            $this->back('#conta', null, $wait);
        }

        try {
            $result = $this->deletions()->request(
                $clinicId,
                $userId,
                (string)($_POST['password'] ?? ''),
                (string)($_POST['confirm_phrase'] ?? ''),
                !empty($_POST['acknowledge']),
                isset($_POST['reason']) ? (string)$_POST['reason'] : null,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );
            $this->back('#conta', (string)$result['message']);
        } catch (\DomainException $e) {
            $this->back('#conta', null, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('account_deletion_request_failed: ' . $e->getMessage());
            $this->back('#conta', null, 'Não foi possível registrar o pedido agora. Nada foi alterado. Tente novamente em instantes.');
        }
    }

    /** O consultório retira o pedido de exclusão antes de a equipe encerrá-lo. */
    public function cancelAccountDeletion(): void
    {
        [$clinicId, $userId] = $this->actor();
        if (!$this->service->canManageSubscription($clinicId, $userId)) {
            $this->renderForbidden();
            return;
        }
        $this->requireCsrf('#conta');
        if (($wait = $this->throttle('deletion_cancel', $clinicId, $userId)) !== null) {
            $this->back('#conta', null, $wait);
        }

        try {
            $result = $this->deletions()->cancel($clinicId, $userId, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
            $this->back('#conta', (string)$result['message']);
        } catch (\DomainException $e) {
            $this->back('#conta', null, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('account_deletion_cancel_failed: ' . $e->getMessage());
            $this->back('#conta', null, 'Não foi possível retirar o pedido agora. Tente novamente em instantes.');
        }
    }

    /**
     * NI2-727 slice 1: inicia checkout hospedado do Asaas (sandbox, cartão, recorrente).
     * Não altera o plano: quem ativa é o webhook financeiro. Redireciona para a página do Asaas.
     */
    public function startAsaasCheckout(): void
    {
        [$clinicId, $userId] = $this->actor();
        if (!$this->service->canManageSubscription($clinicId, $userId)) {
            $this->renderForbidden();
            return;
        }
        $this->requireCsrf('#plano');
        if (($wait = $this->throttle('asaas_checkout', $clinicId, $userId)) !== null) {
            $this->back('#plano', null, $wait);
        }

        $planId = (int)($_POST['plan_id'] ?? 0);
        $key = isset($_POST['idempotency_key']) ? (string)$_POST['idempotency_key'] : null;
        try {
            $result = $this->asaas()->startCheckout($clinicId, $userId, $planId, $key,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
            $url = (string)$result['hosted_url'];
            if ($url === '') {
                $this->back('#plano', null, 'O Asaas não devolveu o link de pagamento. Nada foi cobrado; tente novamente.');
            }
            http_response_code(303);
            header('Location: ' . $url);
            exit;
        } catch (\DomainException | AsaasException $e) {
            $this->back('#plano', null, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('asaas_checkout_failed: ' . $e->getMessage());
            $this->back('#plano', null, 'Não foi possível iniciar a contratação agora. Nada foi cobrado e nada foi alterado.');
        }
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

        if (($wait = $this->throttle('plan_cancel', $clinicId, $userId)) !== null) {
            http_response_code(429);
            echo json_encode(['error' => $wait]);
            return;
        }

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

    // ------------------------------------------------------------------ apoio (v37)

    private function asaas(): AsaasCheckoutService
    {
        return $this->asaas ??= new AsaasCheckoutService(Connection::getInstance(), $this->service);
    }

    private function planChanges(): PlanChangeService
    {
        return $this->planChanges ??= new PlanChangeService(Connection::getInstance(), $this->service);
    }

    private function deletions(): AccountDeletionRequestService
    {
        return $this->deletions ??= new AccountDeletionRequestService(Connection::getInstance(), $this->service);
    }

    /** @return array{0:int,1:int} */
    private function actor(): array
    {
        $clinicId = TenantContext::getClinicId();
        $userId = TenantContext::getUserId();
        if ($clinicId <= 0 || $userId <= 0) {
            header('Location: /login');
            exit;
        }
        return [$clinicId, $userId];
    }

    private function requireCsrf(string $anchor): void
    {
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $this->back($anchor, null, 'A página ficou aberta por muito tempo e a segurança expirou. Recarregue e tente de novo.');
        }
    }

    /** Devolve a mensagem de espera quando o limite de tentativas foi atingido, ou null. */
    private function throttle(string $action, int $clinicId, int $userId): ?string
    {
        try {
            $limiter = $this->limiter ??= new AccountActionRateLimiter(Connection::getInstance());
            $r = $limiter->attempt($action, $clinicId, $userId);
            return $r['allowed'] ? null : AccountActionRateLimiter::waitMessage((int)$r['retry_after']);
        } catch (\Throwable $e) {
            error_log('account_action_limiter_failed: ' . $e->getMessage());
            return 'Não foi possível validar a segurança da ação agora. Tente novamente em instantes.';
        }
    }

    private function back(string $anchor, ?string $success = null, ?string $error = null): void
    {
        if ($success !== null) {
            $_SESSION['flash_success'] = $success;
        }
        if ($error !== null) {
            $_SESSION['flash_error'] = $error;
        }
        http_response_code(303);
        header('Location: /configuracoes/assinatura' . $anchor);
        exit;
    }

    private function renderForbidden(): void
    {
        http_response_code(403);
        $title = 'Acesso restrito';
        $activeNav = 'configuracoes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        ?>
        <div class="card" role="alert" style="max-width: 600px; margin: 2rem auto;">
            <h1 style="color: var(--semantic-error); font-size: 1.4rem;">Acesso restrito à gestão de assinatura</h1>
            <p>Apenas os titulares e administradores do consultório têm permissão para visualizar e gerenciar o contrato e a assinatura SaaS.</p>
            <a href="/configuracoes" class="btn btn-primary">Voltar para Configurações</a>
        </div>
        <?php
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
