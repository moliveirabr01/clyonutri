<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService;
use ClyoNutri\Domain\Patient\PatientRepository;
use PDO;

/**
 * Laudos da solicitação de exames, lado da nutricionista (v48, NI2-539). Todas as rotas com $requireAuth.
 *  - POST /pacientes/{id}/exames/{reqId}/laudos                          anexar (multipart: laudo)
 *  - POST /pacientes/{id}/exames/{reqId}/laudos/{docId}/revisar          aceitar (exames cobertos) ou recusar (motivo)
 *  - POST /pacientes/{id}/exames/{reqId}/laudos/{docId}/nova-versao      correção (multipart: laudo + motivo)
 *  - GET  /pacientes/{id}/exames/{reqId}/laudos/{docId}/abrir            gera link assinado de 5 min e redireciona
 *  - GET  /exames/laudo/{token}                                          entrega (link válido E sessão da mesma pessoa)
 */
class ExamResultController
{
    private PDO $db;
    private ExamResultDocumentService $docs;

    public function __construct(?PDO $db = null, ?ExamResultDocumentService $docs = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->docs = $docs ?? new ExamResultDocumentService($this->db);
    }

    public function upload(array $params): void
    {
        [$patient, $request] = $this->context($params);
        if (!$patient || !$this->csrfOk()) {
            return;
        }
        $this->act($patient, $request, fn() => $this->docs->uploadByProfessional((int)$request['id'], (int)TenantContext::getUserId(), $this->file(), $this->meta()),
            'Laudo anexado e guardado como original, com código de integridade.');
    }

    public function review(array $params): void
    {
        [$patient, $request, $doc] = $this->context($params, true);
        if (!$doc || !$this->csrfOk()) {
            return;
        }
        $accept = (string)($_POST['decision'] ?? '') === 'accept';
        $this->act($patient, $request, fn() => $this->docs->review((int)$doc['id'], (int)TenantContext::getUserId(), $accept,
            is_scalar($_POST['review_note'] ?? null) ? (string)$_POST['review_note'] : '', $this->itemIds()),
            $accept ? 'Laudo aceito: os exames marcados passam a constar como resultado recebido.' : 'Laudo recusado. A paciente vê o motivo no app; o arquivo continua guardado.');
    }

    public function newVersion(array $params): void
    {
        [$patient, $request, $doc] = $this->context($params, true);
        if (!$doc || !$this->csrfOk()) {
            return;
        }
        $this->act($patient, $request, fn() => $this->docs->newVersion((int)$doc['id'], (int)TenantContext::getUserId(), $this->file(),
            is_scalar($_POST['correction_reason'] ?? null) ? (string)$_POST['correction_reason'] : '', $this->meta()),
            'Correção anexada como nova versão. A versão anterior continua guardada.');
    }

    /** Gera o link assinado e redireciona: o endereço do arquivo nunca é fixo nem reaproveitável depois de 5 minutos. */
    public function open(array $params): void
    {
        [$patient, $request, $doc] = $this->context($params, true);
        if (!$doc) {
            return;
        }
        try {
            $token = $this->docs->professionalLink((int)$doc['id'], (int)TenantContext::getUserId());
            $this->redirect('/exames/laudo/' . $token);
        } catch (\Throwable $e) {
            $this->flash('error', $this->message($e));
            $this->redirect("/pacientes/{$patient['id']}/exames/{$request['id']}#laudos");
        }
    }

    /** GET /exames/laudo/{token}: o link vale para a mesma usuária e clínica da sessão, e só até expirar. */
    public function download(array $params): void
    {
        try {
            $link = ExamResultDocumentService::verifyLink((string)($params['token'] ?? ''));
            if ($link['kind'] !== 'p' || $link['subject_id'] !== (int)TenantContext::getUserId() || $link['clinic_id'] !== TenantContext::getClinicId()) {
                throw new \DomainException('Link de laudo inválido.');
            }
            $file = $this->docs->fileFor($link['document_id'], $link['clinic_id'], ['type' => 'user', 'id' => (int)TenantContext::getUserId()]);
        } catch (\Throwable $e) {
            http_response_code(404);
            header('Cache-Control: no-store');
            echo htmlspecialchars($this->message($e), ENT_QUOTES, 'UTF-8');
            return;
        }
        ExamResultDocumentService::sendHeaders($file);
        echo $file['bytes'];
    }

    // ---------------------------------------------------------------- internos

    /** @return array{0:?array,1:?array,2:?array} paciente, solicitação e (opcional) laudo, todos da clínica da sessão. */
    private function context(array $params, bool $withDoc = false): array
    {
        $clinicId = TenantContext::getClinicId();
        $patient = (new PatientRepository($this->db))->findById((int)($params['id'] ?? 0), $clinicId);
        $st = $this->db->prepare('SELECT id, patient_id, status FROM exam_requests WHERE id = ? AND clinic_id = ?');
        $st->execute([(int)($params['reqId'] ?? 0), $clinicId]);
        $request = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $doc = null;
        if ($patient && $request && (int)$request['patient_id'] === (int)$patient['id'] && $withDoc) {
            $doc = $this->docs->get((int)($params['docId'] ?? 0), $clinicId);
            if ($doc && (int)$doc['exam_request_id'] !== (int)$request['id']) {
                $doc = null;
            }
        }
        if (!$patient || !$request || (int)$request['patient_id'] !== (int)$patient['id'] || ($withDoc && !$doc)) {
            http_response_code(404);
            echo 'Não encontrado nesta clínica.';
            return [null, null, null];
        }
        return [$patient, $request, $doc];
    }

    private function act(array $patient, array $request, callable $fn, string $ok): void
    {
        try {
            $fn();
            if (session_status() === PHP_SESSION_ACTIVE) {
                unset($_SESSION['exam_form_recovery']);
            }
            $this->flash('success', $ok);
        } catch (\Throwable $e) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['exam_form_recovery'] = [
                    'req_id' => (int)$request['id'],
                    'laboratory_name' => is_scalar($_POST['laboratory_name'] ?? null) ? (string)$_POST['laboratory_name'] : '',
                    'collected_at' => is_scalar($_POST['collected_at'] ?? null) ? (string)$_POST['collected_at'] : '',
                    'report_issued_at' => is_scalar($_POST['report_issued_at'] ?? null) ? (string)$_POST['report_issued_at'] : '',
                    'notes' => is_scalar($_POST['notes'] ?? null) ? (string)$_POST['notes'] : '',
                    'item_ids' => $this->itemIds(),
                    'error_message' => $this->message($e),
                ];
            }
            $this->flash('error', $this->message($e));
        }
        $this->redirect("/pacientes/{$patient['id']}/exames/{$request['id']}#laudos");
    }

    private function file(): array
    {
        $f = $_FILES['laudo'] ?? null;
        return is_array($f) && is_scalar($f['tmp_name'] ?? null) ? $f : ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => ''];
    }

    private function meta(): array
    {
        $s = static fn(string $k) => is_scalar($_POST[$k] ?? null) ? (string)$_POST[$k] : '';
        return ['laboratory_name' => $s('laboratory_name'), 'collected_at' => $s('collected_at'), 'report_issued_at' => $s('report_issued_at'),
            'notes' => $s('notes'), 'item_ids' => $this->itemIds()];
    }

    private function itemIds(): array
    {
        return array_map('intval', array_filter((array)($_POST['item_ids'] ?? []), 'is_scalar'));
    }

    private function csrfOk(): bool
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Sua sessão de formulário expirou. Recarregue a página e tente de novo.';
            return false;
        }
        return true;
    }

    private function message(\Throwable $e): string
    {
        // v49: PDOException (e outras subclasses de RuntimeException vindas de biblioteca) nunca chegam à tela;
        // só as mensagens escritas pelo próprio domínio de exames são mostradas.
        if ($e instanceof \PDOException) {
            error_log('EXAM_RESULT_DB_ERROR ' . $e->getMessage());
            return 'Não foi possível concluir agora. Nada foi salvo; tente de novo.';
        }
        if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException
            || get_class($e) === \RuntimeException::class
            || $e instanceof \ClyoNutri\Core\Security\TenantSecurityException) {
            return $e->getMessage();
        }
        error_log('EXAM_RESULT_ERROR ' . get_class($e) . ': ' . $e->getMessage());
        return 'Não foi possível concluir agora. Nada foi salvo; tente de novo.';
    }

    private function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['exam_flash'] = ['type' => $type, 'message' => $message];
        }
    }

    private function redirect(string $to): void
    {
        if (!headers_sent()) {
            header(\ClyoNutri\Application\View\AttendanceContext::location('Location: ' . $to), true, 303);
        }
        if (!defined('CLYONUTRI_TESTING')) {
            exit;
        }
    }
}
