<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Audit\AuditLogger;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\Materials\MaterialService;
use PDO;

/**
 * Materiais educativos (estilo WebDiet): biblioteca da clínica, envio para pacientes e histórico de envios.
 *
 *   GET  /materiais                       biblioteca (?q, ?categoria, ?arquivados=1, ?para=<paciente> para envio rápido)
 *   POST /materiais                       subir arquivo (PDF/PNG/JPG até 10 MB)
 *   GET  /materiais/{id}                  detalhe, quem recebeu, enviar para pacientes
 *   POST /materiais/{id}                  editar título, descrição e categoria
 *   POST /materiais/{id}/enviar           enviar para pacientes (idempotente)
 *   POST /materiais/{id}/arquivar|reativar
 *   POST /materiais/envios/{sid}/revogar  o paciente deixa de ver
 *   GET  /materiais/{id}/arquivo          prévia para a clínica
 * CSRF dos POST conferido pelo Router.
 */
class MaterialController
{
    private PDO $db;
    private MaterialService $svc;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->svc = new MaterialService($this->db);
    }

    public function index(array $params = []): void
    {
        $c = $this->clinic();
        $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $cat = is_string($_GET['categoria'] ?? null) ? $_GET['categoria'] : '';
        $archived = ($_GET['arquivados'] ?? '') === '1';
        $target = null;
        if (!empty($_GET['para']) && ctype_digit((string)$_GET['para'])) {
            $st = $this->db->prepare('SELECT id, name FROM patients WHERE id = ? AND clinic_id = ?');
            $st->execute([(int)$_GET['para'], $c]);
            $target = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $targetHas = [];
        if ($target) {
            $st = $this->db->prepare('SELECT material_id FROM educational_material_shares WHERE clinic_id = ? AND patient_id = ? AND revoked_at IS NULL');
            $st->execute([$c, (int)$target['id']]);
            $targetHas = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
        }
        $this->render('index', 'Materiais educativos', [
            'materials' => $this->svc->list($c, $q, $cat, $archived), 'q' => $q, 'cat' => $cat, 'archived' => $archived, 'target' => $target, 'targetHas' => $targetHas,
            'canManage' => $this->canManage(), 'old' => $this->takeOld(),
        ]);
    }

    public function upload(array $params = []): void
    {
        $c = $this->clinic();
        $u = (int)TenantContext::getUserId();
        $title = is_string($_POST['title'] ?? null) ? $_POST['title'] : '';
        $desc = is_string($_POST['description'] ?? null) ? $_POST['description'] : '';
        $cat = is_string($_POST['category'] ?? null) ? $_POST['category'] : '';
        try {
            $id = $this->svc->upload($c, $u, is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [], $title, $desc, $cat);
            (new AuditLogger($this->db))->log('MATERIAL_UPLOADED', 'educational_materials', (string)$id, ['clinic_id' => $c]);
            $this->flash('success', 'Material adicionado à biblioteca. Agora escolha para quais pacientes enviar.');
            $this->redirect('/materiais/' . $id);
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['materials_old'] = ['title' => $title, 'description' => $desc, 'category' => $cat];
            $this->flash('error', $e->getMessage());
            $this->redirect('/materiais');
        }
    }

    public function show(array $params = []): void
    {
        $c = $this->clinic();
        $id = (int)($params['id'] ?? 0);
        try {
            $m = $this->svc->get($c, $id);
        } catch (\DomainException $e) {
            $this->notFound();
            return;
        }
        $this->render('show', (string)$m['title'] . ' — Materiais', [
            'm' => $m, 'shares' => $this->svc->shares($c, $id), 'patients' => $this->svc->patientsForPicker($c), 'canManage' => $this->canManage(),
        ]);
    }

    public function update(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $this->act('/materiais/' . $id, function (int $c, int $u) use ($id) {
            $this->svc->update($c, $u, $id, (string)($_POST['title'] ?? ''), (string)($_POST['description'] ?? ''), (string)($_POST['category'] ?? ''));
            return 'Material atualizado.';
        });
    }

    public function share(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $back = self::safeBack(is_string($_POST['back'] ?? null) ? $_POST['back'] : '', '/materiais/' . $id);
        $this->act($back, function (int $c, int $u) use ($id) {
            $ids = is_array($_POST['patients'] ?? null) ? $_POST['patients'] : [];
            $n = $this->svc->share($c, $u, $id, $ids, is_string($_POST['note'] ?? null) ? $_POST['note'] : null);
            (new AuditLogger($this->db))->log('MATERIAL_SHARED', 'educational_materials', (string)$id, ['clinic_id' => $c, 'patients' => count($ids), 'new' => $n]);
            return $n === 0 ? 'Esses pacientes já tinham este material.' : ($n === 1 ? 'Material enviado para 1 paciente. Ele aparece na área do paciente.' : "Material enviado para $n pacientes. Ele aparece na área do paciente.");
        });
    }

    public function archive(array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        if (!in_array($params['acao'] ?? '', ['arquivar', 'reativar'], true)) {
            $this->notFound();
            return;
        }
        $on = ($params['acao'] ?? '') === 'arquivar';
        $this->act('/materiais/' . $id, function (int $c, int $u) use ($id, $on) {
            $this->svc->setArchived($c, $u, $id, $on);
            (new AuditLogger($this->db))->log($on ? 'MATERIAL_ARCHIVED' : 'MATERIAL_RESTORED', 'educational_materials', (string)$id, ['clinic_id' => $c]);
            return $on ? 'Material arquivado: saiu da biblioteca e da área dos pacientes. Os envios ficam no histórico.' : 'Material reativado.';
        });
    }

    public function revoke(array $params = []): void
    {
        $sid = (int)($params['sid'] ?? 0);
        $back = self::safeBack(is_string($_POST['back'] ?? null) ? $_POST['back'] : '', '/materiais');
        $this->act($back, function (int $c, int $u) use ($sid) {
            $this->svc->revoke($c, $u, $sid);
            (new AuditLogger($this->db))->log('MATERIAL_SHARE_REVOKED', 'educational_material_shares', (string)$sid, ['clinic_id' => $c]);
            return 'Envio revogado: o paciente deixa de ver este material.';
        });
    }

    public function file(array $params = []): void
    {
        try {
            $download = ($_GET['baixar'] ?? '') === '1';
            MaterialService::send($this->svc->clinicFile($this->clinic(), (int)($params['id'] ?? 0)), $download, !$download);
        } catch (\DomainException $e) {
            $this->notFound();
        } catch (\RuntimeException $e) {
            error_log('MATERIAL_FILE_UNAVAILABLE ' . (int)($params['id'] ?? 0));
            http_response_code(503);
            echo 'Arquivo indisponível no momento.';
        }
    }

    public static function safeBack(string $back, string $default): string
    {
        return preg_match('#^/(materiais|pacientes)(?:/[A-Za-z0-9/_\-]*)?(?:\?[A-Za-z0-9=&%_.+\-]*)?$#', $back) ? $back : $default;
    }

    private function act(string $back, callable $fn): void
    {
        try {
            $this->flash('success', $fn($this->clinic(), (int)TenantContext::getUserId()));
        } catch (\InvalidArgumentException | \DomainException $e) {
            $this->flash('error', $e->getMessage());
        } catch (\ClyoNutri\Core\Security\TenantSecurityException $e) {
            $this->flash('error', 'Paciente não encontrado nesta clínica.');
        }
        $this->redirect($back);
    }

    private function clinic(): int
    {
        return (int)TenantContext::getClinicId();
    }

    private function canManage(): bool
    {
        try {
            $this->svc->assertCanManage($this->clinic(), (int)TenantContext::getUserId());
            return true;
        } catch (\DomainException $e) {
            return false;
        }
    }

    private function takeOld(): array
    {
        $o = $_SESSION['materials_old'] ?? [];
        unset($_SESSION['materials_old']);
        return is_array($o) ? $o : [];
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('not_found', 'Material não encontrado', []);
    }

    private function flash(string $type, string $text): void
    {
        $_SESSION['materials_flash'] = ['type' => $type, 'text' => $text];
    }

    private function redirect(string $to): void
    {
        if (!headers_sent()) {
            header('Location: ' . $to, true, 303);
        }
        if (!defined('CLYONUTRI_TESTING')) {
            exit;
        }
    }

    private function render(string $view, string $title, array $vars): void
    {
        $flash = $_SESSION['materials_flash'] ?? null;
        unset($_SESSION['materials_flash']);
        $csrfToken = Csrf::token();
        extract($vars, EXTR_SKIP);
        $activeNav = 'materiais';
        ob_start();
        require __DIR__ . '/../../../views/materials/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
