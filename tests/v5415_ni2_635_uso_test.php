<?php
/**
 * v54.15 — NI2-655 (LGPD dos dados de protocolo) e NI2-639/640/642/652 (bloco da anamnese adaptativa).
 * Protocolos de TESTE, sem conteúdo clínico real. Dados TESTE, duas clínicas. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5415.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\ClinicalProtocolController as CPC;
use ClyoNutri\Core\Settings\PlatformSettings;
use ClyoNutri\Domain\ClinicalProtocol\PatientConditionService;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolCatalog;
use ClyoNutri\Domain\ClinicalProtocol\ProtocolWorkspace;
use ClyoNutri\Domain\Privacy\LgpdService;

if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}
$def = [
    'title' => 'Protocolo TESTE A', 'sections' => ['s1' => 'Seção TESTE'], 'suggestion_terms' => ['condicao teste alfa'],
    'questions' => [
        ['key' => 'usa_item', 'section' => 's1', 'type' => 'yesno', 'options' => ['sim' => 'Sim', 'nao' => 'Não'], 'required' => true, 'text' => 'Usa o item TESTE?'],
        ['key' => 'medida', 'section' => 's1', 'type' => 'number', 'min' => 0, 'max' => 50, 'required' => true, 'text' => 'Medida TESTE',
            'red_flag' => ['when' => ['question' => 'medida', 'gte' => 40], 'action' => 'urgent_evaluation', 'message' => 'Valor TESTE alto: encaminhar para avaliação.']],
        ['key' => 'obs', 'type' => 'textarea', 'text' => 'Observação TESTE'],
    ],
];
$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$author = Fx::user($pdo, $cA, 'Autora TESTE', 'owner'); $rev = Fx::user($pdo, $cA, 'Revisora TESTE', 'admin');
$uB = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner');
$pA = Fx::patient($pdo, $cA, 'Paciente TESTE Um');
$cat = new ProtocolCatalog($pdo);
$id = $cat->register('teste_a', 'TESTE A');
$v = $cat->createDraft($id, $def, 'Referência TESTE', $author['user_id']); $cat->submit($v, $author['user_id']);
$cat->approve($v, $rev['user_id'], 'Revisora TESTE', 'CRN-3 12345', date('Y-m-d', strtotime('+1 year')));
$ws = new ProtocolWorkspace($pdo);
$pcs = new PatientConditionService($pdo);

echo "== bloco da anamnese com a chave desligada\n";
Fx::act($author);
T::ok(CPC::renderSection($pdo, $pA) === '', 'chave global desligada: nada aparece na anamnese');
$pdo->exec("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('feature_flag_clinical_protocols', '1')"); PlatformSettings::clearCache();
T::ok(CPC::renderSection($pdo, $pA) === '', 'chave ligada mas clínica sem protocolo e paciente sem condição: nada aparece');

echo "== bloco ligado\n";
$ws->setClinicEnabled($cA, 'teste_a', true, $author['user_id']);
$h = CPC::renderSection($pdo, $pA);
T::ok(str_contains($h, 'id="anamnese-adaptativa"') && str_contains($h, 'data-testid="adaptive-no-conditions"') && !str_contains($h, 'data-testid="adaptive-form"'), 'clínica ligou: registrar condição disponível, nenhum módulo sem condição');
$pcs->suggestFromText($cA, $pA, 'Relata condicao teste alfa.', $cat->approvedByCode(), $author['user_id']);
$h = CPC::renderSection($pdo, $pA);
T::ok(str_contains($h, 'data-status="suggested"') && str_contains($h, 'Sugestão a confirmar') && !str_contains($h, 'data-testid="adaptive-form"'), 'sugestão aparece para confirmar e não abre módulo');
$cid = (int)$pdo->query("SELECT id FROM patient_conditions WHERE patient_id = $pA")->fetchColumn();
$pcs->confirm($cA, $cid, 'reported', 'self_reported', $author['user_id'], 'Confirmado TESTE');
$ws->save($cA, $pA, ['teste_a.usa_item' => 'sim', 'teste_a.medida' => '45', 'teste_a.obs' => 'Texto livre TESTE <script>x</script>'], 'professional', false, $author['user_id']);
$h = CPC::renderSection($pdo, $pA);
T::ok(str_contains($h, 'data-testid="adaptive-form"') && str_contains($h, 'name="answers[teste_a.medida]"') && str_contains($h, 'value="45"'), 'condição confirmada: módulo aparece com o rascunho');
T::ok(str_contains($h, 'data-testid="adaptive-red-flags"') && str_contains($h, 'role="alert"') && str_contains($h, htmlspecialchars(\ClyoNutri\Domain\ClinicalProtocol\ProtocolDefinition::RED_FLAG_ACTIONS['urgent_evaluation'], ENT_QUOTES)), 'sinal de alerta exibido como alerta');
T::ok(str_contains($h, 'data-testid="adaptive-draft"') && str_contains($h, '2 de 2 obrigatórias'), 'indicador de rascunho; progresso calculado');
T::ok(!str_contains($h, '<script>x</script>') && str_contains($h, '&lt;script&gt;'), 'texto livre escapado');
$aud = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'PROTOCOL_DATA_VIEWED'")->fetchColumn();
T::ok($aud >= 3, 'leitura dos dados de protocolo auditada (' . $aud . ')');
$audTxt = (string)$pdo->query("SELECT COALESCE(details, '') FROM audit_logs WHERE action = 'PROTOCOL_DATA_VIEWED' ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok(!str_contains($audTxt, 'Texto livre TESTE') && !str_contains($audTxt, '45'), 'auditoria sem conteúdo clínico');
$_SESSION['cp_answers'][$cA . ':' . $pA] = ['teste_a.medida' => '7']; // v54.18: guardado por clínica e paciente
T::ok(str_contains(CPC::renderSection($pdo, $pA), 'value="7"') && !isset($_SESSION['cp_answers'][$cA . ':' . $pA]), 'conclusão recusada devolve o que foi digitado (uma vez)');

echo "== outra clínica\n";
Fx::act($uB);
T::ok(CPC::renderSection($pdo, $pA) === '', 'clínica B não vê o bloco do paciente da clínica A');
Fx::act($author);

echo "== NI2-655 LGPD\n";
$exp = (new LgpdService($pdo))->exportPatientData($pA);
T::ok(count($exp['patient_conditions'] ?? []) === 1 && $exp['patient_conditions'][0]['protocol_code'] === 'teste_a', 'exportação inclui as condições');
T::ok(count($exp['protocol_responses'] ?? []) === 1 && str_contains((string)$exp['protocol_responses'][0]['answers_json'], 'Texto livre TESTE'), 'exportação inclui as respostas dos módulos');
T::ok(count($exp['patient_condition_events'] ?? []) === 2, 'exportação inclui o histórico da condição');
Fx::act($uB);
T::ok((new LgpdService($pdo))->exportPatientData($pA) === null, 'clínica B não exporta paciente da A');
Fx::act($author);
(new LgpdService($pdo))->anonymizePatient($pA);
$ev = $pdo->query("SELECT note FROM patient_condition_events e JOIN patient_conditions c ON c.id = e.condition_id WHERE c.patient_id = $pA")->fetchAll(PDO::FETCH_COLUMN);
$evid = $pdo->query("SELECT evidence_text FROM patient_conditions WHERE patient_id = $pA")->fetchColumn();
T::ok(array_filter($ev) === [] && $evid === null, 'anonimização apaga notas e evidências em texto livre das condições');
T::done();
