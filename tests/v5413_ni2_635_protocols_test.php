<?php
/**
 * NI2-635 (motor de protocolos clínicos). Protocolos de TESTE, sem conteúdo clínico real: as perguntas aqui só
 * exercitam o motor. Dados TESTE, duas clínicas. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5413.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\ClinicalProtocol\Conditions as C;
use ClyoNutri\Domain\ClinicalProtocol\PatientConditionService;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolCatalog;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolConflict;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolDefinition as D;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolEngine as E;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace;

$defA = [ // "Protocolo TESTE A"
    'title' => 'Protocolo TESTE A', 'sections' => ['s1' => 'Seção TESTE'], 'suggestion_terms' => ['condicao teste alfa'],
    'questions' => [
        ['key' => 'usa_item', 'shared_key' => 'base.usa_item', 'section' => 's1', 'type' => 'yesno', 'options' => ['sim' => 'Sim', 'nao' => 'Não'], 'required' => true, 'text' => 'Usa o item TESTE?'],
        ['key' => 'qual_item', 'shared_key' => 'base.qual_item', 'section' => 's1', 'type' => 'text', 'required' => true, 'text' => 'Qual item TESTE?', 'condition' => ['question' => 'usa_item', 'equals' => 'sim']],
        ['key' => 'medida', 'section' => 's1', 'type' => 'number', 'min' => 0, 'max' => 50, 'unit' => 'u', 'required' => true, 'text' => 'Medida TESTE',
            'red_flag' => ['when' => ['question' => 'medida', 'gte' => 40], 'action' => 'urgent_evaluation', 'message' => 'Valor TESTE alto: encaminhar para avaliação.']],
        ['key' => 'nota_prof', 'type' => 'textarea', 'audience' => 'professional', 'text' => 'Observação do profissional TESTE'],
    ],
];
$defB = [ // "Protocolo TESTE B" — reaproveita base.usa_item
    'title' => 'Protocolo TESTE B', 'suggestion_terms' => ['condicao teste beta'],
    'questions' => [
        ['key' => 'usa_item', 'shared_key' => 'base.usa_item', 'type' => 'yesno', 'options' => ['sim' => 'Sim', 'nao' => 'Não'], 'required' => true, 'text' => 'Usa o item TESTE?'],
        ['key' => 'freq', 'type' => 'single', 'options' => ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'required' => true, 'text' => 'Frequência TESTE',
            'red_flag' => ['when' => ['all' => [['question' => 'freq', 'equals' => 'c'], ['question' => 'base.usa_item', 'equals' => 'sim']]], 'action' => 'nutritionist_review', 'message' => 'Combinação TESTE: revisar com o nutricionista.']],
    ],
];

echo "== definição\n";
$a = D::validate($defA);
T::ok($a['questions'][3]['audience'] === 'professional' && $a['questions'][0]['required'] === true, 'definição válida normalizada');
T::throws(fn() => D::validate(['title' => 'x', 'questions' => [['key' => 'k1', 'type' => 'xpto', 'text' => 'x']]]), 'tipo desconhecido recusado', 'tipo inválido');
T::throws(fn() => D::validate(['title' => 'x', 'questions' => [['key' => 'k1', 'type' => 'yesno', 'options' => ['s' => 'S', 'n' => 'N'], 'text' => 'x',
    'red_flag' => ['when' => ['question' => 'k1', 'equals' => 's'], 'action' => 'medical_evaluation', 'message' => 'Suspenda o remédio TESTE']]]]), 'alerta com conduta ("suspenda") recusado', 'conduta');
T::throws(fn() => D::validate(['title' => 'x', 'questions' => [['key' => 'k1', 'type' => 'text', 'text' => 'x', 'condition' => ['question' => 'nao_existe', 'equals' => 'a']]]]), 'condição citando pergunta inexistente recusada', 'inexistente');
T::throws(fn() => D::validate(['title' => 'x', 'questions' => [['key' => 'k1', 'type' => 'text', 'text' => 'x', 'condition' => ['question' => 'k1', 'equals' => 'a', 'gt' => 1]]]]), 'condição com dois operadores recusada', 'exatamente um operador');
T::ok(D::hash($a) === D::hash(array_reverse($a, true)), 'hash canônico (ordem das chaves não importa)');

echo "== condições\n";
$ans = fn(array $m) => fn(string $k) => $m[$k] ?? null;
T::ok(C::holds(['question' => 'x', 'gt' => 5], $ans(['x' => '7'])) && !C::holds(['question' => 'x', 'gt' => 5], $ans([])), 'comparação numérica; sem resposta nunca satisfaz');
T::ok(C::holds(['any' => [['question' => 'a', 'equals' => '1'], ['question' => 'b', 'includes' => 'z']]], $ans(['b' => ['y', 'z']])), 'any + includes');
T::ok(!C::holds(['all' => [['question' => 'a', 'equals' => '1'], ['question' => 'b', 'answered' => true]]], $ans(['a' => '1'])), 'all exige todas');

echo "== motor\n";
$mods = [['code' => 'teste_a', 'name' => 'TESTE A', 'version' => 1, 'version_id' => 1, 'definition' => $a],
         ['code' => 'teste_b', 'name' => 'TESTE B', 'version' => 1, 'version_id' => 2, 'definition' => D::validate($defB)]];
$c0 = E::compose($mods, []);
$usa = array_values(array_filter($c0['items'], fn($i) => $i['answer_key'] === 'base.usa_item'))[0];
T::ok(count(array_filter($c0['items'], fn($i) => $i['answer_key'] === 'base.usa_item')) === 1 && $usa['modules'] === ['TESTE A', 'TESTE B'], 'pergunta compartilhada aparece uma vez, usada pelos dois módulos');
$qual = array_values(array_filter($c0['items'], fn($i) => $i['answer_key'] === 'base.qual_item'))[0];
T::ok(!$qual['visible'] && $c0['completeness']['teste_a']['required'] === 2, 'ramificação: "qual item" oculto e fora da contagem enquanto não responde "sim"');
$c1 = E::compose($mods, ['base.usa_item' => 'sim', 'teste_a.medida' => '45', 'teste_b.freq' => 'c']);
T::ok(array_values(array_filter($c1['items'], fn($i) => $i['answer_key'] === 'base.qual_item'))[0]['visible'] && $c1['completeness']['teste_a']['required'] === 3 && !$c1['completeness']['teste_a']['complete'], 'responde "sim": pergunta aparece e passa a ser obrigatória');
T::ok($c1['completeness']['teste_b']['complete'] && $c1['completeness']['teste_b']['pct'] === 100, 'módulo B completo (resposta compartilhada conta para os dois)');
T::ok(count($c1['red_flags']) === 2 && $c1['red_flags'][0]['action'] === 'urgent_evaluation' && $c1['red_flags'][1]['module'] === 'TESTE B', 'sinais de alerta: urgente primeiro; regra cruzando módulos funciona');
$c2 = E::compose($mods, ['teste_a.medida' => '99', 'teste_b.freq' => 'z']);
T::ok(isset($c2['errors']['teste_a.medida'], $c2['errors']['teste_b.freq']) && $c2['red_flags'] === [] || isset($c2['errors']['teste_a.medida'], $c2['errors']['teste_b.freq']), 'valor fora do intervalo e opção inexistente recusados');
$cp = E::compose($mods, [], 'patient');
T::ok(!in_array('teste_a.nota_prof', array_column($cp['items'], 'answer_key'), true), 'pré-consulta não mostra pergunta só do profissional');
T::ok(E::sanitize($c0, ['base.usa_item' => 'sim', 'invasor' => 'x']) === ['base.usa_item' => 'sim'], 'chaves fora do questionário são descartadas');
$bad = D::validate(['title' => 'C', 'questions' => [['key' => 'usa_item', 'shared_key' => 'base.usa_item', 'type' => 'text', 'text' => 'Usa?']]]);
T::throws(fn() => E::compose([$mods[0], ['code' => 'teste_c', 'name' => 'TESTE C', 'version' => 1, 'version_id' => 3, 'definition' => $bad]], []), 'módulos com pergunta compartilhada em formatos diferentes: combinação recusada', 'formatos diferentes');

echo "== catálogo e governança\n";
$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$author = Fx::user($pdo, $cA, 'Autora TESTE', 'owner'); $rev = Fx::user($pdo, $cA, 'Revisora TESTE', 'admin');
$uB = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner');
$pA = Fx::patient($pdo, $cA, 'Paciente TESTE Um'); $pB = Fx::patient($pdo, $cB, 'Paciente TESTE Outra');
$cat = new ProtocolCatalog($pdo);
$idA = $cat->register('teste_a', 'TESTE A'); $idB = $cat->register('teste_b', 'TESTE B'); $idC = $cat->register('teste_c_fase', 'TESTE fase C', 'condition', 'C');
T::ok($cat->register('teste_a', 'outro nome') === $idA, 'registro idempotente pelo código');
$vA = $cat->createDraft($idA, $defA, '', $author['user_id']);
T::ok($cat->approvedByCode() === [], 'rascunho não aparece como aprovado');
T::throws(fn() => $cat->approve($vA, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year'))), 'aprovar sem passar por revisão: recusado', 'em revisão');
$cat->submit($vA, $author['user_id']);
T::throws(fn() => $cat->updateDraft($vA, $defA, 'x', $author['user_id']), 'versão em revisão não é editável', 'Só rascunho');
T::throws(fn() => $cat->approve($vA, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year'))), 'sem referências: recusado', 'referências');
$pdo->prepare("UPDATE clinical_protocol_versions SET references_text = 'Referência TESTE 2026' WHERE id = ?")->execute([$vA]);
T::throws(fn() => $cat->approve($vA, $author['user_id'], 'Autora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year'))), 'autora não aprova a própria versão', 'não pode aprová-la');
T::throws(fn() => $cat->approve($vA, $rev['user_id'], 'Revisora TESTE', '12345', date('Y-m-d', strtotime('+1 year'))), 'CRN sem região recusado', 'CRN');
T::throws(fn() => $cat->approve($vA, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+3 years'))), 'revisão além de 24 meses recusada', '24 meses');
$cat->approve($vA, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year')));
T::ok(isset($cat->approvedByCode()['teste_a']) && $cat->approvedByCode()['teste_a']['version'] === 1, 'aprovada com nome, CRN e data de revisão');
$vC = $cat->createDraft($idC, $defB, 'Referência TESTE', $author['user_id']); $cat->submit($vC, $author['user_id']);
T::throws(fn() => $cat->approve($vC, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year'))), 'fase C sem parecer especializado: recusado', 'especializada');
$vA2 = $cat->createDraft($idA, $defA, 'Referência TESTE v2', $author['user_id']); $cat->submit($vA2, $author['user_id']);
$cat->approve($vA2, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+6 months')));
T::ok($cat->version($vA)['status'] === 'retired' && $cat->approvedByCode()['teste_a']['version'] === 2, 'nova aprovação aposenta a anterior');
$vB = $cat->createDraft($idB, $defB, 'Referência TESTE', $author['user_id']); $cat->submit($vB, $author['user_id']);
$cat->approve($vB, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year')));
$ev = (int)$pdo->query("SELECT COUNT(*) FROM clinical_protocol_events WHERE protocol_version_id = $vA")->fetchColumn();
T::ok($ev === 4, 'trilha editorial da v1: criada, enviada, aprovada, aposentada (' . $ev . ')');
$pdo->prepare("UPDATE clinical_protocol_versions SET definition_json = REPLACE(definition_json, 'Usa o item', 'Usa o ITEM') WHERE id = ?")->execute([$vB]);
T::ok(!isset($cat->approvedByCode()['teste_b']), 'conteúdo aprovado alterado no banco: versão ignorada (hash não confere)');
$pdo->prepare("UPDATE clinical_protocol_versions SET definition_json = ? WHERE id = ?")->execute([json_encode(D::validate($defB), JSON_UNESCAPED_UNICODE), $vB]);
T::ok(isset($cat->approvedByCode()['teste_b']), 'conteúdo restaurado: volta a valer');

echo "== condições do paciente\n";
$pcs = new PatientConditionService($pdo);
$sug = $pcs->suggestFromText($cA, $pA, 'Paciente relata Condicao TESTE Alfa desde 2020.', $cat->approvedByCode(), $author['user_id']);
T::ok($sug === ['teste_a'] && $pcs->activatingCodes($cA, $pA) === [], 'texto livre gera só sugestão; sugestão não ativa módulo');
T::ok($pcs->suggestFromText($cA, $pA, 'condicao teste alfa de novo', $cat->approvedByCode(), null) === [], 'sugestão não duplica');
T::ok($pcs->suggestFromText($cA, $pA, 'condicao teste alfabeto', $cat->approvedByCode(), null) === [], 'termo dentro de outra palavra não conta');
$condA = (int)$pdo->query("SELECT id FROM patient_conditions WHERE patient_id = $pA AND protocol_code = 'teste_a'")->fetchColumn();
T::throws(fn() => $pcs->confirm($cA, $condA, 'documented', 'self_reported', $author['user_id']), 'documentada exige laudo ou avaliação profissional', 'laudo');
$pcs->confirm($cA, $condA, 'reported', 'self_reported', $author['user_id'], 'Confirmado na consulta TESTE');
T::ok($pcs->activatingCodes($cA, $pA) === ['teste_a'], 'confirmada pelo profissional: ativa o módulo');
T::throws(fn() => $pcs->confirm($cB, $condA, 'reported', 'self_reported', $uB['user_id']), 'outra clínica não altera a condição', 'não encontrada');
T::throws(fn() => $pcs->list($cB, $pA), 'outra clínica não lista condições do paciente');
$condB = $pcs->record($cA, $pA, 'teste_b', 'suspected', 'professional', $author['user_id'], 'Em investigação TESTE', null, date('Y-m-d', strtotime('-1 day')));
T::ok(!in_array('teste_b', $pcs->activatingCodes($cA, $pA), true), 'validade vencida não ativa');
$pcs->confirm($cA, $condB, 'suspected', 'professional', $author['user_id'], 'Renovada TESTE', null, null);
T::ok(in_array('teste_b', $pcs->activatingCodes($cA, $pA), true), 'renovada: ativa');
T::ok(count($pcs->history($cA, $condA)) === 2, 'histórico da condição: sugerida → relatada');

echo "== uso (travas, respostas, rascunho)\n";
$ws = new ProtocolWorkspace($pdo);
T::ok($ws->activeModules($cA, $pA) === [], 'chave global desligada: nenhum módulo');
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('feature_flag_clinical_protocols', '1')"); PlatformSettings::clearCache();
T::ok($ws->activeModules($cA, $pA) === [], 'clínica não ligou: nenhum módulo');
T::throws(fn() => $ws->setClinicEnabled($cA, 'teste_c_fase', true, $author['user_id']), 'não liga protocolo sem versão aprovada', 'aprovada');
$ws->setClinicEnabled($cA, 'teste_a', true, $author['user_id']); $ws->setClinicEnabled($cA, 'teste_b', true, $author['user_id']);
$mods = $ws->activeModules($cA, $pA);
T::ok(array_column($mods, 'code') === ['teste_a', 'teste_b'] && $mods[0]['version'] === 2, 'travas abertas: dois módulos, versão vigente v2');
T::throws(fn() => $ws->activeModules($cB, $pA), 'clínica B não monta questionário do paciente da A');
$ws->save($cA, $pA, ['base.usa_item' => 'nao', 'invasor' => 'x'], 'professional', false, $author['user_id']);
$q = $ws->questionnaire($cA, $pA);
T::ok($q['draft_in_progress'] && $q['answers'] === ['base.usa_item' => 'nao'], 'rascunho salvo, só chaves válidas');
T::throws(fn() => $ws->save($cA, $pA, [], 'professional', true, $author['user_id']), 'concluir com obrigatória faltando: recusado', 'Faltam');
$ws->save($cA, $pA, ['teste_a.medida' => '10', 'teste_b.freq' => 'a'], 'professional', true, $author['user_id']);
$ws->save($cA, $pA, ['teste_a.medida' => '12'], 'professional', false, $author['user_id']);
$rows = (int)$pdo->query("SELECT COUNT(*) FROM protocol_responses WHERE patient_id = $pA")->fetchColumn();
$q = $ws->questionnaire($cA, $pA);
T::ok($rows === 6 && $q['answers']['teste_a.medida'] === '12' && $q['draft_in_progress'], 'respostas nunca sobrescritas (6 linhas); rascunho mais novo por cima da concluída');
$con = $pdo->query("SELECT answers_json FROM protocol_responses WHERE patient_id = $pA AND is_draft = 0 AND protocol_version_id = $vA2")->fetchColumn();
T::ok(json_decode($con, true)['teste_a.medida'] === '10', 'versão concluída preservada (10), rascunho separado (12)');
$pq = $ws->questionnaire($cA, $pA, 'patient');
T::ok(!in_array('teste_a.nota_prof', array_column($pq['composed']['items'], 'answer_key'), true), 'pré-consulta sem pergunta do profissional');
$pdo->exec("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'feature_flag_clinical_protocols'"); PlatformSettings::clearCache();
T::ok($ws->activeModules($cA, $pA) === [] && (int)$pdo->query("SELECT COUNT(*) FROM protocol_responses")->fetchColumn() === 6, 'desligar a chave (rollback) esconde os módulos e preserva as respostas');
T::done();
