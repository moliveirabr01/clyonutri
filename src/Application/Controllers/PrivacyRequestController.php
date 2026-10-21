<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Privacy\DataSubjectRequestService;

/**
 * Fila de pedidos de titulares (LGPD art. 18) da clínica — code review NI2-91, A2.
 * Ver: dono, admin e nutricionista. Exportar: os mesmos. Anonimizar (exclusão): só dono/admin.
 */
final class PrivacyRequestController
{
    public const CONFIRM_WORD = 'ANONIMIZAR';

    private function memberRole(): ?string
    {
        $stmt = Connection::getInstance()->prepare("SELECT cm.role FROM clinic_members cm JOIN users u ON u.id = cm.user_id AND u.status = 'active'
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active'");
        $stmt->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $role = $stmt->fetchColumn();
        return is_string($role) ? $role : null;
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Acesso restrito à equipe clínica do consultório.';
    }

    public function index(array $params = []): void
    {
        $role = $this->memberRole();
        if (!in_array($role, ['owner', 'admin', 'nutritionist'], true)) { $this->deny(); return; }
        $requests = (new DataSubjectRequestService())->listForClinic();
        $canProcessDeletion = in_array($role, ['owner', 'admin'], true);
        $csrfToken = Csrf::token();
        $flash = $_SESSION['privacy_flash'] ?? null; unset($_SESSION['privacy_flash']);
        $confirmWord = self::CONFIRM_WORD;
        $title = 'Pedidos de titulares (LGPD)';
        $activeNav = 'privacidade';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? '';
        ob_start();
        require __DIR__ . '/../../../views/privacy/requests.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    public function export(array $params = []): void
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Sessão expirada. Recarregue a página.'; return; }
        if (!in_array($this->memberRole(), ['owner', 'admin', 'nutritionist'], true)) { $this->deny(); return; }
        try {
            $result = (new DataSubjectRequestService())->processExport((int)($params['id'] ?? 0), (int)TenantContext::getUserId());
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="dados-titular-pedido-' . (int)$result['request_id'] . '.json"');
            header('Cache-Control: no-store');
            echo json_encode(['checksum_sha256' => $result['checksum_sha256']] + $result['export_package'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            $this->back('error', $e->getMessage());
        } catch (TenantSecurityException $e) {
            $this->deny();
        }
    }

    public function processDeletion(array $params = []): void
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Sessão expirada. Recarregue a página.'; return; }
        if (!in_array($this->memberRole(), ['owner', 'admin'], true)) { $this->deny(); return; }
        if (trim((string)($_POST['confirm_word'] ?? '')) !== self::CONFIRM_WORD || ($_POST['confirm_irreversible'] ?? '') !== 'yes') {
            $this->back('error', 'Para anonimizar, digite ' . self::CONFIRM_WORD . ' e confirme que a ação é irreversível.');
            return;
        }
        try {
            $r = (new DataSubjectRequestService())->processDeletionWithLegalHold((int)($params['id'] ?? 0), (int)TenantContext::getUserId());
            $this->back('success', $r['legal_hold_applied']
                ? 'Cadastro anonimizado. O prontuário foi preservado sob guarda legal e o pedido foi concluído com essa ressalva.'
                : 'Cadastro anonimizado e pedido concluído.');
        } catch (\InvalidArgumentException $e) {
            $this->back('error', $e->getMessage());
        } catch (TenantSecurityException $e) {
            $this->deny();
        }
    }

    private function back(string $type, string $message): void
    {
        $_SESSION['privacy_flash'] = ['type' => $type, 'message' => $message];
        header('Location: /privacidade/pedidos');
    }
}
