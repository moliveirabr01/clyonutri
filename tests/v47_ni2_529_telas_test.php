<?php
/**
 * v47: controladores e telas (renderização real das views, sem avisos de PHP), isolamento por clínica nas rotas
 * novas e conferência de integridade dos PDFs (NI2-542). Dados só de TESTE.
 */
declare(strict_types=1);
require __DIR__ . '/_v47_exam_bootstrap.php';

use ClyoNutri\Application\Controllers\ExamCatalogController;
use ClyoNutri\Application\Controllers\ExamRequestController;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\LaboratoryExam\ExamCatalogService;
use ClyoNutri\Domain\LaboratoryExam\ExamPdfIntegrityChecker;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestPdfService;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;

ob_start(); // nada sai antes dos cabeçalhos (o relatório vai por STDOUT)
set_error_handler(function (int $no, string $str, string $file, int $line) {
    throw new ErrorException("{$str} em {$file}:{$line}", 0, $no, $file, $line);
});
session_start();
$_SESSION['clinic_name'] = 'Clínica TESTE A';
$_SESSION['user_name'] = 'Dona TESTE A';

$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$ownerA = Fx::user($pdo, $cA, 'Dona TESTE A', 'owner');
$nutriA = Fx::user($pdo, $cA, 'Nutri TESTE A2', 'nutritionist');
$secA = Fx::user($pdo, $cA, 'Secretaria TESTE A', 'secretary', null, null, 'secretary');
$ownerB = Fx::user($pdo, $cB, 'Dona TESTE B', 'owner');
$pA = Fx::patient($pdo, $cA, 'Paciente TESTE A');
$pB = Fx::patient($pdo, $cB, 'Paciente TESTE B');
$apA = Fx::appointment($pdo, $cA, $pA, $ownerA['professional_id']);
$G = Fx::examId($pdo, 'GLICEMIA_JEJUM');
$F = Fx::examId($pdo, 'FERRITINA_SERICA');
$J = 'Avaliar glicemia e reservas de ferro para ajuste do plano alimentar.';

function as_user(array $u): void { Fx::act($u); $_SESSION['user_id'] = $u['user_id']; $_SESSION['clinic_id'] = $u['clinic_id']; }
function page(callable $fn): array
{
    http_response_code(200);
    ob_start();
    try {
        $fn();
    } finally {
        $html = (string)ob_get_clean();
    }
    return [http_response_code(), $html];
}
function post(array $data): void { $_POST = $data + ['_csrf_token' => Csrf::token()]; $_SERVER['REQUEST_METHOD'] = 'POST'; }
function get(array $q = []): void { $_POST = []; $_GET = $q; $_SERVER['REQUEST_METHOD'] = 'GET'; }

$req = new ExamRequestController($pdo);
$catCtl = new ExamCatalogController($pdo);
$svc = new ExamRequestService($pdo);

fwrite(STDOUT, "== Editor (NI2-533)\n");
as_user($ownerA);
get(['atendimento' => (string)$apA]);
[$code, $html] = page(fn() => $req->create(['id' => $pA]));
T::ok($code === 200 && str_contains($html, 'Nova solicitação de exames laboratoriais'), 'editor abre com o título novo');
T::ok((bool)preg_match('/<option value="' . $apA . '" selected>/', $html), 'atendimento vindo da consulta já vem selecionado');
T::ok(str_contains($html, 'name="exam_order"') && str_contains($html, 'id="exam-order-list"'), 'editor tem a ordem dos exames');
T::ok(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html) && !preg_match('/\son[a-z]+="/i', $html), 'editor sem script inline nem onclick (CSP)');
get(['atendimento' => '999999']);
[, $html] = page(fn() => $req->create(['id' => $pA]));
T::ok(!str_contains($html, '" selected>') || str_contains($html, '<option value="0">Sem vínculo'), 'atendimento de fora é ignorado no editor');
T::ok(!preg_match('/<option value="999999"/', $html), 'não lista atendimento que não é do paciente');

post(['exam_ids' => [$G, $F], 'exam_order' => "$F,$G", 'clinical_justification' => $J, 'appointment_id' => (string)$apA]);
[$code] = page(fn() => $req->store(['id' => $pA]));
$id1 = (int)$pdo->query("SELECT MAX(id) FROM exam_requests WHERE clinic_id = {$cA}")->fetchColumn();
$r1 = $svc->getById($id1, $cA);
T::ok($r1 && array_map('intval', array_column($r1['items'], 'exam_id')) === [$F, $G] && (int)$r1['appointment_id'] === $apA, 'salvar pelo formulário grava ordem e atendimento');
post(['exam_ids' => [$G], 'clinical_justification' => $J, 'appointment_id' => '999999']);
[$code, $html] = page(fn() => $req->store(['id' => $pA]));
T::ok($code === 422 && str_contains($html, 'Atendimento não encontrado') && str_contains($html, 'data-start-dirty'), 'erro devolve o formulário preenchido e marcado como não salvo');

$favCount = 0;
(new ExamCatalogService($pdo))->toggleFavorite($cA, $ownerA['user_id'], $F);
get();
[, $html] = page(fn() => $req->create(['id' => $pA]));
$posF = strpos($html, 'data-exam-item="' . $F . '"');
$posG = strpos($html, 'data-exam-item="' . $G . '"');
T::ok($posF !== false && $posG !== false && $posF < $posG && str_contains($html, '★ Meus favoritos'), 'favorito aparece primeiro e tem filtro próprio');
T::ok(!preg_match('/data-exam-item="' . $F . '"[^>]*>\s*<input type="checkbox"[^>]*checked/', $html), 'favorito não vem marcado');

fwrite(STDOUT, "== Solicitação, nova versão e trilha (NI2-537, NI2-542)\n");
get();
[$code, $html] = page(fn() => $req->show(['id' => $pA, 'reqId' => $id1]));
T::ok($code === 200 && str_contains($html, 'Solicitação ') && str_contains($html, 'Atendimento de origem'), 'tela da solicitação mostra o atendimento de origem');
$views = (int)$pdo->query("SELECT COUNT(*) FROM exam_audit_logs WHERE action = 'EXAM_REQUEST_VIEWED' AND entity_id = {$id1}")->fetchColumn();
T::ok($views === 1, 'abrir a solicitação fica na trilha');
$svc->submitForReview($id1, $ownerA['user_id']);
$svc->issue($id1, $ownerA['user_id'], true);
get();
[, $html] = page(fn() => $req->show(['id' => $pA, 'reqId' => $id1]));
T::ok(str_contains($html, 'Corrigir (nova versão)') && str_contains($html, '/nova-versao'), 'emitida oferece "Corrigir (nova versão)"');
post(['version_reason' => 'Trocar a ordem dos exames TESTE']);
page(fn() => $req->newVersion(['id' => $pA, 'reqId' => $id1]));
$id2 = (int)$pdo->query("SELECT id FROM exam_requests WHERE supersedes_request_id = {$id1}")->fetchColumn();
T::ok($id2 > 0, 'rota de nova versão cria a versão 2');
get();
[, $html] = page(fn() => $req->show(['id' => $pA, 'reqId' => $id2]));
T::ok(str_contains($html, 'versão 2') && str_contains($html, 'Substitui a solicitação'), 'versão 2 mostra qual substitui');
[, $html] = page(fn() => $req->show(['id' => $pA, 'reqId' => $id1]));
T::ok(str_contains($html, 'Substituída') && str_contains($html, 'Substituída pela versão 2'), 'anterior mostra que foi substituída e o motivo');
[$code, $html] = page(fn() => $req->index(['id' => $pA]));
T::ok($code === 200 && str_contains($html, 'Solicitações de exames laboratoriais') && str_contains($html, 'versão 2'), 'lista do paciente com versão');

as_user($ownerB);
get();
[$code, $html] = page(fn() => $req->show(['id' => $pA, 'reqId' => $id1]));
T::ok($code === 404 && !str_contains($html, 'EXAM-'), 'outra clínica: 404 sem dados');
post(['version_reason' => 'Tentativa de outra clínica']);
[$code] = page(fn() => $req->newVersion(['id' => $pA, 'reqId' => $id2]));
T::ok($code === 404, 'outra clínica não cria versão (404)');

fwrite(STDOUT, "== Prontuário e consulta (NI2-537)\n");
as_user($ownerA);
$examTimeline = $svc->timeline($pA, $cA);
$examPatientId = $pA; $examAppointmentId = null;
ob_start(); require __DIR__ . '/../views/patients/partials/exam_timeline.php'; $html = (string)ob_get_clean();
T::ok(str_contains($html, 'Solicitações de exames laboratoriais (2)') && str_contains($html, 'consulta de'), 'linha do tempo do prontuário com as 2 e o atendimento');
$examTimeline = $svc->timeline($pA, $cA, $apA);
$examAppointmentId = $apA;
ob_start(); require __DIR__ . '/../views/patients/partials/exam_timeline.php'; $html = (string)ob_get_clean();
T::ok(str_contains($html, 'desta consulta (2)') && str_contains($html, '?atendimento=' . $apA), 'bloco da consulta leva o atendimento para a nova solicitação');
$examTimeline = [];
ob_start(); require __DIR__ . '/../views/patients/partials/exam_timeline.php'; $html = (string)ob_get_clean();
T::ok(str_contains($html, 'Nenhuma solicitação de exames vinculada a esta consulta'), 'estado vazio útil');

fwrite(STDOUT, "== Catálogo, exames da clínica e política (NI2-532, NI2-530)\n");
get();
[$code, $html] = page(fn() => $catCtl->index());
T::ok($code === 200 && str_contains($html, 'Catálogo de exames laboratoriais') && str_contains($html, 'data-testid="exam-catalog-review-notice"') && str_contains($html, 'a revisão do nutricionista responsável técnico'), 'catálogo abre e avisa a dona da revisão clínica pendente (texto da NI2-891)');
T::ok(substr_count($html, 'aria-pressed="true"') === 1, 'mostra o favorito marcado');
post(['canonical_name' => 'Tomografia de abdome', 'category' => 'Imagem', 'sample_type' => '-', 'nutritional_indication' => 'Avaliar gordura visceral TESTE.', 'declaration' => '1']);
[$code, $html] = page(fn() => $catCtl->submitCustom());
T::ok($code === 422 && str_contains($html, 'não permitido') && str_contains($html, 'Tomografia de abdome'), 'proposta de imagem recusada com o formulário preenchido');
as_user($nutriA);
post(['canonical_name' => 'Selênio sérico', 'category' => 'Micronutrientes e Vitaminas', 'sample_type' => 'Soro', 'nutritional_indication' => 'Avaliar selênio em dieta restritiva TESTE.', 'declaration' => '1']);
page(fn() => $catCtl->submitCustom());
$sel = (int)$pdo->query("SELECT id FROM exam_catalog WHERE canonical_name = 'Selênio sérico'")->fetchColumn();
T::ok($sel > 0, 'nutricionista propõe exame pela tela');
get();
[, $html] = page(fn() => $catCtl->index());
T::ok(str_contains($html, 'Aguardando validação') && !str_contains($html, '/validar"'), 'nutricionista vê a situação mas não o botão de validar');
post(['decision' => 'approve']);
page(fn() => $catCtl->validateCustom(['examId' => $sel]));
T::ok($pdo->query("SELECT validation_status FROM exam_catalog WHERE id = {$sel}")->fetchColumn() === 'pending', 'nutricionista não valida pela rota');
as_user($ownerB);
post(['decision' => 'approve']);
page(fn() => $catCtl->validateCustom(['examId' => $sel]));
T::ok($pdo->query("SELECT validation_status FROM exam_catalog WHERE id = {$sel}")->fetchColumn() === 'pending', 'outra clínica não valida pela rota');
get();
[, $html] = page(fn() => $catCtl->index());
T::ok(!str_contains($html, 'Selênio sérico'), 'outra clínica não vê o exame proposto');
as_user($ownerA);
get();
[, $html] = page(fn() => $catCtl->index());
T::ok(str_contains($html, '/personalizados/' . $sel . '/validar'), 'responsável vê o botão de validar');
post(['decision' => 'approve', 'validation_note' => 'Ok TESTE']);
page(fn() => $catCtl->validateCustom(['examId' => $sel]));
T::ok($pdo->query("SELECT validation_status FROM exam_catalog WHERE id = {$sel}")->fetchColumn() === 'approved', 'responsável valida pela rota');
$acts = $pdo->query("SELECT action FROM exam_audit_logs WHERE entity_type = 'exam_catalog' AND clinic_id = {$cA}")->fetchAll(PDO::FETCH_COLUMN);
T::ok(in_array('EXAM_CUSTOM_SUBMITTED', $acts, true) && in_array('EXAM_CUSTOM_APPROVED', $acts, true), 'proposta e validação ficam na trilha');
as_user($secA);
post([]);
page(fn() => $catCtl->toggleFavorite(['examId' => $G]));
T::ok((int)$pdo->query("SELECT COUNT(*) FROM exam_favorites WHERE user_id = {$secA['user_id']}")->fetchColumn() === 0, 'secretária não marca favorito');
post(['canonical_name' => 'Iodo urinário', 'category' => 'Micronutrientes e Vitaminas', 'sample_type' => 'Urina', 'nutritional_indication' => 'Avaliar iodo em gestante TESTE.', 'declaration' => '1']);
[$code] = page(fn() => $catCtl->submitCustom());
T::ok($code === 422 && (int)$pdo->query("SELECT COUNT(*) FROM exam_catalog WHERE canonical_name = 'Iodo urinário'")->fetchColumn() === 0, 'secretária não propõe exame');

as_user($ownerA);
$pdo->prepare("INSERT INTO exam_regulatory_policies (policy_version, legal_basis, statement, disclaimer_coverage, is_approved_clinical, is_approved_legal, effective_from, created_at, change_summary) VALUES ('2099.1', 'B', 'S', 'C', 1, 1, '2099-01-01', CURRENT_TIMESTAMP, 'Futura TESTE')")->execute();
get();
[$code, $html] = page(fn() => $catCtl->policy());
T::ok($code === 200 && str_contains($html, 'Versão 2026.1') && str_contains($html, 'Vigente') && str_contains($html, 'Futura'), 'página da política mostra vigente e futura');

fwrite(STDOUT, "== Conferência dos PDFs (NI2-542)\n");
$pdfSvc = new ExamRequestPdfService();
$checker = new ExamPdfIntegrityChecker($pdo, $pdfSvc);
$rep = $checker->run();
T::ok($rep['checked'] === 1 && $rep['ok'] === 1 && !$rep['missing'] && !$rep['mismatch'], 'PDF da emitida confere (cancelada mantém o arquivo)');
$path = (string)$pdo->query("SELECT pdf_path FROM exam_requests WHERE id = {$id1}")->fetchColumn();
$abs = $pdfSvc->absolutePath($path);
file_put_contents($abs, file_get_contents($abs) . ' ');
$rep = $checker->run();
T::ok(count($rep['mismatch']) === 1 && $rep['mismatch'][0]['id'] === $id1, 'arquivo alterado é apontado');
unlink($abs);
$rep = $checker->run();
T::ok(count($rep['missing']) === 1, 'arquivo apagado é apontado');
file_put_contents(dirname($abs) . '/SOBRA-TESTE.pdf', '%PDF-teste');
$rep = $checker->run();
T::ok(count($rep['orphans']) === 1 && $rep['orphans'][0]['file'] === 'SOBRA-TESTE.pdf' && is_file(dirname($abs) . '/SOBRA-TESTE.pdf'), 'sobra é listada e não apagada');
T::ok($checker->run($cB)['checked'] === 0, 'filtro por clínica');
T::ok(!str_contains(json_encode($rep), 'Paciente TESTE'), 'relatório sem nome de paciente');

ob_end_clean();
T::done();
