<?php
/** NI2-603, 604, 605 e 611 (v54.10): regras de domínio. Dados TESTE. Fora do zip. */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5410.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Anamnesis\AnamnesisVersions as AV;
use ClyoNutri\Domain\Clinical\AllergenSafetyValidator as V;
use ClyoNutri\Core\Http\SafeReturnPath as SRP;

$c = Fx::clinic($pdo, 'Clínica TESTE'); $o = Fx::user($pdo, $c, 'Nutri TESTE', 'owner'); Fx::act($o);
$ins = $pdo->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, allergies, allergy_assessment, is_draft, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$an = new AnamnesisService($pdo); $val = new V($pdo);

echo "== NI2-605: v1 concluída (amendoim) + v2 rascunho vazio\n";
$p1 = Fx::patient($pdo, $c, 'Paciente TESTE 1');
$ins->execute([$c, $p1, $o['professional_id'], 1, 'amendoim', 'recorded', 0, '2026-10-20 10:00:00']);
$ins->execute([$c, $p1, $o['professional_id'], 2, null, 'not_assessed', 1, '2026-11-02 14:30:00']);
$s = $an->getClinicalSummaryForPrescription($p1);
T::ok($s['source']['version'] === 1 && !$s['is_draft'] && !$s['allergy_pending'] && $s['allergy_state'] === 'recorded', 'resumo usa a v1 concluída, sem "pendente"');
T::ok(($s['draft_in_progress']['version'] ?? 0) === 2 && $s['draft_in_progress']['label'] === 'Alteração em andamento (v2, salva em 02/11 14:30)', 'rascunho aparece como alteração em andamento: ' . ($s['draft_in_progress']['label'] ?? '-'));
$st = $val->evaluatePatientAllergyState($p1);
T::ok($st['state'] === V::STATE_CONFIRMED && in_array('amendoim', $st['allergen_groups'], true), 'trava de alérgenos mantém amendoim da v1 (antes o rascunho vazio apagava): ' . implode(',', $st['allergen_groups']));
T::ok(($an->getEffectiveAnamnesis($p1)['version'] ?? 0) == 1 && ($an->getLatestAnamnesis($p1)['version'] ?? 0) == 2, 'perfil mostra a v1; a edição continua no rascunho v2');

echo "== NI2-605: v1 nega alergias + v2 rascunho com camarão\n";
$p2 = Fx::patient($pdo, $c, 'Paciente TESTE 2');
$ins->execute([$c, $p2, $o['professional_id'], 1, null, 'denied', 0, '2026-10-20 10:00:00']);
$ins->execute([$c, $p2, $o['professional_id'], 2, 'camarão', 'recorded', 1, '2026-11-02 15:00:00']);
$st = $val->evaluatePatientAllergyState($p2);
T::ok($st['state'] === V::STATE_CONFIRMED && $st['allergen_groups'] !== [], 'alergia digitada no rascunho já vale para a trava (nunca menos proteção): ' . implode(',', $st['allergen_groups']));
$s = $an->getClinicalSummaryForPrescription($p2);
T::ok($s['allergy_state'] === 'recorded' && str_contains((string)$s['allergies'], 'camarão (alteração em andamento v2)') && $s['source']['version'] === 1, 'resumo: v1 é a origem e a alergia do rascunho aparece marcada');

echo "== NI2-605: só rascunho\n";
$p3 = Fx::patient($pdo, $c, 'Paciente TESTE 3');
$ins->execute([$c, $p3, $o['professional_id'], 1, null, 'not_assessed', 1, '2026-11-02 15:00:00']);
$s = $an->getClinicalSummaryForPrescription($p3);
T::ok($s['allergy_pending'] && $s['is_draft'] && $s['draft_in_progress'] === null, 'só rascunho: continua "pendente", como antes');

echo "== NI2-605: v2 concluída depois da v1\n";
$p4 = Fx::patient($pdo, $c, 'Paciente TESTE 4');
$ins->execute([$c, $p4, $o['professional_id'], 1, 'amendoim', 'recorded', 0, '2026-10-20 10:00:00']);
$ins->execute([$c, $p4, $o['professional_id'], 2, 'leite', 'recorded', 0, '2026-11-01 10:00:00']);
$s = $an->getClinicalSummaryForPrescription($p4);
T::ok($s['source']['version'] === 2 && $s['allergies'] === 'leite' && $s['draft_in_progress'] === null, 'a concluída mais nova vale');
$m = AV::mergeForSafety(['concluded' => ['allergies' => 'amendoim', 'allergy_assessment' => 'recorded'], 'draft' => ['allergies' => 'nega alergias']]);
T::ok($m['row']['allergies'] === 'amendoim' && !$m['draft_added'], 'rascunho que "nega" não apaga alergia concluída');

echo "== NI2-604: caminho de volta\n";
T::ok(SRP::clean('/agenda?date=2026-11-03&novo=1') === '/agenda?date=2026-11-03&novo=1', 'aceita caminho interno com parâmetros');
foreach (['//evil.com/x', 'https://evil.com', '/\\evil', '/login', '/logout', '/api/v1/x', '/sessao/renovar', "/x\r\nSet-Cookie: a", 'javascript:alert(1)', ''] as $bad) {
    T::ok(SRP::clean($bad) === null, 'recusa ' . json_encode($bad));
}

echo "== NI2-611: textos de assinatura\n";
$files = ['src/Application/Controllers/DashboardController.php', 'src/Domain/Helpdesk/HelpArticlesV21.php'];
$bad = 0;
foreach ($files as $f) {
    $txt = file_get_contents(dirname(__DIR__) . '/' . $f);
    $bad += preg_match_all('/concluída e assinada|será assinada|assinar (o )?prontuário|Assinar e gravar|assinado pelo profissional|Assinatura digital \(SHA|Assinatura raiz|a anamnese é assinada/u', $txt);
}
T::ok($bad === 0, 'nenhum texto diz que anamnese, evolução ou prontuário são "assinados" (' . $bad . ' ocorrências)');
T::done();
