<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Core\Security\TenantSecurityException;
use ClyoNutri\Domain\Patient\PatientImportService;
use PDO;

/**
 * NI2-522 (v42): importar pacientes de planilha CSV (prévia, confirmação, relatório e desfazer).
 * Mesma regra de quem pode cadastrar paciente à mão: nutricionista ativo da clínica com CRN.
 */
final class PatientImportController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    private function canImport(): bool
    {
        $st = $this->db->prepare("SELECT pr.id FROM clinic_members cm JOIN users u ON u.id = cm.user_id
            JOIN professionals pr ON pr.user_id = u.id
            WHERE cm.clinic_id = ? AND cm.user_id = ? AND cm.status = 'active'
            AND cm.role IN ('owner', 'admin', 'nutritionist')
            AND u.status = 'active' AND u.role = 'nutritionist'
            AND pr.crn IS NOT NULL AND TRIM(pr.crn) <> ''
            ORDER BY pr.id LIMIT 1");
        $st->execute([TenantContext::getClinicId(), TenantContext::getUserId()]);
        return $st->fetchColumn() !== false;
    }

    private function deny(): void
    {
        http_response_code(403);
        echo 'Você não tem permissão para importar pacientes. Apenas nutricionistas com CRN cadastrado na clínica podem cadastrar pacientes.';
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to);
        exit;
    }

    private function render(string $view, array $vars, string $title): void
    {
        extract($vars, EXTR_SKIP);
        $activeNav = 'pacientes';
        $clinicName = $_SESSION['clinic_name'] ?? 'Minha Clínica';
        $userName = $_SESSION['user_name'] ?? 'Profissional';
        $csrfToken = Csrf::token();
        ob_start();
        require __DIR__ . '/../../../views/patients/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }

    private function flash(): array
    {
        $f = ['feedback' => $_SESSION['feedback_message'] ?? null, 'error' => $_SESSION['error_message'] ?? null];
        unset($_SESSION['feedback_message'], $_SESSION['error_message']);
        return $f;
    }

    public function index(): void
    {
        if (!$this->canImport()) {
            $this->deny();
            return;
        }
        $svc = new PatientImportService($this->db);
        $recent = [];
        $unavailable = false;
        try {
            $svc->purgeStalePreviews();
            $recent = $svc->recent();
        } catch (\PDOException $e) {
            error_log('NI2-522 import tables unavailable: ' . $e->getMessage());
            $unavailable = true;
        }
        $this->render('import', $this->flash() + ['recent' => $recent, 'unavailable' => $unavailable], 'Importar pacientes');
    }

    public function template(): void
    {
        if (!$this->canImport()) {
            $this->deny();
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modelo-importacao-pacientes.csv"');
        header('Cache-Control: no-store');
        echo PatientImportService::templateCsv();
    }

    public function preview(): void
    {
        if (!$this->canImport()) {
            $this->deny();
            return;
        }
        $content = '';
        $fileName = null;
        $file = $_FILES['csv_file'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
                $_SESSION['error_message'] = 'Não foi possível receber o arquivo. Tente de novo ou cole o conteúdo da planilha.';
                $this->redirect('/pacientes/importar');
            }
            if ((int)$file['size'] > PatientImportService::MAX_BYTES) {
                $_SESSION['error_message'] = 'O arquivo passa de 2 MB. Divida a planilha em partes menores.';
                $this->redirect('/pacientes/importar');
            }
            $content = (string)file_get_contents((string)$file['tmp_name']);
            $fileName = basename((string)($file['name'] ?? 'planilha.csv'));
        } elseif (is_string($_POST['csv_text'] ?? null)) {
            $content = (string)$_POST['csv_text'];
            $fileName = 'texto colado';
        }
        if (trim($content) === '') {
            $_SESSION['error_message'] = 'Escolha um arquivo CSV ou cole o conteúdo da planilha.';
            $this->redirect('/pacientes/importar');
        }
        try {
            $svc = new PatientImportService($this->db);
            $svc->purgeStalePreviews();
            $r = $svc->preview($content, $fileName, (int)TenantContext::getUserId());
            if ($r['reused']) {
                $_SESSION['feedback_message'] = match ($r['status']) {
                    'confirmed' => 'Este arquivo já foi importado nesta clínica. Nada foi gravado de novo; veja o relatório abaixo.',
                    'rolled_back' => 'Este arquivo já foi importado e desfeito. Para importar de novo, altere o arquivo (por exemplo, remova as linhas que não quer) e envie outra vez.',
                    default => 'Este arquivo já tem uma prévia aberta. Confira e confirme abaixo.',
                };
            }
            $this->redirect('/pacientes/importar/' . $r['batch_id']);
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (TenantSecurityException $e) {
            $this->deny();
            return;
        } catch (\PDOException $e) {
            error_log('NI2-522 preview failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível gerar a prévia. Se o problema continuar, confira se a atualização do banco da versão 42 foi aplicada.';
        }
        $this->redirect('/pacientes/importar');
    }

    public function show(array $params): void
    {
        if (!$this->canImport()) {
            $this->deny();
            return;
        }
        try {
            $batch = (new PatientImportService($this->db))->find((int)($params['id'] ?? 0));
        } catch (\PDOException $e) {
            $batch = null;
        }
        if ($batch === null) {
            http_response_code(404);
            echo 'Importação não encontrada.';
            return;
        }
        $this->render('import_show', $this->flash() + ['batch' => $batch], 'Importação de pacientes');
    }

    private function act(array $params, string $action): void
    {
        if (!$this->canImport()) {
            $this->deny();
            return;
        }
        $id = (int)($params['id'] ?? 0);
        $svc = new PatientImportService($this->db);
        if ($svc->find($id) === null) {
            http_response_code(404);
            echo 'Importação não encontrada.';
            return;
        }
        try {
            if ($action === 'confirm') {
                $r = $svc->confirm($id);
                $_SESSION['feedback_message'] = $r['created'] . ($r['created'] === 1 ? ' paciente cadastrado' : ' pacientes cadastrados')
                    . ($r['skipped'] > 0 ? ', ' . ((int)$r['skipped'] === 1 ? '1 pulado' : (int)$r['skipped'] . ' pulados') . ' por duplicidade' : '')
                    . '. O consentimento ficou como "não coletado": registre na ficha de cada paciente.';
                $this->redirect('/pacientes/importar/' . $id);
            }
            if ($action === 'discard') {
                $svc->discard($id);
                $_SESSION['feedback_message'] = 'Prévia descartada. Nenhum paciente foi cadastrado e os dados da planilha foram apagados.';
                $this->redirect('/pacientes/importar');
            }
            if (($_POST['confirm_rollback'] ?? '') !== 'yes') {
                throw new \InvalidArgumentException('Marque a confirmação para desfazer a importação.');
            }
            $r = $svc->rollback($id);
            $_SESSION['feedback_message'] = 'Importação desfeita: ' . ((int)$r['removed'] === 1 ? '1 removido' : (int)$r['removed'] . ' removidos')
                . ($r['kept'] > 0 ? '; ' . ((int)$r['kept'] === 1 ? '1 mantido porque já tem registros ou acesso ao app' : (int)$r['kept'] . ' mantidos porque já têm registros ou acesso ao app') : '') . '.';
        } catch (\InvalidArgumentException | \DomainException $e) {
            $_SESSION['error_message'] = $e->getMessage();
        } catch (TenantSecurityException $e) {
            $this->deny();
            return;
        } catch (\Throwable $e) {
            error_log('NI2-522 ' . $action . ' failed: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Não foi possível concluir. Nada foi alterado. Tente novamente.';
        }
        $this->redirect('/pacientes/importar/' . $id);
    }

    public function confirm(array $params): void
    {
        $this->act($params, 'confirm');
    }

    public function discard(array $params): void
    {
        $this->act($params, 'discard');
    }

    public function rollback(array $params): void
    {
        $this->act($params, 'rollback');
    }
}
