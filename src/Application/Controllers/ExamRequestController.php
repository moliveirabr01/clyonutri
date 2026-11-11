<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\LaboratoryExam\ExamCatalogService;
use ClyoNutri\Domain\LaboratoryExam\ExamRegulatoryPolicy;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;
use ClyoNutri\Domain\LaboratoryExam\ProfessionalEligibilityValidator;
use ClyoNutri\Domain\Patient\PatientRepository;
use PDO;

/**
 * Pedido de exames laboratoriais (NI2-529 a NI2-533; v45).
 * Todas as rotas passam por $requireAuth em public/index.php (sessão, papel clínico, CSRF de POST no Router).
 */
class ExamRequestController
{
    private PDO $db;
    private ExamRequestService $requestService;
    private ExamCatalogService $catalogService;
    private PatientRepository $patientRepo;

    public function __construct(?PDO $db = null, ?ExamRequestService $service = null)
    {
        $this->db = $db ?? Connection::getInstance();
        $this->requestService = $service ?? new ExamRequestService($this->db);
        $this->catalogService = new ExamCatalogService($this->db);
        $this->patientRepo = new PatientRepository($this->db);
    }

    /** GET /pacientes/{id}/exames */
    public function index(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient) {
            return;
        }
        $requests = $this->requestService->listByPatient((int)$patient['id'], TenantContext::getClinicId());
        $resultCounts = (new \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService($this->db))->countsByRequest(array_column($requests, 'id'), TenantContext::getClinicId());
        $flash = $this->takeFlash();
        $this->noStore();
        $this->render('exams', 'Solicitações de exames — ' . $patient['name'], compact('patient', 'requests', 'flash', 'resultCounts'));
    }

    private function saveWithRevision(int $patientId,array $input,?int $id=null): array
    {
        if (isset($_POST['_workflow_revision'],$_POST['_final_request_key']) && is_string($_POST['_workflow_revision']) && is_string($_POST['_final_request_key'])) {
            $result=(new \ClyoNutri\Domain\Clinical\WorkflowWrites($this->db))->write('exam',$id??0,$patientId,$_POST['_final_request_key'],$_POST['_workflow_revision'],$input,function()use($patientId,$input,$id){$r=$this->requestService->saveDraft($patientId,(int)TenantContext::getUserId(),$input,$id);return ['resource_id'=>(int)$r['id']];});
            return $this->requestService->getById($result['resource_id'],(int)TenantContext::getClinicId())??[];
        }
        return $this->requestService->saveDraft($patientId,(int)TenantContext::getUserId(),$input,$id);
    }

    /** GET /pacientes/{id}/exames/novo */
    public function create(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient) {
            return;
        }
        // Vindo da consulta (/consulta/{id}): ?atendimento= já vem marcado; renderForm só aceita se for do paciente.
        $this->renderForm($patient, null, ['appointment_id' => (int)($_GET['atendimento'] ?? 0)]);
    }

    /** POST /pacientes/{id}/exames — salva o rascunho (não emite). */
    public function store(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient || !$this->csrfOk()) {
            return;
        }
        $input = $this->formInput();
        try {
            $saved = $this->saveWithRevision((int)$patient['id'], $input);
            $this->flash('success', 'Rascunho salvo. Confira e envie para revisão quando estiver pronto.');
            $this->redirect("/pacientes/{$patient['id']}/exames/{$saved['id']}");
        } catch (\Throwable $e) {
            $this->renderForm($patient, null, $input, $this->userMessage($e), 422);
        }
    }

    /** GET /pacientes/{id}/exames/{reqId}/editar */
    public function edit(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient) {
            return;
        }
        $request = $this->requestOr404($params, $patient);
        if (!$request) {
            return;
        }
        if (!in_array($request['status'], ['draft', 'review'], true)) {
            $this->flash('error', 'Solicitação emitida ou cancelada não pode ser editada. Para corrigir uma emitida, use "Corrigir (nova versão)".');
            $this->redirect("/pacientes/{$patient['id']}/exames/{$request['id']}");
            return;
        }
        $input = [
            'exam_ids' => array_map(fn($i) => (int)$i['exam_id'], $request['items']),
            'matched_synonym' => array_column($request['items'], 'matched_synonym', 'exam_id'),
            'instructions' => array_column($request['items'], 'specific_instructions', 'exam_id'),
            'clinical_justification' => $request['clinical_justification'],
            'diagnostic_hypothesis_nutritional' => $request['diagnostic_hypothesis_nutritional'],
            'coverage_disclaimer_acknowledged' => (int)$request['coverage_disclaimer_acknowledged'] === 1,
            'appointment_id' => (int)($request['appointment_id'] ?? 0),
        ];
        $this->renderForm($patient, $request, $input);
    }

    /** POST /pacientes/{id}/exames/{reqId}/editar */
    public function update(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient || !$this->csrfOk()) {
            return;
        }
        $request = $this->requestOr404($params, $patient);
        if (!$request) {
            return;
        }
        $input = $this->formInput();
        try {
            $this->saveWithRevision((int)$patient['id'], $input, (int)$request['id']);
            $this->flash('success', $request['status'] === 'review'
                ? 'Alterações salvas. A solicitação voltou para rascunho; envie para revisão de novo.'
                : 'Rascunho atualizado.');
            $this->redirect("/pacientes/{$patient['id']}/exames/{$request['id']}");
        } catch (\Throwable $e) {
            $this->renderForm($patient, $request, $input, $this->userMessage($e), 422);
        }
    }

    /** POST /pacientes/{id}/exames/{reqId}/revisar */
    public function review(array $params): void
    {
        $this->transition($params, function (array $request) {
            $this->requestService->submitForReview((int)$request['id'], (int)TenantContext::getUserId());
            return 'Solicitação em revisão. Confira o documento abaixo e, se estiver tudo certo, emita.';
        });
    }

    /** POST /pacientes/{id}/exames/{reqId}/emitir — gera o PDF no servidor e só então marca como emitido. */
    public function issue(array $params): void
    {
        $this->transition($params, function (array $request) {
            $ack = (string)($_POST['coverage_disclaimer_acknowledged'] ?? '') === '1';
            $this->requestService->issue((int)$request['id'], (int)TenantContext::getUserId(), $ack);
            return 'Solicitação emitida. O PDF foi gerado e guardado; use Imprimir ou Baixar PDF.';
        });
    }

    /** POST /pacientes/{id}/exames/{reqId}/nova-versao — corrige uma emitida: ela é cancelada e nasce a versão seguinte. */
    public function newVersion(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient || !$this->csrfOk()) {
            return;
        }
        $request = $this->requestOr404($params, $patient);
        if (!$request) {
            return;
        }
        try {
            $new = $this->requestService->createNewVersion((int)$request['id'], (int)TenantContext::getUserId(), (string)($_POST['version_reason'] ?? ''));
            $this->flash('success', 'Versão ' . (int)$new['version_number'] . ' criada como rascunho. A anterior ficou cancelada, com o PDF guardado no histórico. Ajuste, revise e emita.');
            $this->redirect("/pacientes/{$patient['id']}/exames/{$new['id']}/editar");
        } catch (\Throwable $e) {
            $this->flash('error', $this->userMessage($e));
            $this->redirect("/pacientes/{$patient['id']}/exames/{$request['id']}");
        }
    }

    /** POST /pacientes/{id}/exames/{reqId}/cancelar */
    public function cancel(array $params): void
    {
        $this->transition($params, function (array $request) {
            $this->requestService->cancelRequest((int)$request['id'], (int)TenantContext::getUserId(), (string)($_POST['cancellation_reason'] ?? ''));
            return 'Solicitação cancelada. O histórico foi mantido.';
        });
    }

    /** GET /pacientes/{id}/exames/{reqId} */
    public function show(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient) {
            return;
        }
        $request = $this->requestOr404($params, $patient);
        if (!$request) {
            return;
        }
        $policy = ExamRegulatoryPolicy::policyForRequest($this->db, $request);
        $doc = ExamRequestService::buildDocument($request, $policy);
        $flash = $this->takeFlash();
        $csrfToken = Csrf::token();
        $this->requestService->recordView($request, (int)TenantContext::getUserId());
        $this->noStore();
        // v48 (NI2-539): laudos anexados e situação por exame.
        $resultSvc = new \ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService($this->db);
        $resultDocs = $resultSvc->listForRequest((int)$request['id'], TenantContext::getClinicId());
        $coverage = $resultSvc->coverageByItem((int)$request['id'], TenantContext::getClinicId());
        $this->render('exam_show', 'Solicitação de exames ' . $request['request_number'],
            compact('patient', 'request', 'policy', 'doc', 'flash', 'csrfToken', 'resultDocs', 'coverage'));
    }

    /** GET /pacientes/{id}/exames/{reqId}/imprimir — folha A4 limpa (sem menu), só para pedido emitido ou cancelado. */
    public function printView(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient) {
            return;
        }
        $request = $this->requestOr404($params, $patient);
        if (!$request) {
            return;
        }
        if (!in_array($request['status'], ['issued', 'cancelled'], true)) {
            $this->flash('error', 'A impressão fica disponível depois que a solicitação é emitida.');
            $this->redirect("/pacientes/{$patient['id']}/exames/{$request['id']}");
            return;
        }
        $policy = ExamRegulatoryPolicy::policyForRequest($this->db, $request);
        $doc = ExamRequestService::buildDocument($request, $policy);
        $this->requestService->recordPrintView($request, (int)TenantContext::getUserId());
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: same-origin');
            header('X-Frame-Options: DENY');
        }
        require __DIR__ . '/../../../views/patients/exam_print.php';
    }

    /** GET /pacientes/{id}/exames/{reqId}/pdf — download autenticado e isolado por clínica, com hash conferido. */
    public function downloadPdf(array $params): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient) {
            return;
        }
        try {
            $file = $this->requestService->pdfForDownload((int)($params['reqId'] ?? 0), (int)$patient['id'], (int)TenantContext::getUserId());
        } catch (\Throwable $e) {
            $this->flash('error', $this->userMessage($e));
            $this->redirect("/pacientes/{$patient['id']}/exames/" . (int)($params['reqId'] ?? 0));
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
            header('Content-Length: ' . strlen($file['bytes']));
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
        }
        echo $file['bytes'];
    }

    /** GET /api/exames/buscar?q=&category= — exige sessão de profissional com clínica. */
    public function searchApi(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        if (!TenantContext::hasClinic() || !TenantContext::getUserId()) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'error' => 'Sessão expirada. Entre de novo.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $query = mb_substr((string)($_GET['q'] ?? ''), 0, 120, 'UTF-8');
        $clinicId = TenantContext::getClinicId();
        $fav = array_flip($this->catalogService->favoriteIds($clinicId, (int)TenantContext::getUserId()));
        $category = !empty($_GET['category']) ? mb_substr((string)$_GET['category'], 0, 80, 'UTF-8') : null;
        $results = array_map(static fn(array $r) => [
            'id' => (int)$r['id'],
            'canonical_name' => (string)$r['canonical_name'],
            'short_name' => (string)($r['short_name'] ?? ''),
            'category' => (string)$r['category'],
            'sample_type' => (string)$r['sample_type'],
            'matched_synonym' => $r['matched_synonym'] ?? null,
            'is_favorite' => isset($fav[(int)$r['id']]),
            'origin' => (string)($r['origin'] ?? 'curated'),
        ], $this->catalogService->search($query, $category, true, 60, $clinicId));
        echo json_encode(['status' => 'success', 'count' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ---------------------------------------------------------------- internos

    private function transition(array $params, callable $action): void
    {
        $patient = $this->patientOr404($params);
        if (!$patient || !$this->csrfOk()) {
            return;
        }
        $request = $this->requestOr404($params, $patient);
        if (!$request) {
            return;
        }
        try {
            $this->flash('success', (string)$action($request));
        } catch (\Throwable $e) {
            $this->flash('error', $this->userMessage($e));
        }
        $this->redirect("/pacientes/{$patient['id']}/exames/{$request['id']}");
    }

    private function renderForm(array $patient, ?array $request, array $old, ?string $errorMessage = null, int $status = 200): void
    {
        $clinicId = TenantContext::getClinicId();
        $eligibilityError = null;
        try {
            ProfessionalEligibilityValidator::validateEligibility((int)TenantContext::getUserId(), $clinicId, $this->db);
        } catch (\Throwable $e) {
            $eligibilityError = $this->userMessage($e);
        }
        $policy = ExamRegulatoryPolicy::getActivePolicy($this->db);
        $categories = $this->catalogService->listCategories($clinicId);
        $favoriteIds = $this->catalogService->favoriteIds($clinicId, (int)TenantContext::getUserId());
        // Favoritos primeiro (só atalho visual: nada vem marcado), depois o resto do catálogo em ordem alfabética.
        $catalog = $this->catalogService->search('', null, true, 500, $clinicId);
        $favPos = array_flip($favoriteIds);
        usort($catalog, static fn($a, $b) => [isset($favPos[$a['id']]) ? 0 : 1, $favPos[$a['id']] ?? 0, $a['canonical_name']]
            <=> [isset($favPos[$b['id']]) ? 0 : 1, $favPos[$b['id']] ?? 0, $b['canonical_name']]);
        $appointments = $this->requestService->appointmentsForPatient((int)$patient['id'], $clinicId);
        if (empty($old['appointment_id']) && $request === null) {
            $now = time();
            $maxTime = $now + 7 * 86400;
            $closest = null;
            $closestDiff = PHP_INT_MAX;
            foreach ($appointments as $apt) {
                $aptTime = strtotime((string)$apt['scheduled_at']);
                if ($aptTime !== false && $aptTime >= ($now - 86400) && $aptTime <= $maxTime) {
                    $diff = abs($aptTime - $now);
                    if ($diff < $closestDiff) {
                        $closestDiff = $diff;
                        $closest = (int)$apt['id'];
                    }
                }
            }
            if ($closest !== null) {
                $old['appointment_id'] = $closest;
            }
        }
        if (!in_array((int)($old['appointment_id'] ?? 0), array_map('intval', array_column($appointments, 'id')), true)) {
            $old['appointment_id'] = 0; // ?atendimento= de outro paciente ou clínica é ignorado
        }
        $csrfToken = Csrf::token();
        $flash = $this->takeFlash(); // ex.: "Versão 2 criada" ao chegar da nova versão
        http_response_code($status);
        $this->render('exam_new', $request ? 'Editar solicitação de exames' : 'Nova solicitação de exames',
            compact('patient', 'request', 'old', 'errorMessage', 'eligibilityError', 'policy', 'categories', 'catalog', 'csrfToken', 'favoriteIds', 'appointments', 'flash'));
    }

    private function formInput(): array
    {
        $synonyms = [];
        foreach ((array)($_POST['matched_synonym'] ?? []) as $k => $v) {
            if (is_scalar($v)) {
                $synonyms[(int)$k] = mb_substr((string)$v, 0, 255, 'UTF-8');
            }
        }
        $instructions = [];
        foreach ((array)($_POST['specific_instructions'] ?? []) as $k => $v) {
            if (is_scalar($v)) {
                $instructions[(int)$k] = (string)$v;
            }
        }
        return [
            'exam_ids' => array_map('intval', array_filter((array)($_POST['exam_ids'] ?? []), 'is_scalar')),
            'matched_synonym' => $synonyms,
            'instructions' => $instructions,
            'clinical_justification' => (string)($_POST['clinical_justification'] ?? ''),
            'diagnostic_hypothesis_nutritional' => (string)($_POST['diagnostic_hypothesis_nutritional'] ?? ''),
            'coverage_disclaimer_acknowledged' => (string)($_POST['coverage_disclaimer_acknowledged'] ?? '') === '1',
            'exam_order' => is_scalar($_POST['exam_order'] ?? null) ? mb_substr((string)$_POST['exam_order'], 0, 2000, 'UTF-8') : '',
            'appointment_id' => is_scalar($_POST['appointment_id'] ?? null) ? (int)$_POST['appointment_id'] : 0,
        ];
    }

    private function noStore(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
        }
    }

    private function patientOr404(array $params): ?array
    {
        $patient = $this->patientRepo->findById((int)($params['id'] ?? 0), TenantContext::getClinicId());
        if (!$patient) {
            http_response_code(404);
            echo 'Paciente não encontrado.';
            return null;
        }
        return $patient;
    }

    private function requestOr404(array $params, array $patient): ?array
    {
        $request = $this->requestService->getById((int)($params['reqId'] ?? 0), TenantContext::getClinicId());
        if (!$request || (int)$request['patient_id'] !== (int)$patient['id']) {
            http_response_code(404);
            echo 'Solicitação de exames não encontrada nesta clínica.';
            return null;
        }
        return $request;
    }

    /** O Router já valida o CSRF de POST fora de /api; esta checagem extra cobre chamadas diretas ao controller. */
    private function csrfOk(): bool
    {
        if (!Csrf::validate($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Sua sessão de formulário expirou. Recarregue a página e tente de novo.';
            return false;
        }
        return true;
    }

    private function userMessage(\Throwable $e): string
    {
        if ($e instanceof \PDOException) {
            error_log('EXAM_REQUEST_DB_ERROR ' . $e->getMessage());
            return 'Não foi possível concluir agora. Nada foi emitido; tente de novo em instantes.';
        }
        if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException
            || get_class($e) === \RuntimeException::class
            || $e instanceof \ClyoNutri\Core\Security\TenantSecurityException) {
            return $e->getMessage();
        }
        error_log('EXAM_REQUEST_ERROR ' . get_class($e) . ': ' . $e->getMessage());
        return 'Não foi possível concluir agora. Nada foi emitido; tente de novo em instantes.';
    }

    private function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['exam_flash'] = ['type' => $type, 'message' => $message];
        }
    }

    private function takeFlash(): ?array
    {
        $f = $_SESSION['exam_flash'] ?? null;
        unset($_SESSION['exam_flash']);
        return is_array($f) ? $f : null;
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
        extract($vars, EXTR_SKIP);
        $activeNav = 'pacientes';
        $examOrigin=(int)($request['appointment_id'] ?? $old['appointment_id'] ?? $_GET['atendimento'] ?? 0);
        $examReturnUrl=null;
        if($examOrigin>0 && isset($patient)){
            $origin=$this->db->prepare('SELECT id FROM appointments WHERE id=? AND patient_id=? AND clinic_id=?');
            $origin->execute([$examOrigin,(int)$patient['id'],TenantContext::getClinicId()]);
            if($origin->fetchColumn())$examReturnUrl='/consulta/'.$examOrigin;
        }
        ob_start();
        if($examReturnUrl)echo '<p><a class="btn btn-secondary" href="'.htmlspecialchars($examReturnUrl,ENT_QUOTES,'UTF-8').'">Voltar ao atendimento de origem</a></p>';
        require __DIR__ . '/../../../views/patients/' . $view . '.php';
        $content = ob_get_clean();
        require __DIR__ . '/../../../views/layouts/professional-shell.php';
    }
}
