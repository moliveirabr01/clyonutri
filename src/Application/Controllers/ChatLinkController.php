<?php
declare(strict_types=1);
namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Chat\ChatException;
use ClyoNutri\Domain\Chat\PatientLinkService;

/**
 * Vínculo de acompanhamento que libera o chat (NI2-138, A1/A2), no perfil da paciente.
 * Dono e admin liberam ou encerram para qualquer profissional; nutricionista, só o próprio vínculo.
 */
final class ChatLinkController
{
    /** @return array{role: ?string, professional_id: ?int} */
    public static function actor(): array
    {
        $st = Connection::getInstance()->prepare("SELECT cm.role, pr.id AS professional_id FROM clinic_members cm
            JOIN users u ON u.id = cm.user_id AND u.status = 'active'
            LEFT JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active' LIMIT 1");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['role' => $r['role'] ?? null, 'professional_id' => isset($r['professional_id']) ? (int)$r['professional_id'] : null];
    }

    private function guard(): ?array
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) { http_response_code(403); echo 'Sessão expirada. Recarregue a página.'; return null; }
        $a = self::actor();
        if (!in_array($a['role'], ['owner', 'admin', 'nutritionist'], true)) { http_response_code(403); echo 'Somente a equipe clínica libera o chat.'; return null; }
        return $a;
    }

    private function back(int $patientId, string $type, string $message): void
    {
        $_SESSION['chat_link_flash'] = ['type' => $type, 'message' => $message];
        header('Location: /pacientes/' . $patientId . '#chat-vinculo');
    }

    public function activate(array $params = []): void
    {
        if (($a = $this->guard()) === null) return;
        $pid = (int)($params['id'] ?? 0);
        $profId = ctype_digit((string)($_POST['professional_id'] ?? '')) ? (int)$_POST['professional_id'] : 0;
        if ($a['role'] === 'nutritionist' && $profId !== (int)$a['professional_id']) {
            $this->back($pid, 'error', 'Você só pode liberar o chat entre a paciente e você. Peça à gestão para liberar outro profissional.');
            return;
        }
        try {
            (new PatientLinkService())->activate($pid, $profId, (int)TenantContext::getUserId(), 'manual');
            $this->back($pid, 'success', 'Chat liberado. A paciente já pode enviar mensagens pelo app.');
        } catch (ChatException $e) {
            $this->back($pid, 'error', $e->getMessage());
        } catch (TenantSecurityException) {
            http_response_code(404); echo 'Paciente não encontrado.';
        }
    }

    public function end(array $params = []): void
    {
        if (($a = $this->guard()) === null) return;
        $pid = (int)($params['id'] ?? 0);
        $linkId = (int)($params['linkId'] ?? 0);
        $svc = new PatientLinkService();
        try {
            $links = $svc->linksForPatient($pid);
        } catch (TenantSecurityException) {
            http_response_code(404); echo 'Paciente não encontrado.'; return; // NI2-24 (M3): antes era 500
        }
        $own = array_filter($links, static fn($l) => (int)$l['id'] === $linkId);
        $link = $own ? array_values($own)[0] : null;
        if ($link === null) { $this->back($pid, 'error', 'Vínculo não encontrado.'); return; }
        if ($a['role'] === 'nutritionist' && (int)$link['professional_id'] !== (int)$a['professional_id']) {
            $this->back($pid, 'error', 'Você só pode encerrar o seu próprio vínculo com a paciente.');
            return;
        }
        try {
            $svc->end($linkId, (int)TenantContext::getUserId(), is_string($_POST['ended_reason'] ?? null) ? $_POST['ended_reason'] : '');
            $this->back($pid, 'success', 'Chat encerrado. A paciente continua vendo o histórico, mas não envia novas mensagens.');
        } catch (ChatException $e) {
            $this->back($pid, 'error', $e->getMessage());
        }
    }
}
