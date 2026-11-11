<?php
/**
 * v54.16 — pendências da NI2-635: detecção com negação/terceira pessoa (NI2-640), dispensa de módulo (NI2-642),
 * comparação e conflito de gravação (NI2-652), auditoria e métricas sem dado de saúde (NI2-655).
 * Protocolos de TESTE, sem conteúdo clínico real. Dados TESTE. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5416.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\ClinicalProtocolController as CPC;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\Analytics\AdherenceAnalytics;
use ClyoNutri\Domain\ClinicalProtocol\PatientConditionService;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolCatalog;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolSaveConflict;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace;
use ClyoNutri\Domain\ClinicalProtocol\TermDetector as TD;

$_SESSION = [];
echo "== NI2-640 regras de detecção (termos TESTE)\n";
$terms = ['condicao alfa', 'doença beta'];
$m = fn(string $t) => TD::detect($t, $terms)['match'];
$cases = [
    ['Tenho condição alfa.', 'stated'], ['Paciente relata Condicao Alfa desde 2020', 'stated'], ['Não tenho doença beta', null],
    ['Nega condicao alfa. Relata doença beta.', 'stated'], ['Minha mãe tem condicao alfa', null], ['Histórico familiar de condicao alfa', null],
    ['Minha mãe, que tem condicao alfa, mora comigo', null], ['Acho que tenho condicao alfa', 'uncertain'], ['Não sei se tenho doenca beta', 'uncertain'],
    ['Condicao alfa descartada pelo médico', null], ['Já tive condicao alfa', 'past'], ['Paciente não fuma e tem condicao alfa', 'stated'],
    ['Não tenho condicao alfa e doença beta', null], ['condicao alfabética', null], ['Sem condicao alfa', null],
];
foreach ($cases as [$text, $want]) {
    $r = $m($text);
    T::ok(($r['certainty'] ?? null) === $want, '"' . $text . '" → ' . ($want ?? 'ignorado'));
}
$r = TD::detect('Minha mãe tem condicao alfa', $terms);
T::ok($r['skipped'][0]['reason'] === 'outra pessoa ou histórico familiar' && $r['skipped'][0]['snippet'] === 'Minha mãe tem condicao alfa', 'o ignorado vem com motivo e trecho');
T::ok(str_starts_with(TD::explain($m('Acho que tenho condicao alfa')), 'Sugerido porque o texto diz: "Acho que tenho condicao alfa".') && str_contains(TD::explain($m('Acho que tenho condicao alfa')), 'dúvida'), 'justificativa mostra o trecho e a dúvida');

$def = ['title' => 'Protocolo TESTE A', 'sections' => ['s1' => 'Seção TESTE'], 'suggestion_terms' => ['condicao alfa'],
    'questions' => [
        ['key' => 'usa_item', 'section' => 's1', 'type' => 'single', 'options' => ['a' => 'Opção TESTE A', 'b' => 'Opção TESTE B'], 'required' => true, 'text' => 'Escolha TESTE'],
        ['key' => 'medida', 'section' => 's1', 'type' => 'number', 'min' => 0, 'max' => 50, 'required' => true, 'text' => 'Medida TESTE'],
    ]];
$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$author = Fx::user($pdo, $cA, 'Autora TESTE', 'owner'); $rev = Fx::user($pdo, $cA, 'Revisora TESTE', 'admin');
$colega = Fx::user($pdo, $cA, 'Colega TESTE', 'nutritionist');
$pA = Fx::patient($pdo, $cA, 'Paciente TESTE Um');
$cat = new ProtocolCatalog($pdo);
$id = $cat->register('teste_a', 'TESTE A');
$v = $cat->createDraft($id, $def, 'Referência TESTE', $author['user_id']); $cat->submit($v, $author['user_id']);
$cat->approve($v, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year')));
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('feature_flag_clinical_protocols', '1')"); PlatformSettings::clearCache();
$ws = new ProtocolWorkspace($pdo); $pcs = new PatientConditionService($pdo);
$ws->setClinicEnabled($cA, 'teste_a', true, $author['user_id']);
Fx::act($author);

echo "== sugestão no serviço\n";
$r = $pcs->suggestFromTextDetailed($cA, $pA, 'Minha mãe tem condicao alfa. Nega doença beta.', $cat->approvedByCode(), $author['user_id']);
T::ok($r['made'] === [] && count($r['skipped']) === 1 && $r['skipped'][0]['code'] === 'teste_a', 'texto só com familiar: nenhuma sugestão, motivo devolvido');
$r = $pcs->suggestFromTextDetailed($cA, $pA, 'Acho que tenho condição alfa.', $cat->approvedByCode(), $author['user_id']);
$ev = (string)$pdo->query("SELECT evidence_text FROM patient_conditions WHERE patient_id = $pA")->fetchColumn();
T::ok($r['made'] === ['teste_a'] && str_contains($ev, 'Sugerido porque o texto diz') && str_contains($ev, 'dúvida'), 'sugestão grava a justificativa com o trecho');
$cid = (int)$pdo->query("SELECT id FROM patient_conditions WHERE patient_id = $pA")->fetchColumn();
T::ok($pcs->activatingCodes($cA, $pA) === [], 'sugestão continua sem ativar módulo');
$pcs->confirm($cA, $cid, 'reported', 'self_reported', $author['user_id'], 'Confirmado TESTE');

echo "== NI2-642 dispensar módulo\n";
T::ok(count($ws->activeModules($cA, $pA)) === 1, 'condição confirmada: módulo ativo');
T::throws(fn() => $pcs->dismissModule($cA, $pA, 'teste_a', $author['user_id'], 'x'), 'dispensa sem motivo recusada', 'motivo');
$pcs->dismissModule($cA, $pA, 'teste_a', $author['user_id'], 'Fora do escopo desta consulta TESTE');
T::ok($ws->activeModules($cA, $pA) === [] && $pcs->list($cA, $pA)[0]['status'] === 'reported', 'dispensado: módulo some, condição continua registrada');
T::ok(($pcs->dismissedModules($cA, $pA)['teste_a']['note'] ?? '') === 'Fora do escopo desta consulta TESTE', 'motivo guardado');
T::throws(fn() => $pcs->dismissModule($cA, $pA, 'teste_a', $author['user_id'], 'De novo TESTE'), 'dispensar duas vezes recusado', 'já está dispensado');
$h = CPC::renderSection($pdo, $pA);
T::ok(str_contains($h, 'data-testid="adaptive-dismissed"') && str_contains($h, 'Fora do escopo desta consulta TESTE') && !str_contains($h, 'data-testid="adaptive-form"'), 'tela mostra o dispensado com motivo e sem formulário');
$pcs->restoreModule($cA, $pA, 'teste_a', $author['user_id']);
T::ok(count($ws->activeModules($cA, $pA)) === 1 && $pcs->dismissedModules($cA, $pA) === [], 'reativado');
T::ok(count(array_filter($pcs->history($cA, $cid), fn($e) => str_starts_with($e['to_status'], 'module_'))) === 2, 'dispensa e reativação ficam no histórico da condição');

echo "== NI2-652 conflito e comparação\n";
$rev0 = $ws->questionnaire($cA, $pA)['revision'];
$ws->save($cA, $pA, ['teste_a.usa_item' => 'a', 'teste_a.medida' => '10'], 'professional', true, $author['user_id'], null, $rev0);
$rev1 = $ws->questionnaire($cA, $pA)['revision'];
T::ok($rev1 > $rev0, 'gravar muda a marca de versão');
$ws->save($cA, $pA, ['teste_a.medida' => '11'], 'professional', false, $colega['user_id'], null, $rev1); // colega grava primeiro
$n = (int)$pdo->query('SELECT COUNT(*) FROM protocol_responses')->fetchColumn();
T::throws(fn() => $ws->save($cA, $pA, ['teste_a.medida' => '12'], 'professional', false, $author['user_id'], null, $rev1), 'gravação com tela desatualizada: conflito explícito', 'Outra pessoa salvou');
T::ok((int)$pdo->query('SELECT COUNT(*) FROM protocol_responses')->fetchColumn() === $n && $ws->questionnaire($cA, $pA)['answers']['teste_a.medida'] === '11', 'nada gravado no conflito; vale a resposta da colega');
T::ok((new \ReflectionClass(ProtocolSaveConflict::class))->isSubclassOf(\DomainException::class), 'conflito é erro de domínio (vira aviso na tela)');
T::ok($ws->compare($cA, $pA) === [], 'uma conclusão só: nada a comparar');
$ws->save($cA, $pA, ['teste_a.usa_item' => 'b', 'teste_a.medida' => '11'], 'professional', true, $author['user_id'], null, $ws->revision($cA, $pA));
$cmp = $ws->compare($cA, $pA);
$ch = array_column($cmp[0]['changes'] ?? [], null, 'key');
T::ok(count($cmp) === 1 && $ch['teste_a.usa_item']['before'] === 'Opção TESTE A' && $ch['teste_a.usa_item']['after'] === 'Opção TESTE B' && $ch['teste_a.medida']['after'] === '11', 'comparação entre conclusões, com rótulos das opções');
$_SESSION['cp_conflict'][$cA . ':' . $pA] = ['teste_a.medida' => '12']; // v54.18: guardado por clínica e paciente
$h = CPC::renderSection($pdo, $pA);
T::ok(str_contains($h, 'data-testid="adaptive-conflict"') && str_contains($h, 'Medida TESTE: 12') && str_contains($h, 'name="base_revision"') && str_contains($h, 'data-testid="adaptive-compare"'), 'tela mostra o que não foi gravado, a marca de versão e a comparação');

echo "== NI2-655 auditoria e métricas\n";
$det = (string)$pdo->query("SELECT details FROM audit_logs WHERE action = 'PROTOCOL_DATA_VIEWED' ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok($det !== '' && !str_contains($det, 'teste_a') && !str_contains($det, 'TESTE A') && !str_contains($det, '11'), 'auditoria de leitura sem código do protocolo nem respostas');
T::ok(AdherenceAnalytics::METRICS === ['plan_viewed', 'checkin_submitted', 'hydration_logged'], 'métricas de uso: lista fechada, sem evento de protocolo');
T::ok((int)$pdo->query('SELECT COUNT(*) FROM analytics_daily_metrics')->fetchColumn() === 0, 'nenhuma métrica gravada pelo fluxo de protocolos');
$view = (string)file_get_contents(dirname(__DIR__) . '/views/patients/_adaptive_anamnesis.php');
T::ok(!preg_match('/gtag|analytics|dataLayer|fetch\(/i', $view), 'bloco não envia nada para fora (sem script de métricas)');
T::done();
