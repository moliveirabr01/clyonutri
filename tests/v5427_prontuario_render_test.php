<?php
/**
 * v54.27: evolução gravada na sala de teleconsulta (conteúdo notes/goals) aparecia como JSON no prontuário.
 * Confere a tela do prontuário, o cartão do perfil, a impressão e a retificação: só texto, sem chave nem id,
 * metas só quando preenchidas, JSON inválido como texto simples, XSS escapado e o conteúdo gravado intacto
 * (mesmo content_json e mesmo record_hash, cadeia válida). Dados TESTE, SQLite descartável. Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5427-prontuario.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Clinical\ClinicalRecordContent;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;
use ClyoNutri\Domain\Clinical\MedicalRecordPrintService;
use ClyoNutri\Domain\Telehealth\TeleconsultationService;

$root = dirname(__DIR__);
putenv('APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk=');
putenv('APP_URL=http://127.0.0.1');
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$a = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner', '27101');
$p = Fx::patient($pdo, $cA, 'Paciente TESTE Vídeo');
$pdo->prepare("INSERT INTO appointments (clinic_id, patient_id, professional_id, scheduled_at, duration_minutes, status, type) VALUES (?, ?, ?, ?, 60, 'confirmed', 'teleconsultation')")
    ->execute([$cA, $p, $a['professional_id'], date('Y-m-d H:i:00', time() + 600)]);
$app = (int)$pdo->lastInsertId();
Fx::act($a);
$_SESSION['user_id'] = $a['user_id']; $_SESSION['clinic_id'] = $cA; $_SESSION['clinic_name'] = 'Clínica TESTE A'; $_SESSION['user_name'] = 'Nutri TESTE A';

$tc = new TeleconsultationService($pdo);
$sess = $tc->scheduleSession($app, $a['professional_id'], $cA);
$sid = (int)$sess['session_id'];
$crs = new ClinicalRecordService($pdo);
$tc->recordClinicalEvolution($sid, $a['professional_id'], 'Evolução — teleconsulta TESTE sem metas', [
    'notes' => "Paciente TESTE relatou fome à noite.\nAjustar o lanche da tarde.", 'goals' => '', 'source' => 'teleconsultation_room',
]);
$tc->recordClinicalEvolution($sid, $a['professional_id'], 'Evolução — teleconsulta TESTE com metas', [
    'notes' => 'Retorno TESTE <script>alert(1)</script> & "aspas"', 'goals' => "Caminhar 30 min <b>3x</b>.\nUma fruta no café.", 'source' => 'teleconsultation_room',
]);
$crs->addClinicalObservation($p, $a['professional_id'], 'Anotação TESTE comum', 'Texto TESTE comum.', 'observacao');

// registro com JSON inválido, gravado direto (cenário de dado antigo/corrompido): só leitura, sem reescrever
$pdo->prepare("INSERT INTO clinical_records (clinic_id, patient_id, professional_id, version, title, content_json, record_hash, previous_hash, is_retracted, created_at, hash_version)
               SELECT clinic_id, patient_id, professional_id, version + 1, 'Registro TESTE malformado', ?, 'x', record_hash, 0, created_at, 2 FROM clinical_records WHERE patient_id = ? ORDER BY version DESC LIMIT 1")
    ->execute(['{"notes": "texto TESTE quebrado <i>sem fechar', $p]);
$malId = (int)$pdo->lastInsertId();

$snap = fn() => $pdo->query('SELECT id, content_json, record_hash FROM clinical_records WHERE patient_id = ' . $p . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$before = $snap();
$teleIds = array_map('intval', $pdo->query("SELECT id FROM clinical_records WHERE patient_id = {$p} AND content_json LIKE '%teleconsultation_room%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

echo "== leitura do conteúdo\n";
$v = ClinicalRecordContent::present(['notes' => 'N', 'goals' => '', 'source' => 'teleconsultation_room', 'teleconsultation_session_id' => 7, 'appointment_id' => 9, 'recorded_during_telehealth' => true]);
T::ok($v['text'] === 'N' && $v['sections'] === [] && $v['via_teleconsultation'] && $v['appointment_id'] === 9 && $v['structured'], 'notes vira o texto, metas vazias não aparecem, origem teleconsulta reconhecida');
$v = ClinicalRecordContent::present('{"notes": "quebrado');
T::ok($v['text'] === '{"notes": "quebrado' && !$v['via_teleconsultation'], 'JSON inválido vira texto simples');
T::ok(ClinicalRecordContent::present(null)['text'] === '' && ClinicalRecordContent::present('"só texto"')['text'] === 'só texto', 'nulo e JSON de string tratados');
$v = ClinicalRecordContent::present(['type' => 'revised_note', 'text' => '{"notes":"interno","goals":"meta X","appointment_id":3}']);
T::ok($v['text'] === 'interno' && ($v['sections'][0]['label'] ?? '') === 'Metas até o retorno', 'texto em dupla codificação também é lido');
$pt = ClinicalRecordContent::plainText(['notes' => 'A', 'goals' => 'B', 'teleconsultation_session_id' => 5, 'scheduled_professional_id' => 8]);
T::ok($pt === "A\n\nMetas até o retorno: B", 'texto corrido sem ids');
$pf = ClinicalRecordContent::printFields(['evolution_summary' => 'R', 'weight_kg' => 72.5, 'waist_cm' => 80, 'appointment_id' => 1, 'scheduled_professional_id' => 2, 'category' => 'evolucao', 'recorded_by' => 3]);
$pfJ = json_encode($pf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
T::ok(str_contains($pfJ, 'Resumo da evolução') && str_contains($pfJ, '72,5 kg') && str_contains($pfJ, '80,0 cm') && str_contains($pfJ, 'Evolução clínica / atendimento') && !preg_match('/_id|recorded|Weight/i', $pfJ), 'impressão: rótulos em português, sem campo técnico');

echo "== tela do prontuário\n";
$dash = new \ClyoNutri\Application\Controllers\DashboardController();
ob_start();
try { $dash->patientMedicalRecord(['id' => $p]); $html = (string)ob_get_clean(); }
catch (\Throwable $e) { $html = (string)ob_get_clean(); T::ok(false, 'prontuário renderiza: ' . $e->getMessage()); }
$tl = substr($html, (int)strpos($html, 'cr-timeline-title'));
$tl = substr($tl, 0, (int)strpos($tl, 'modal-new-record'));
$visible = html_entity_decode(strip_tags($tl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
// o registro malformado mostra o texto bruto de propósito (fallback); as checagens de "sem chave" valem para os demais
$tlOk = implode('', array_filter(explode('<li class="cr-entry', $tl), fn($c) => !str_contains($c, 'Registro TESTE malformado')));
$visibleOk = html_entity_decode(strip_tags($tlOk), ENT_QUOTES | ENT_HTML5, 'UTF-8');
T::ok($tl !== '' && str_contains($visible, 'Paciente TESTE relatou fome à noite.'), 'anotação da teleconsulta aparece como texto');
T::ok(!str_contains($visibleOk, '"notes"') && !str_contains($visibleOk, 'teleconsultation_session_id') && !str_contains($visibleOk, 'recorded_during_telehealth') && !str_contains($visibleOk, 'appointment_id') && !str_contains($visibleOk, 'teleconsultation_room'), 'nenhuma chave interna visível');
T::ok(!str_contains($visibleOk, '{') && !str_contains($visibleOk, '}'), 'nenhum objeto JSON visível na linha do tempo');
T::ok(!str_contains($visible, 'teleconsultation') && !str_contains($visible, 'appointment_id'), 'nem o registro malformado expõe ids da teleconsulta');
T::ok(substr_count($visible, 'Registrada durante a teleconsulta') === 2, 'selo "Registrada durante a teleconsulta" nas duas evoluções da sala');
T::ok(substr_count($tl, 'href="/consulta/' . $app . '"') === 2, 'link para a consulta de origem (mesma clínica e paciente)');
T::ok(substr_count($visible, 'Metas até o retorno') === 1 && str_contains($visible, 'Caminhar 30 min <b>3x</b>.'), 'metas só onde foram preenchidas, como texto');
T::ok(str_contains($tl, 'Retorno TESTE &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;aspas&quot;') && !str_contains($tl, '<script>alert(1)') && !str_contains($tl, '<b>3x</b>'), 'XSS nas anotações e metas sai escapado');
T::ok(str_contains($tl, 'texto TESTE quebrado &lt;i&gt;sem fechar') && !str_contains($tl, '<i>sem fechar'), 'JSON inválido aparece como texto escapado');
T::ok(!str_contains($tl, '<pre>'), 'sem bloco <pre> de JSON');
T::ok(str_contains($tl, 'data-rev-id="' . $teleIds[0] . '" data-rev-notes="Paciente TESTE relatou fome à noite.' . "\n" . 'Ajustar o lanche da tarde."'), 'botão de retificar leva as anotações vigentes');
T::ok((bool)preg_match('/data-rev-id="' . $teleIds[1] . '" data-rev-notes="Retorno TESTE &lt;script&gt;[^"]*"\s+data-rev-goals="Caminhar 30 min &lt;b&gt;3x&lt;\/b&gt;\.\nUma fruta no café\." data-rev-structured="1"/', $tl), 'retificação recebe anotações e metas (não JSON), escapadas');
T::ok(!preg_match('/data-rev-(notes|goals)="[^"]*(&quot;notes|\{)/', $tlOk), 'pré-preenchimento sem JSON');
T::ok(str_contains($html, 'id="dc11-revised_goals"') && str_contains($html, 'name="revised_goals"'), 'campo de metas no formulário de retificação');

echo "== cartão do perfil\n";
ob_start();
try { $dash->profile360(['id' => $p]); $ph = (string)ob_get_clean(); }
catch (\Throwable $e) { $ph = (string)ob_get_clean(); T::ok(false, 'perfil renderiza: ' . $e->getMessage()); }
$phv = html_entity_decode(strip_tags($ph), ENT_QUOTES | ENT_HTML5, 'UTF-8');
T::ok(str_contains($phv, 'Retorno TESTE <script>alert(1)</script>') && !str_contains($ph, '<script>alert(1)'), 'perfil mostra a anotação da teleconsulta como texto escapado');
T::ok(!str_contains($phv, 'teleconsultation_session_id') && !str_contains($phv, 'recorded_during_telehealth') && !str_contains($phv, '"goals"'), 'perfil do paciente sem JSON do prontuário');

echo "== impressão\n";
$doc = (new MedicalRecordPrintService($pdo))->build($p, $a['user_id'], null, null, false);
$dj = json_encode($doc['entries'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$fj = json_encode(array_column($doc['entries'], 'fields'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
T::ok(!preg_match('/teleconsultation|appointment_id|recorded_|_id\b|Source/i', $fj), 'impressão sem campos técnicos');
$tele = array_values(array_filter($doc['entries'], fn($r) => $r['via_teleconsultation']));
T::ok(count($tele) === 2 && ($tele[0]['fields'][0]['label'] ?? '') === 'Anotações', 'impressão: evoluções da sala com "Anotações" e origem marcada');
T::ok(str_contains($dj, 'Metas até o retorno') && substr_count($dj, 'Metas até o retorno') === 1, 'impressão: metas só quando preenchidas');
$mal = array_values(array_filter($doc['entries'], fn($r) => $r['title'] === 'Registro TESTE malformado'));
T::ok(($mal[0]['fields'][0]['value'] ?? '') === '{"notes": "texto TESTE quebrado <i>sem fechar', 'impressão: JSON inválido como texto (a view escapa)');
$printView = (string)file_get_contents($root . '/views/patients/medical_record_print.php');
T::ok(str_contains($printView, 'registrada durante a teleconsulta') && str_contains($printView, 'nl2br($e($f[\'value\']))'), 'view de impressão escapa os valores');

echo "== conteúdo gravado intacto\n";
T::ok($snap() === $before, 'content_json e record_hash iguais depois de renderizar tela, perfil e impressão');

echo "== retificação mantém anotações/metas\n";
$pdo->prepare('DELETE FROM clinical_records WHERE id = ?')->execute([$malId]); // tira o registro de teste fora da cadeia
T::ok($crs->verifyIntegrity($p)['is_valid'] === true, 'cadeia válida antes da retificação');
$rv = $crs->reviseRecord($teleIds[1], $p, $a['professional_id'], 'Correção TESTE de digitação nas metas', ['type' => 'revised_note', 'text' => 'Retorno TESTE corrigido', 'goals' => 'Caminhar 40 min', 'revised_by' => $a['professional_id']]);
$rc = json_decode((string)$pdo->query('SELECT content_json FROM clinical_records WHERE id = ' . (int)($rv['id'] ?? $rv['record_id']))->fetchColumn(), true);
T::ok(($rc['notes'] ?? null) === 'Retorno TESTE corrigido' && !array_key_exists('text', $rc) && ($rc['goals'] ?? null) === 'Caminhar 40 min', 'retificação grava notes/goals, no mesmo formato');
T::ok(!empty($rc['recorded_during_telehealth']) && (int)($rc['appointment_id'] ?? 0) === $app && (int)($rc['teleconsultation_session_id'] ?? 0) === $sid, 'retificação mantém a origem da teleconsulta');
$rv2 = $crs->reviseRecord($teleIds[0], $p, $a['professional_id'], 'Correção TESTE pelo formulário antigo', ['type' => 'revised_note', 'text' => 'Fome à noite TESTE corrigida']);
$rc2 = json_decode((string)$pdo->query('SELECT content_json FROM clinical_records WHERE id = ' . (int)($rv2['id'] ?? $rv2['record_id']))->fetchColumn(), true);
T::ok(($rc2['notes'] ?? null) === 'Fome à noite TESTE corrigida' && ($rc2['goals'] ?? null) === '' && !array_key_exists('text', $rc2), 'sem campo de metas no envio, as metas anteriores continuam');
T::throws(fn() => $crs->reviseRecord((int)($rv['id'] ?? $rv['record_id']), $p, $a['professional_id'], 'Correção TESTE com anotação vazia', ['type' => 'revised_note', 'notes' => '   ']), 'anotação vazia na retificação é recusada', 'obrigatório');
$plain = $pdo->query("SELECT id FROM clinical_records WHERE patient_id = {$p} AND title = 'Anotação TESTE comum'")->fetchColumn();
$rv3 = $crs->reviseRecord((int)$plain, $p, $a['professional_id'], 'Correção TESTE da anotação comum', ['type' => 'revised_note', 'text' => 'Texto TESTE comum corrigido']);
$rc3 = json_decode((string)$pdo->query('SELECT content_json FROM clinical_records WHERE id = ' . (int)($rv3['id'] ?? $rv3['record_id']))->fetchColumn(), true);
T::ok(($rc3['text'] ?? null) === 'Texto TESTE comum corrigido' && !isset($rc3['notes']) && !isset($rc3['source']), 'anotação comum continua com text');
T::ok($crs->verifyIntegrity($p)['is_valid'] === true, 'cadeia continua válida depois das retificações');
ob_start();
$dash->patientMedicalRecord(['id' => $p]); $h2 = (string)ob_get_clean();
$v2 = html_entity_decode(strip_tags($h2), ENT_QUOTES | ENT_HTML5, 'UTF-8');
T::ok(str_contains($v2, 'Retorno TESTE corrigido') && str_contains($v2, 'Caminhar 40 min') && !str_contains($v2, '"notes"') && substr_count($v2, 'Registrada durante a teleconsulta') >= 2, 'versão retificada aparece como texto, com o selo da teleconsulta');

echo "== código\n";
$ctrl = (string)file_get_contents($root . '/src/Application/Controllers/DashboardController.php');
T::ok(!str_contains($ctrl, "json_encode(\$cr['content']"), 'perfil não cai mais em json_encode do conteúdo');
T::ok(str_contains($ctrl, "array_key_exists('revised_goals', \$_POST)"), 'controlador envia as metas da retificação');
$mr = (string)file_get_contents($root . '/views/clinical/medical_record.php');
T::ok(!str_contains($mr, 'json_encode($rec[\'content\']') && !str_contains($mr, 'style="'), 'prontuário sem json_encode do conteúdo e sem estilo inline');

T::done();
