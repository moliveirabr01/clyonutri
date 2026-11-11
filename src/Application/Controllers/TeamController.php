<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Team\TeamService;

/**
 * NI2-606: Configurações > Equipe.
 *   GET  /configuracoes/equipe                       lista, limite do plano, convite (gestão) e histórico
 *   POST /configuracoes/equipe                       convidar
 *   POST /configuracoes/equipe/{userId}/papel        mudar papel
 *   POST /configuracoes/equipe/{userId}/desativar    desativar (histórico preservado)
 *   POST /configuracoes/equipe/{userId}/reativar     reativar (respeita o limite)
 *   POST /configuracoes/equipe/{userId}/reenviar     novo link de convite
 * Quem não é titular/gestor só vê a lista.
 */
class TeamController
{
    public function show(): void
    {
        $db = Connection::getInstance();
        $svc = new TeamService($db);
        $clinicId = (int)TenantContext::getClinicId();
        $userId = (int)TenantContext::getUserId();
        $canManage = $svc->canManage($clinicId, $userId);
        $members = $svc->members($clinicId);
        $limit = $svc->limit($clinicId);
        $history = $canManage ? $svc->history($clinicId) : [];
        $roles = TeamService::ROLES;
        $flash = $_SESSION['team_flash'] ?? null;
        $old = $_SESSION['team_old'] ?? [];
        unset($_SESSION['team_flash'], $_SESSION['team_old']);
        $csrfToken = Csrf::token();
        $selfId = $userId;
        $title = 'Equipe';
        $activeNav = 'configuracoes';
        $clinicName = $_SESSION['clinic_name'] ?? '';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        require __DIR__ . '/../../../views/settings/team.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function invite(): void
    {
        $this->run(function (TeamService $svc, int $c, int $u) {
            $_SESSION['team_old'] = array_intersect_key($_POST, array_flip(['name', 'email', 'role', 'crn', 'crn_region']));
            $r = $svc->invite($c, $u, $_POST, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
            unset($_SESSION['team_old']);
            return ['type' => 'success', 'text' => 'Convite criado. ' . $r['note'], 'link' => $r['link'], 'expires_at' => $r['expires_at']];
        });
    }

    public function resend(array $params): void
    {
        $this->run(function (TeamService $svc, int $c, int $u) use ($params) {
            $r = $svc->resendInvite($c, $u, (int)($params['userId'] ?? 0));
            return ['type' => 'success', 'text' => 'Novo convite gerado; o anterior deixou de valer. ' . $r['note'], 'link' => $r['link'], 'expires_at' => $r['expires_at']];
        });
    }

    public function changeRole(array $params): void
    {
        $this->run(function (TeamService $svc, int $c, int $u) use ($params) {
            $svc->changeRole($c, $u, (int)($params['userId'] ?? 0), (string)($_POST['role'] ?? ''));
            return ['type' => 'success', 'text' => 'Papel atualizado. A pessoa precisa entrar de novo para o novo papel valer.'];
        });
    }

    public function deactivate(array $params): void
    {
        $this->run(function (TeamService $svc, int $c, int $u) use ($params) {
            $svc->deactivate($c, $u, (int)($params['userId'] ?? 0));
            return ['type' => 'success', 'text' => 'Acesso desativado. A pessoa saiu do sistema; prontuários, planos e o histórico dela continuam preservados.'];
        });
    }

    public function reactivate(array $params): void
    {
        $this->run(function (TeamService $svc, int $c, int $u) use ($params) {
            $svc->reactivate($c, $u, (int)($params['userId'] ?? 0));
            return ['type' => 'success', 'text' => 'Acesso reativado.'];
        });
    }

    private function run(callable $fn): void
    {
        if (!Csrf::validate((string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['team_flash'] = ['type' => 'error', 'text' => 'A página ficou aberta por muito tempo e a segurança expirou. Recarregue e tente de novo.'];
        } else {
            try {
                $_SESSION['team_flash'] = $fn(new TeamService(Connection::getInstance()), (int)TenantContext::getClinicId(), (int)TenantContext::getUserId());
            } catch (\InvalidArgumentException | \DomainException $e) {
                $_SESSION['team_flash'] = ['type' => 'error', 'text' => $e->getMessage()];
            } catch (\Throwable $e) {
                error_log('NI2-606 equipe: ' . get_class($e) . ': ' . $e->getMessage());
                $_SESSION['team_flash'] = ['type' => 'error', 'text' => 'Não foi possível concluir a ação. Nada foi alterado. Tente de novo.'];
            }
        }
        header('Location: /configuracoes/equipe');
        exit;
    }
}
