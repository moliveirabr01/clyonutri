<?php
/**
 * NI2-233 (homologação do prontuário): regressões dos defeitos achados na homologação. Dados TESTE, duas clínicas.
 *  (a) autoria: usuário sem cadastro profissional (CRN) não pode gravar no prontuário com o CRN de outra pessoa;
 *  (c) retificação de versão já superada (formulário velho ou duas abas) não pode bifurcar o histórico.
 * Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5423-ni2-233.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Application\Controllers\DashboardController;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;

$cA = Fx::clinic($pdo, 'Clínica TESTE A'); $cB = Fx::clinic($pdo, 'Clínica TESTE B');
$a = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner', '23301');
$a2 = Fx::user($pdo, $cA, 'Nutri TESTE A2', 'nutritionist', '23302');
$adm = Fx::user($pdo, $cA, 'Gestora TESTE sem CRN', 'admin', null);
$b = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner', '23303');
$p = Fx::patient($pdo, $cA, 'Paciente TESTE Homologação');

echo "== (a) autoria das gravações clínicas = CRN do próprio usuário\n";
$ctrl = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
$author = new ReflectionMethod(DashboardController::class, 'clinicalAuthorProfessionalId');
$author->setAccessible(true);
Fx::act($a);
T::ok($author->invoke($ctrl) === $a['professional_id'], 'dona A grava com o próprio CRN');
Fx::act($a2);
T::ok($author->invoke($ctrl) === $a2['professional_id'], 'colega A2 grava com o próprio CRN (não o da dona)');
Fx::act($adm);
T::ok($author->invoke($ctrl) === null, 'gestora sem CRN: sem autoria possível (antes caía no CRN do primeiro profissional da clínica)');
$legacy = new ReflectionMethod(DashboardController::class, 'getActiveProfessionalId');
$legacy->setAccessible(true);
T::ok($legacy->invoke($ctrl) !== null && $legacy->invoke($ctrl) !== 0, 'documenta o risco: o resolvedor antigo devolve um CRN de terceiro para a gestora (' . $legacy->invoke($ctrl) . ')');
// B com sessão apontando para a clínica A (sessão adulterada): não é membro da A, não há autoria
\ClyoNutri\Core\Auth\TenantContext::set($cA, ['id' => $b['user_id'], 'role' => 'nutritionist']);
T::ok($author->invoke($ctrl) === null, 'B com contexto da clínica A: não é membro ativo da A, sem autoria');
$src = (string)file_get_contents(dirname(__DIR__) . '/src/Application/Controllers/DashboardController.php');
foreach (['saveAnthropometry', 'savePatientMedicalRecord', 'revisePatientMedicalRecord'] as $fn) {
    preg_match('/function ' . $fn . '\(.*?\n    }\n/s', $src, $m);
    T::ok(isset($m[0]) && str_contains($m[0], 'clinicalAuthorProfessionalId()') && !str_contains($m[0], 'getActiveProfessionalId()'), "{$fn} usa o resolvedor estrito de autoria");
}
$pdo->prepare("UPDATE clinic_members SET status = 'inactive' WHERE user_id = ?")->execute([$a2['user_id']]);
Fx::act($a2);
T::ok($author->invoke($ctrl) === null, 'colega desligada (vínculo inativo) não grava mais com o CRN dela nesta clínica');
$pdo->prepare("UPDATE clinic_members SET status = 'active' WHERE user_id = ?")->execute([$a2['user_id']]);

echo "== (c) retificação de versão já superada\n";
Fx::act($a);
$svc = new ClinicalRecordService($pdo);
$r1 = $svc->addClinicalObservation($p, $a['professional_id'], 'Evolução TESTE', 'Texto TESTE original', 'evolucao');
$id1 = (int)($r1['id'] ?? $r1['record_id'] ?? 0);
T::ok($id1 > 0, 'registro TESTE criado (id ' . $id1 . ')');
$r2 = $svc->reviseRecord($id1, $p, $a['professional_id'], 'Correção TESTE de digitação', ['type' => 'revised_note', 'text' => 'Texto TESTE corrigido']);
$id2 = (int)($r2['id'] ?? $r2['record_id'] ?? 0);
T::ok($id2 > $id1, 'primeira retificação gera nova versão (id ' . $id2 . ')');
$before = (int)$pdo->query("SELECT COUNT(*) FROM clinical_records WHERE patient_id = $p")->fetchColumn();
T::throws(fn() => $svc->reviseRecord($id1, $p, $a2['professional_id'], 'Outra correção TESTE em aba antiga', ['type' => 'revised_note', 'text' => 'Texto TESTE divergente']),
    'retificar de novo a versão já superada é recusado', 'já foi retificado');
T::ok((int)$pdo->query("SELECT COUNT(*) FROM clinical_records WHERE patient_id = $p")->fetchColumn() === $before, 'nada gravado na tentativa recusada');
$live = (int)$pdo->query("SELECT COUNT(*) FROM clinical_records WHERE patient_id = $p AND is_retracted = 0")->fetchColumn();
$forks = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT supersedes_id FROM clinical_records WHERE patient_id = $p AND supersedes_id IS NOT NULL GROUP BY supersedes_id HAVING COUNT(*) > 1) x")->fetchColumn();
T::ok($live === 1 && $forks === 0, "histórico linear: 1 versão vigente, nenhuma versão superada duas vezes (vigentes {$live}, bifurcações {$forks})");
$r3 = $svc->reviseRecord($id2, $p, $a2['professional_id'], 'Complemento TESTE da colega', ['type' => 'revised_note', 'text' => 'Texto TESTE complementado']);
T::ok((int)($r3['id'] ?? $r3['record_id'] ?? 0) > $id2, 'retificar a versão vigente continua funcionando (autoria A2)');
T::ok($svc->verifyIntegrity($p)['is_valid'] === true, 'cadeia de integridade (SHA-256) continua válida');

echo "== (d) elo da cadeia na linha do tempo e na exportação\n";
Fx::act($a);
$tl = $svc->getPatientTimeline($p);
$byId = []; foreach ($tl as $row) $byId[(int)$row['id']] = $row;
$okLinks = count($tl) >= 3;
$prevHash = ClinicalRecordService::GENESIS_HASH;
foreach ($tl as $row) { $okLinks = $okLinks && array_key_exists('previous_hash', $row) && $row['previous_hash'] === $prevHash; $prevHash = $row['record_hash']; }
T::ok($okLinks, 'linha do tempo devolve previous_hash e cada versão aponta para o hash da anterior (' . count($tl) . ' versões)');
T::ok((int)$byId[$id1]['professional_id'] === (int)$a['professional_id'] && (string)$byId[$id1]['professional_crn'] !== '', 'linha do tempo traz o professional_id e o CRN do autor de cada versão');
$ctrlSrc = file_get_contents(__DIR__ . '/../src/Application/Controllers/DashboardController.php');
// v54.25 (NI2-220 I9): a tela do prontuário saiu do controlador para views/clinical/medical_record.php.
$mrView = (string)file_get_contents(__DIR__ . '/../views/clinical/medical_record.php');
T::ok(str_contains($mrView, "\$rec['previous_hash'] !== ClinicalRecordService::GENESIS_HASH) ? htmlspecialchars(\$rec['previous_hash']) : 'Registro inicial'"), 'tela de autenticidade só chama de "Registro inicial" o elo gênese');

echo "== (e) acessibilidade das telas clínicas (a verificação no navegador fica no cenário p233 / C5)\n";
foreach (['profile360', 'patientAnthropometry', 'patientMedicalRecord'] as $fn) {
    preg_match('/public function ' . $fn . '\(.*?\n    }\n/s', $ctrlSrc, $m);
    $body = ($m[0] ?? '') . ($fn === 'patientMedicalRecord' ? $mrView : '');
    T::ok($body !== '' && str_contains($body, 'class="op-message op-message--error" role="alert"'), "{$fn}: caixa de erro anunciada a leitores de tela (role=alert)");
}
// v54.28 (NI2-687): o estilo inline virou utilitário do DS; a cor continua --neutral-gray-600 (#475467).
T::ok(str_contains($ctrlSrc, "u-color-neutral-gray-600 u-font-mono\">\n                        Responsável:"), '"Responsável:" no resumo clínico usa #475467 (era #98A2B3, 2,46:1)');
$long = file_get_contents(__DIR__ . '/../views/patients/longitudinal.php');
T::ok(substr_count($long, 'class="table-responsive" tabindex="0" role="region" aria-labelledby=') === 3, 'tabelas roláveis do perfil (consultas, financeiro, consentimentos) são regiões focáveis pelo teclado');
T::ok(str_contains(file_get_contents(__DIR__ . '/../public/assets/css/clinical-journeys.css'), '.app-content .table-responsive[tabindex] > table { display: table; overflow-x: visible; }'), 'no celular a rolagem fica no invólucro focável, não na tabela');

echo "== multi-clínica\n";
Fx::act($b);
T::throws(fn() => $svc->reviseRecord($id2, $p, $b['professional_id'], 'Tentativa TESTE da clínica B', ['type' => 'revised_note', 'text' => 'x']), 'clínica B não retifica registro da clínica A');
T::done();
