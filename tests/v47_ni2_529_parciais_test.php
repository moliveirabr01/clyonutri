<?php
/**
 * v47 · NI2-530, NI2-532, NI2-533, NI2-537, NI2-542: testes de domínio (dados só de TESTE).
 */
declare(strict_types=1);
require __DIR__ . '/_v47_exam_bootstrap.php';

use ClyoNutri\Domain\LaboratoryExam\ExamCatalogService;
use ClyoNutri\Domain\LaboratoryExam\ExamRegulatoryPolicy;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;

$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$ownerA = Fx::user($pdo, $cA, 'Dona TESTE A', 'owner');
$nutriA = Fx::user($pdo, $cA, 'Nutri TESTE A2', 'nutritionist');
$ownerB = Fx::user($pdo, $cB, 'Dona TESTE B', 'owner');
$pA = Fx::patient($pdo, $cA, 'Paciente TESTE A');
$pA2 = Fx::patient($pdo, $cA, 'Paciente TESTE A2');
$pB = Fx::patient($pdo, $cB, 'Paciente TESTE B');
$svc = new ExamRequestService($pdo);
$cat = new ExamCatalogService($pdo);
$G = Fx::examId($pdo, 'GLICEMIA_JEJUM');
$F = Fx::examId($pdo, 'FERRITINA_SERICA');
$H = Fx::examId($pdo, 'HEMOGRAMA_COMPLETO');
$J = 'Avaliar glicemia e reservas de ferro para ajuste do plano alimentar.';

// ------------------------------------------------------------------ NI2-530
echo "== NI2-530 política versionada\n";
Fx::act($ownerA);
$active = ExamRegulatoryPolicy::getActivePolicy($pdo);
T::ok($active['policy_version'] === '2026.1', 'vigente inicial é a 2026.1');
$r1 = $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$G], 'clinical_justification' => $J]);
$svc->submitForReview((int)$r1['id'], $ownerA['user_id']);
$r1 = $svc->issue((int)$r1['id'], $ownerA['user_id'], true);
T::ok($r1['policy_version'] === '2026.1', 'emitida grava 2026.1');
$pdo->prepare("INSERT INTO exam_regulatory_policies (policy_version, legal_basis, statement, disclaimer_coverage, is_approved_clinical, is_approved_legal, effective_from, created_at, change_summary)
  VALUES ('2027.1', 'Base TESTE 2027', 'Declaração TESTE', 'Aviso de cobertura TESTE 2027', 1, 1, ?, CURRENT_TIMESTAMP, 'Texto do aviso revisado (TESTE)')")
    ->execute([date('Y-m-d', strtotime('+30 days'))]);
T::ok(ExamRegulatoryPolicy::getActivePolicy($pdo)['policy_version'] === '2026.1', 'versão com vigência futura ainda não vale');
T::ok(ExamRegulatoryPolicy::getActivePolicy($pdo, date('Y-m-d', strtotime('+31 days')))['policy_version'] === '2027.1', 'vale a partir da data de vigência');
$pdo->exec("UPDATE exam_regulatory_policies SET effective_from = '" . date('Y-m-d', strtotime('-1 day')) . "' WHERE policy_version = '2027.1'");
T::ok(ExamRegulatoryPolicy::getActivePolicy($pdo)['policy_version'] === '2027.1', 'nova versão vigente é lida do banco');
$r2 = $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$F], 'clinical_justification' => $J]);
T::ok($r2['policy_version'] === '2027.1', 'rascunho novo usa a versão vigente');
$old = $svc->getById((int)$r1['id'], $cA);
$polOld = ExamRegulatoryPolicy::policyForRequest($pdo, $old);
T::ok($polOld['policy_version'] === '2026.1', 'documento emitido continua com a política da sua versão');
$docOld = ExamRequestService::buildDocument($old, $polOld);
T::ok(!str_contains($docOld['coverage_notice'], '2027'), 'aviso de cobertura do documento antigo não muda');
$versions = ExamRegulatoryPolicy::listVersions($pdo, $cA);
T::ok(count($versions) === 2 && $versions[0]['is_active'] && $versions[1]['requests_in_clinic'] === 1, 'lista as versões com a vigente e a contagem de solicitações');
$svB = ExamRegulatoryPolicy::listVersions($pdo, $cB);
T::ok($svB[1]['requests_in_clinic'] === 0, 'contagem por clínica não mostra a outra clínica');
T::throws(fn() => $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [999999], 'clinical_justification' => $J]), 'exame inexistente é recusado', 'não encontrado');

// ------------------------------------------------------------------ NI2-532
echo "== NI2-532 catálogo: personalizado com validação, favoritos, códigos e revisão clínica\n";
Fx::act($nutriA);
T::throws(fn() => $cat->submitCustomExam($cA, $nutriA['user_id'], ['canonical_name' => 'Ultrassonografia de abdome', 'category' => 'Imagem', 'sample_type' => 'N/A', 'nutritional_indication' => 'Avaliar esteatose hepática no acompanhamento.', 'declaration' => 1]), 'exame de imagem recusado já na proposta', 'não permitido');
T::throws(fn() => $cat->submitCustomExam($cA, $nutriA['user_id'], ['canonical_name' => 'Glicemia de jejum', 'category' => 'X', 'sample_type' => 'Soro', 'nutritional_indication' => 'Duplicado do catálogo geral TESTE.', 'declaration' => 1]), 'duplicado do catálogo recusado', 'Já existe');
T::throws(fn() => $cat->submitCustomExam($cA, $nutriA['user_id'], ['canonical_name' => 'Selênio sérico', 'category' => 'Micronutrientes', 'sample_type' => 'Soro', 'nutritional_indication' => 'Avaliar selênio em dieta restritiva.']), 'sem a declaração de pertinência é recusado', 'Confirme');
T::throws(fn() => $cat->submitCustomExam($cA, $nutriA['user_id'], ['canonical_name' => 'Selênio sérico', 'category' => 'Micronutrientes', 'sample_type' => 'Soro', 'nutritional_indication' => 'Avaliar selênio em dieta restritiva.', 'standard_code_system' => 'TUSS', 'declaration' => 1]), 'sistema de código sem código é recusado', 'juntos');
$sel = $cat->submitCustomExam($cA, $nutriA['user_id'], ['canonical_name' => 'Selênio sérico', 'category' => 'Micronutrientes e Vitaminas', 'sample_type' => 'Soro', 'fasting_hours' => '4', 'nutritional_indication' => 'Avaliar selênio em dieta restritiva e pós-bariátrica.', 'synonyms' => 'selenio, se serico', 'declaration' => 1]);
$selId = (int)$sel['id'];
T::ok($sel['validation_status'] === 'pending' && (int)$sel['clinic_id'] === $cA && $sel['clinical_review_status'] === 'pending', 'proposta fica aguardando validação, da clínica A, revisão clínica pendente');
T::ok(!in_array($selId, array_column($cat->search('selenio', null, true, 30, $cA), 'id'), true), 'pendente não aparece na busca');
T::throws(fn() => $svc->saveDraft($pA, $nutriA['user_id'], ['exam_ids' => [$selId], 'clinical_justification' => $J]), 'pendente não entra em solicitação', 'ainda não foi validado');
T::throws(fn() => $cat->validateCustomExam($cA, $nutriA['user_id'], $selId, true, ''), 'nutricionista sem papel de responsável não valida', 'Só a responsável');
T::throws(fn() => $cat->validateCustomExam($cB, $ownerB['user_id'], $selId, true, ''), 'responsável de outra clínica não valida', 'não encontrado');
Fx::act($ownerA);
T::throws(fn() => $cat->validateCustomExam($cA, $ownerA['user_id'], $selId, false, 'x'), 'recusa sem motivo é bloqueada', 'motivo');
$val = $cat->validateCustomExam($cA, $ownerA['user_id'], $selId, true, 'Pertinente (TESTE).');
T::ok($val['validation_status'] === 'approved' && (int)$val['validated_by_user_id'] === $ownerA['user_id'], 'responsável valida e fica registrado quem validou');
T::throws(fn() => $cat->validateCustomExam($cA, $ownerA['user_id'], $selId, true, ''), 'não valida duas vezes', 'já foi validado');
$hitsA = $cat->search('selenio', null, true, 30, $cA);
T::ok(in_array($selId, array_column($hitsA, 'id'), true), 'validado aparece na busca da clínica A');
T::ok(!in_array($selId, array_column($cat->search('selenio', null, true, 30, $cB), 'id'), true), 'não aparece na busca da clínica B');
T::ok(!in_array($selId, array_column($cat->search('selenio'), 'id'), true), 'busca sem clínica mostra só o catálogo geral');
Fx::act($ownerB);
T::throws(fn() => $svc->saveDraft($pB, $ownerB['user_id'], ['exam_ids' => [$selId], 'clinical_justification' => $J]), 'clínica B não usa exame personalizado da A (responde como inexistente)', 'não encontrado');
Fx::act($ownerA);
$rSel = $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$selId, $G], 'clinical_justification' => $J]);
T::ok(count($rSel['items']) === 2, 'clínica A usa o exame validado em solicitação');
$rej = $cat->submitCustomExam($cA, $nutriA['user_id'], ['canonical_name' => 'Cobre sérico', 'category' => 'Micronutrientes e Vitaminas', 'sample_type' => 'Soro', 'nutritional_indication' => 'Avaliar cobre em suplementação de zinco TESTE.', 'declaration' => 1]);
$rej = $cat->validateCustomExam($cA, $ownerA['user_id'], (int)$rej['id'], false, 'Sem pertinência para este serviço (TESTE).');
T::ok($rej['validation_status'] === 'rejected' && $rej['validation_note'] !== null, 'recusa fica registrada com o motivo');
T::ok(count($cat->listCustomExams($cA)) === 2 && count($cat->listCustomExams($cB)) === 0, 'lista de personalizados isolada por clínica');
T::ok(in_array('Micronutrientes e Vitaminas', $cat->listCategories($cA), true), 'categorias da clínica incluem as dos personalizados');

T::ok($cat->toggleFavorite($cA, $ownerA['user_id'], $F) === true, 'marca favorito');
$cat->toggleFavorite($cA, $ownerA['user_id'], $selId);
T::ok($cat->favoriteIds($cA, $ownerA['user_id']) === [$F, $selId], 'favoritos do usuário na ordem em que foram marcados');
T::ok($cat->favoriteIds($cA, $nutriA['user_id']) === [], 'favorito é por nutricionista');
T::ok($cat->toggleFavorite($cA, $ownerA['user_id'], $F) === false && $cat->favoriteIds($cA, $ownerA['user_id']) === [$selId], 'desmarca favorito');
T::throws(fn() => $cat->toggleFavorite($cB, $ownerB['user_id'], $selId), 'outra clínica não favorita exame personalizado da A', 'não encontrado');
$rFav = $svc->saveDraft($pA2, $ownerA['user_id'], ['exam_ids' => [$H], 'clinical_justification' => $J]);
T::ok(count($rFav['items']) === 1, 'favorito não pré-seleciona nada no pedido');
$pdo->prepare('UPDATE exam_catalog SET is_active = 0 WHERE id = ?')->execute([$selId]);
T::ok($cat->favoriteIds($cA, $ownerA['user_id']) === [], 'exame inativado sai dos favoritos');
T::ok(count($svc->getById((int)$rSel['id'], $cA)['items']) === 2, 'inativo continua no histórico da solicitação');
$pdo->prepare('UPDATE exam_catalog SET is_active = 1 WHERE id = ?')->execute([$selId]);
$g = $cat->getById($G);
T::ok(array_key_exists('standard_code', $g) && $g['standard_code'] === null && $g['clinical_review_status'] === 'pending', 'catálogo geral com campo de código (vazio, sem licença) e revisão clínica pendente');

// ------------------------------------------------------------------ NI2-533
echo "== NI2-533 editor: ordem e vínculo com o atendimento\n";
$apA = Fx::appointment($pdo, $cA, $pA, $ownerA['professional_id']);
$apA2 = Fx::appointment($pdo, $cA, $pA2, $ownerA['professional_id']);
$apB = Fx::appointment($pdo, $cB, $pB, $ownerB['professional_id']);
$rO = $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$G, $F, $H], 'exam_order' => "$H,$G,$F", 'clinical_justification' => $J, 'appointment_id' => $apA]);
T::ok(array_map('intval', array_column($rO['items'], 'exam_id')) === [$H, $G, $F], 'exames gravados na ordem escolhida');
T::ok((int)$rO['appointment_id'] === $apA && !empty($rO['appointment_scheduled_at']), 'solicitação vinculada ao atendimento de origem');
$rO2 = $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$G, $F, $H], 'exam_order' => [$F, $H], 'clinical_justification' => $J, 'appointment_id' => $apA], (int)$rO['id']);
T::ok(array_map('intval', array_column($rO2['items'], 'exam_id')) === [$F, $H, $G], 'reordenar na edição; o que ficou fora da ordem vai para o fim');
T::ok(ExamRequestService::applyOrder([1, 2, 3], '3,3,1') === [3, 1, 2], 'ordem ignora repetidos');
T::throws(fn() => $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$G], 'clinical_justification' => $J, 'appointment_id' => $apA2]), 'atendimento de outro paciente é recusado', 'Atendimento não encontrado');
T::throws(fn() => $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$G], 'clinical_justification' => $J, 'appointment_id' => $apB]), 'atendimento de outra clínica é recusado', 'Atendimento não encontrado');
$rNo = $svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$G], 'clinical_justification' => '', 'appointment_id' => '']);
T::ok($rNo['appointment_id'] === null, 'sem atendimento fica sem vínculo');
T::throws(fn() => $svc->submitForReview((int)$rNo['id'], $ownerA['user_id']), 'justificativa ausente bloqueia a revisão', 'justificativa');
T::ok(count($svc->appointmentsForPatient($pA, $cA)) === 1 && count($svc->appointmentsForPatient($pB, $cA)) === 0, 'atendimentos listados só do paciente e da clínica');

// ------------------------------------------------------------------ NI2-537
echo "== NI2-537 versões e linha do tempo\n";
$svc->submitForReview((int)$rO['id'], $ownerA['user_id']);
$v1 = $svc->issue((int)$rO['id'], $ownerA['user_id'], true);
$hashV1 = $v1['pdf_hash'];
T::throws(fn() => $svc->createNewVersion((int)$rFav['id'], $ownerA['user_id'], 'Corrigir exames'), 'rascunho não gera nova versão', 'Só uma solicitação emitida');
Fx::act($nutriA);
T::throws(fn() => $svc->createNewVersion((int)$v1['id'], $nutriA['user_id'], 'Corrigir exames'), 'outra nutricionista não gera versão da solicitação alheia', 'Só o nutricionista');
Fx::act($ownerA);
T::throws(fn() => $svc->createNewVersion((int)$v1['id'], $ownerA['user_id'], 'x'), 'nova versão exige motivo', 'mínimo');
$v2 = $svc->createNewVersion((int)$v1['id'], $ownerA['user_id'], 'Trocar hemograma por ferro sérico');
$v1b = $svc->getById((int)$v1['id'], $cA);
T::ok($v1b['status'] === 'cancelled' && str_contains($v1b['cancellation_reason'], 'Substituída pela versão 2'), 'anterior fica cancelada com o motivo da substituição');
T::ok($v1b['pdf_hash'] === $hashV1 && (int)$v1b['superseded_by_id'] === (int)$v2['id'], 'anterior mantém o PDF e o hash e aponta para a nova');
T::ok($v2['status'] === 'draft' && (int)$v2['version_number'] === 2 && (int)$v2['supersedes_request_id'] === (int)$v1['id'] && (int)$v2['root_request_id'] === (int)$v1['id'], 'nova é rascunho versão 2 ligada à anterior');
T::ok(count($v2['items']) === 3 && (int)$v2['appointment_id'] === $apA && $v2['clinical_justification'] === $J, 'nova copia exames, justificativa e atendimento');
T::ok((int)$v2['coverage_disclaimer_acknowledged'] === 0, 'ciência do aviso tem de ser dada de novo');
T::throws(fn() => $svc->createNewVersion((int)$v1['id'], $ownerA['user_id'], 'Corrigir de novo'), 'não gera duas versões da mesma emitida', 'Só uma solicitação emitida');
$svc->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [$G, $F, Fx::examId($pdo, 'FERRO_SERICO')], 'clinical_justification' => $J, 'appointment_id' => $apA, 'coverage_disclaimer_acknowledged' => 1], (int)$v2['id']);
$svc->submitForReview((int)$v2['id'], $ownerA['user_id']);
$v2 = $svc->issue((int)$v2['id'], $ownerA['user_id'], true);
$doc2 = ExamRequestService::buildDocument($v2, ExamRegulatoryPolicy::policyForRequest($pdo, $v2));
T::ok($doc2['version_number'] === 2 && $doc2['supersedes_request_number'] === $v1['request_number'], 'documento da versão 2 diz qual substitui');
$v3 = $svc->createNewVersion((int)$v2['id'], $ownerA['user_id'], 'Mais uma correção TESTE');
T::ok((int)$v3['version_number'] === 3 && (int)$v3['root_request_id'] === (int)$v1['id'], 'versão 3 aponta para a primeira da cadeia');
$tl = $svc->timeline($pA, $cA);
$byId = array_column($tl, null, 'id');
T::ok(isset($byId[$v1['id']], $byId[$v2['id']], $byId[$v3['id']]) && $byId[$v1['id']]['exam_names'][0] === 'Ferritina sérica', 'linha do tempo do paciente com exames na ordem');
$tlAp = $svc->timeline($pA, $cA, $apA);
T::ok(count($tlAp) === 3 && count(array_filter($tlAp, fn($r) => (int)$r['appointment_id'] !== $apA)) === 0, 'linha do tempo filtrada pelo atendimento');
T::throws(fn() => $svc->timeline($pA, $cB), 'outra clínica não lê a linha do tempo', null);
T::ok($svc->getById((int)$v1['id'], $cB) === null, 'outra clínica não acha a solicitação pelo id');

// ------------------------------------------------------------------ NI2-542
echo "== NI2-542 trilha\n";
$svc->recordView($svc->getById((int)$v2['id'], $cA), $ownerA['user_id']);
$acts = $pdo->query("SELECT action FROM exam_audit_logs WHERE clinic_id = {$cA}")->fetchAll(PDO::FETCH_COLUMN);
foreach (['EXAM_REQUEST_VIEWED', 'EXAM_REQUEST_SUPERSEDED', 'EXAM_REQUEST_NEW_VERSION_CREATED', 'EXAM_REQUEST_ISSUED'] as $a) {
    T::ok(in_array($a, $acts, true), "trilha registra {$a}");
}
$det = $pdo->query("SELECT details FROM exam_audit_logs WHERE action = 'EXAM_REQUEST_NEW_VERSION_CREATED'")->fetchColumn();
T::ok(!str_contains((string)$det, 'hemograma') && !str_contains((string)$det, $J), 'trilha sem texto clínico');
$who = $pdo->query("SELECT user_id, created_at FROM exam_audit_logs WHERE action = 'EXAM_REQUEST_SUPERSEDED'")->fetch();
T::ok((int)$who['user_id'] === $ownerA['user_id'] && $who['created_at'] !== null, 'trilha com autor e instante');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM exam_audit_logs WHERE clinic_id = {$cB}")->fetchColumn() === 0, 'nada da clínica A na trilha da B');

T::done();
