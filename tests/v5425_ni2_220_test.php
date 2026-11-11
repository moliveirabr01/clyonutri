<?php
/**
 * v54.25 / NI2-220 (correções do code review da anamnese, prontuário e sala de consulta). Dados TESTE, SQLite descartável.
 *  B1 autoria de quem conclui (com CRN) · B2 "Não avaliado" no lugar de negativa inventada (migração 86)
 *  B3 recusa de dado fora da faixa, sem corte silencioso · I1..I8 e I10 · retificação mantém dados estruturados
 *  I9 telas no design system (sem style="" e sem cor fixa). A parte de navegador fica no cenário p220.
 * Fora do zip.
 */
declare(strict_types=1);
putenv('V54_DB=' . dirname(__DIR__) . '/storage/testing-v5425-ni2-220.sqlite');
require __DIR__ . '/_v54_bootstrap.php';

use ClyoNutri\Domain\Anamnesis\AnamnesisService;
use ClyoNutri\Domain\Appointment\AppointmentService;
use ClyoNutri\Domain\Clinical\ClinicalAuthor;
use ClyoNutri\Domain\Clinical\ClinicalInputException;
use ClyoNutri\Domain\Clinical\ClinicalRecordService;

$root = dirname(__DIR__);
$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$a = Fx::user($pdo, $cA, 'Nutri TESTE A', 'owner', '22001');
$a2 = Fx::user($pdo, $cA, 'Nutri TESTE A2', 'nutritionist', '22002');
$adm = Fx::user($pdo, $cA, 'Gestora TESTE sem CRN', 'admin', null, null, 'admin');
$rec = Fx::user($pdo, $cA, 'Recepção TESTE com CRN', 'receptionist', '22003');
$b = Fx::user($pdo, $cB, 'Nutri TESTE B', 'owner', '22004');
$p = Fx::patient($pdo, $cA, 'Paciente TESTE 220');
$count = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

echo "== B1/I4 regra única de autoria clínica (ClinicalAuthor)\n";
T::ok(ClinicalAuthor::resolve($pdo, $cA, $a['user_id']) === $a['professional_id'], 'dona com CRN é autora com o próprio CRN');
T::ok(ClinicalAuthor::resolve($pdo, $cA, $a2['user_id']) === $a2['professional_id'], 'colega com CRN é autora com o próprio CRN');
T::ok(ClinicalAuthor::resolve($pdo, $cA, $adm['user_id']) === null, 'administradora sem CRN não é autora clínica');
T::ok(ClinicalAuthor::resolve($pdo, $cA, $rec['user_id']) === null, 'recepção não é autora clínica mesmo com CRN cadastrado (papel conta)');
T::ok(ClinicalAuthor::resolve($pdo, $cA, $b['user_id']) === null, 'profissional de outra clínica não é autora nesta');
$pdo->prepare("UPDATE professionals SET crn = '  ' WHERE id = ?")->execute([$a2['professional_id']]);
T::ok(ClinicalAuthor::resolve($pdo, $cA, $a2['user_id']) === null, 'CRN em branco não vale como CRN');
$pdo->prepare("UPDATE professionals SET crn = '22002' WHERE id = ?")->execute([$a2['professional_id']]);
$ctrlSrc = (string)file_get_contents($root . '/src/Application/Controllers/DashboardController.php');
preg_match('/private function clinicalAuthorProfessionalId\(.*?\n    }\n/s', $ctrlSrc, $m);
T::ok(isset($m[0]) && str_contains($m[0], 'ClinicalAuthor::resolve'), 'controlador usa a mesma regra (I4)');
$wfSrc = (string)file_get_contents($root . '/src/Application/Controllers/WorkflowController.php');
T::ok(str_contains($wfSrc, 'ClinicalAuthor::resolve') && str_contains($wfSrc, 'ClinicalAuthor::SEM_CRN_MSG'), 'rascunho automático da anamnese usa a mesma regra (I4)');

echo "== B1 conclusão de consulta: autoria de quem conclui\n";
$svc = new AppointmentService($pdo);
$app1 = Fx::appointment($pdo, $cA, $p, $a['professional_id']);
Fx::act($adm);
$before = $count("SELECT COUNT(*) FROM clinical_records WHERE patient_id = $p");
T::throws(fn() => $svc->completeConsultation($app1, 'Evolução TESTE pela gestora', [], $adm['user_id']), 'gestora sem CRN não conclui consulta', 'CRN');
T::ok($count("SELECT COUNT(*) FROM clinical_records WHERE patient_id = $p") === $before, 'nada gravado no prontuário na tentativa da gestora');
T::ok((string)$pdo->query("SELECT status FROM appointments WHERE id = $app1")->fetchColumn() === 'in_progress', 'consulta continua em andamento');
Fx::act($a2);
T::throws(fn() => $svc->completeConsultation($app1, 'Evolução TESTE', [], $a2['user_id'], $a['professional_id']), 'autoria informada diferente de quem conclui é recusada', 'CRN');
$res = $svc->completeConsultation($app1, 'Evolução TESTE concluída pela colega', ['weight_kg' => '68,5', 'waist_cm' => '80'], $a2['user_id'], $a2['professional_id']);
$row = $pdo->query('SELECT professional_id, content_json FROM clinical_records WHERE id = ' . (int)$res['clinical_record_id'])->fetch();
$content = json_decode((string)$row['content_json'], true);
T::ok((int)$row['professional_id'] === $a2['professional_id'], 'evolução gravada com o CRN de quem concluiu (A2), não do profissional agendado');
T::ok((int)($content['recorded_by'] ?? 0) === $a2['professional_id'] && (int)($content['scheduled_professional_id'] ?? 0) === $a['professional_id'], 'profissional agendado fica registrado à parte (scheduled_professional_id)');
T::ok(abs((float)$content['weight_kg'] - 68.5) < 0.001 && abs((float)$content['waist_cm'] - 80.0) < 0.001, 'vírgula decimal aceita (68,5 kg)');
preg_match('/public function completeConsultation\(.*?\n    }\n/s', $ctrlSrc, $m);
$cc = $m[0] ?? '';
T::ok(str_contains($cc, 'ClinicalAuthor::resolve') && str_contains($cc, '->recordAssessment(') && preg_match('/recordAssessment\(\s*\(int\)\$app\[.patient_id.\],\s*\$authorId/', $cc) === 1, 'antropometria da conclusão gravada com o mesmo autor (quem concluiu)');
T::ok(!str_contains($cc, 'getActiveProfessionalId()'), 'conclusão não usa o resolvedor antigo (que caía no CRN de terceiros)');

echo "== B3 dados fora da faixa recusados, sem corte\n";
$app2 = Fx::appointment($pdo, $cA, $p, $a['professional_id']);
Fx::act($a);
$before = $count("SELECT COUNT(*) FROM clinical_records WHERE patient_id = $p");
try {
    $svc->completeConsultation($app2, 'Evolução TESTE', ['weight_kg' => '1000', 'waist_cm' => '3'], $a['user_id']);
    T::ok(false, 'peso 1000 kg e cintura 3 cm recusados');
} catch (ClinicalInputException $e) {
    $fe = $e->fieldErrors();
    T::ok(isset($fe['weight_kg'], $fe['waist_cm']), 'peso 1000 kg e cintura 3 cm recusados com erro por campo');
    T::ok(str_contains($fe['weight_kg'], 'entre 10 e 400 kg') && str_contains($fe['waist_cm'], 'entre 10 e 250 cm'), 'mensagens em português com a faixa aceita');
}
T::ok($count("SELECT COUNT(*) FROM clinical_records WHERE patient_id = $p") === $before, 'nada gravado no registro imutável com medida inválida');
T::ok((string)$pdo->query("SELECT status FROM appointments WHERE id = $app2")->fetchColumn() === 'in_progress', 'consulta segue aberta para corrigir');
T::throws(fn() => AppointmentService::validatedConsultationDetails(['weight_kg' => 'abc']), 'texto no peso é recusado', 'informe um número');
T::throws(fn() => AppointmentService::validatedConsultationDetails(['weight_kg' => 'INF']), 'infinito no peso é recusado');
T::ok(AppointmentService::validatedConsultationDetails(['weight_kg' => '', 'waist_cm' => '']) === [], 'medidas vazias = não aferidas (opcionais)');

$an = new AnamnesisService($pdo);
$aBefore = $count("SELECT COUNT(*) FROM anamneses WHERE patient_id = $p");
try {
    $an->saveAnamnesis($p, $a['professional_id'], ['chief_complaint' => 'Queixa TESTE', 'water_intake_ml' => '99999', 'sleep_hours' => '30', 'has_surgeries' => 'talvez']);
    T::ok(false, 'anamnese com água 99999 ml, sono 30 h e opção desconhecida recusada');
} catch (ClinicalInputException $e) {
    $fe = $e->fieldErrors();
    T::ok(isset($fe['water_intake_ml'], $fe['sleep_hours'], $fe['has_surgeries']), 'anamnese recusa água 99999 ml, sono 30 h e opção desconhecida, com erro por campo');
    T::ok(str_contains($fe['water_intake_ml'], 'entre 0 e 10.000 ml') && str_contains($fe['sleep_hours'], 'entre 0 e 24 horas'), 'mensagens da anamnese com a faixa (sem cortar para 10.000 ou 24)');
}
try {
    $an->saveAnamnesis($p, $a['professional_id'], ['chief_complaint' => 'Queixa TESTE', 'physical_activity' => 'active', 'activity_modality' => 'Caminhada', 'activity_frequency_weekly' => '9', 'activity_duration_minutes' => '2']);
    T::ok(false, 'frequência 9 dias e duração 2 minutos recusadas');
} catch (ClinicalInputException $e) {
    T::ok(isset($e->fieldErrors()['activity_frequency_weekly'], $e->fieldErrors()['activity_duration_minutes']), 'frequência 9 dias/semana e duração 2 min recusadas por campo');
}
T::throws(fn() => $an->saveAnamnesis($p, $a['professional_id'], ['chief_complaint' => 'Queixa TESTE', 'water_intake_ml' => '1500,5']), 'água precisa ser inteira', 'inteiro');
T::ok($count("SELECT COUNT(*) FROM anamneses WHERE patient_id = $p") === $aBefore, 'nenhuma anamnese gravada com dado inválido');
preg_match('/public function savePatientAnamnesis\(.*?\n    }\n/s', $ctrlSrc, $m);
$sa = $m[0] ?? '';
T::ok(str_contains($sa, "\$_SESSION['anamnesis_field_errors'][\$patientId] = \$e->fieldErrors()"), 'erros por campo da anamnese voltam para a tela');

echo "== B2 pergunta não feita = Não avaliado (NULL)\n";
$saved = $an->saveAnamnesis($p, $a['professional_id'], ['chief_complaint' => 'Queixa TESTE só com texto']);
$r = $pdo->query('SELECT * FROM anamneses WHERE id = ' . (int)$saved['id'])->fetch();
$enumCols = array_keys(AnamnesisService::ENUM_OPTIONS);
$allNull = true;
foreach ($enumCols as $c) { $allNull = $allNull && $r[$c] === null; }
T::ok($allNull, 'sem resposta, as perguntas fechadas ficam NULL (' . implode(', ', $enumCols) . ')');
T::ok(AnamnesisService::answerLabel('smoking_status', null) === 'Não avaliado' && AnamnesisService::answerLabel('has_surgeries', '') === 'Não avaliado', 'NULL é exibido como "Não avaliado"');
T::ok(AnamnesisService::answerLabel('smoking_status', 'non_smoker') === 'Não fumante', 'resposta marcada mantém o rótulo');
$saved2 = $an->saveAnamnesis($p, $a['professional_id'], ['chief_complaint' => 'Queixa TESTE', 'smoking_status' => 'not_assessed', 'has_surgeries' => 'no']);
$r2 = $pdo->query('SELECT smoking_status, has_surgeries FROM anamneses WHERE id = ' . (int)$saved2['id'])->fetch();
T::ok($r2['smoking_status'] === null && $r2['has_surgeries'] === 'no', '"Não avaliado" explícito vira NULL; negativa marcada fica gravada');
$notNull = [];
foreach ($pdo->query('PRAGMA table_info(anamneses)')->fetchAll() as $col) {
    if (in_array($col['name'], $enumCols, true) && ((int)$col['notnull'] === 1 || !in_array(strtoupper((string)$col['dflt_value']), ['', 'NULL'], true))) $notNull[] = $col['name'];
}
T::ok($notNull === [], 'migração 86: as 7 colunas aceitam NULL e não têm resposta padrão' . ($notNull ? ' (falta: ' . implode(', ', $notNull) . ')' : ''));
$summary = $an->getClinicalSummaryForPrescription($p);
T::ok(array_key_exists('smoking_status', $summary) && $summary['smoking_status'] === null && $summary['physical_activity'] === null, 'resumo para prescrição não presume "não fumante" nem "sedentário"');

require_once $root . '/database/migrations/2026_11_03_000086_anamnesis_unanswered_state.php';
$ins = $pdo->prepare("INSERT INTO anamneses (clinic_id, patient_id, professional_id, version, objectives, is_draft, created_at, has_surgeries, medication_usage, supplement_usage, intolerance_assessment, alcohol_consumption, smoking_status, physical_activity, structured_data_json)
    VALUES (?, ?, ?, ?, ?, 0, ?, 'no', 'none', 'none', 'none', 'none', 'non_smoker', 'sedentary', ?)");
$pL = Fx::patient($pdo, $cA, 'Paciente TESTE legado');
$ins->execute([$cA, $pL, $a['professional_id'], 1, 'Objetivo TESTE legado', '2026-09-01 10:00:00', null]);
$legacyId = (int)$pdo->lastInsertId();
$ins->execute([$cA, $pL, $a['professional_id'], 2, 'Objetivo TESTE estruturado', '2026-10-20 10:00:00', '{"smoking_status":"non_smoker"}']);
$structId = (int)$pdo->lastInsertId();
(new AnamnesisUnansweredState())->up($pdo);
$l = $pdo->query("SELECT * FROM anamneses WHERE id = $legacyId")->fetch();
$s = $pdo->query("SELECT * FROM anamneses WHERE id = $structId")->fetch();
$legacyNull = true; foreach ($enumCols as $c) { $legacyNull = $legacyNull && $l[$c] === null; }
T::ok($legacyNull, 'linha anterior ao formulário estruturado (negativas vindas só do DEFAULT da 73) volta para NULL');
T::ok($s['smoking_status'] === 'non_smoker' && $s['physical_activity'] === 'sedentary', 'linha do formulário estruturado fica como está (não dá para saber o que foi marcado)');
T::ok($l['objectives'] === 'Objetivo TESTE legado', 'texto clínico da linha antiga preservado');
$snap = json_encode($pdo->query('SELECT * FROM anamneses ORDER BY id')->fetchAll());
(new AnamnesisUnansweredState())->up($pdo);
T::ok(json_encode($pdo->query('SELECT * FROM anamneses ORDER BY id')->fetchAll()) === $snap, 'migração 86 idempotente (segunda execução não muda nada)');
T::ok((string)$pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite íntegro depois da migração');

echo "== I1 rascunho preserva autoria e início\n";
$pD = Fx::patient($pdo, $cA, 'Paciente TESTE rascunho');
Fx::act($a);
$d1 = $an->saveAnamnesis($pD, $a['professional_id'], ['chief_complaint' => 'Rascunho TESTE'], true);
$pdo->exec("UPDATE anamneses SET created_at = '2026-11-01 09:00:00' WHERE id = " . (int)$d1['id']);
Fx::act($a2);
$an->saveAnamnesis($pD, $a2['professional_id'], ['chief_complaint' => 'Rascunho TESTE editado pela colega'], true);
$dr = $pdo->query('SELECT professional_id, created_at, updated_by_professional_id, updated_at, is_draft FROM anamneses WHERE id = ' . (int)$d1['id'])->fetch();
T::ok((int)$dr['professional_id'] === $a['professional_id'] && $dr['created_at'] === '2026-11-01 09:00:00', 'salvar o rascunho de novo não troca autora nem data de início');
T::ok((int)$dr['updated_by_professional_id'] === $a2['professional_id'] && !empty($dr['updated_at']), 'última edição registrada à parte (updated_by / updated_at)');
$an->saveAnamnesis($pD, $a2['professional_id'], ['chief_complaint' => 'Anamnese TESTE concluída pela colega'], false);
$fin = $pdo->query('SELECT professional_id, is_draft, structured_data_json FROM anamneses WHERE id = ' . (int)$d1['id'])->fetch();
$origin = json_decode((string)\ClyoNutri\Core\Security\ClinicalEncryptionService::decrypt((string)$fin['structured_data_json']), true)['draft_origin'] ?? [];
T::ok((int)$fin['is_draft'] === 0 && (int)$fin['professional_id'] === $a2['professional_id'], 'ao concluir, a versão é de quem concluiu');
T::ok((int)($origin['professional_id'] ?? 0) === $a['professional_id'] && ($origin['started_at'] ?? '') === '2026-11-01 09:00:00', 'origem do rascunho (quem começou e quando) fica no JSON da versão');

echo "== I2 conferência de concorrência obrigatória no servidor\n";
T::ok(str_contains($sa, "\$wfRevision === '' || \$wfKey === ''") && strpos($sa, "\$wfKey === ''") < strpos($sa, '->write(\'anamnesis\''), 'sem revisão lida ou chave da gravação, nada é gravado');
T::ok(!preg_match('/if\s*\(\s*\$wfRevision\s*!==\s*null/', $sa), 'checagem não é mais opcional (antes omitir os campos pulava a conferência)');

echo "== I3 origem real no resumo\n";
$pC = Fx::patient($pdo, $cA, 'Paciente TESTE cadastro');
$pdo->prepare("UPDATE patients SET allergies = 'Amendoim (TESTE)' WHERE id = ?")->execute([$pC]);
Fx::act($a);
$sum = $an->getClinicalSummaryForPrescription($pC);
T::ok(($sum['source']['kind'] ?? '') === 'cadastro' && $sum['source']['version'] === null && $sum['source']['recorded_at'] === null, 'sem anamnese, a origem é o cadastro, sem versão nem data inventadas');
T::ok(!str_contains(json_encode($sum, JSON_UNESCAPED_UNICODE), 'Anamnese v1'), 'resumo não fala em "Anamnese v1" quando a fonte é o cadastro');
$sumA = $an->getClinicalSummaryForPrescription($pD);
T::ok(($sumA['source']['kind'] ?? '') === 'anamnese' && (int)$sumA['source']['version'] === 1, 'com anamnese, a origem é a versão real');
$mrView = (string)file_get_contents($root . '/views/clinical/medical_record.php');
T::ok(str_contains($mrView, "\$summarySource['kind'] ?? '') === 'cadastro'") || str_contains($mrView, "=== 'cadastro'"), 'tela do prontuário mostra a origem conforme o tipo');

echo "== I5/I6/I8 rotas clínicas\n";
preg_match('/public function prescriptionSummary\(.*?\n    }\n/s', $ctrlSrc, $m);
$ps = $m[0] ?? '';
T::ok(str_contains($ps, 'clinicalAuthorProfessionalId() === null') && str_contains($ps, '403'), 'resumo para prescrição exige papel clínico com CRN (I5)');
T::ok(!str_contains($ps, 'getMessage()'), 'resumo para prescrição não devolve texto de exceção (I5)');
preg_match('/public function exportMedicalRecord\(.*?\n    }\n/s', $ctrlSrc, $m);
$ex = $m[0] ?? '';
T::ok(str_contains($ex, '$this->clinicalAuthorProfessionalId()') && !str_contains($ex, 'getActiveProfessionalId') && !str_contains($ex, '?: 1'), 'exportação registra quem exportou, sem cair no id 1 (I6)');
T::ok(!str_contains($ex, 'getMessage()') || preg_match_all('/getMessage\(\)/', $ex) === preg_match_all('/str_starts_with\(\$e->getMessage\(\)/', $ex), 'exportação não mostra texto técnico (I8)');
T::ok(!str_contains($cc, "msg=' . urlencode(\$e->getMessage())") && !str_contains($cc, 'msg=" . urlencode($e->getMessage())') && str_contains($cc, 'error=falha&ref='), 'conclusão de consulta: falha técnica vira mensagem amigável + código (I8)');
$roomView = (string)file_get_contents($root . '/views/consultation/room.php');
T::ok(!str_contains($roomView, 'getMessage') && str_contains($roomView, "preg_match('/^[a-f0-9]{12}\$/D'"), 'sala de consulta só exibe o código de suporte validado (I8)');
$logBody = (preg_match('/private function logClinicalFailure\(.*?\n    }\n/s', $ctrlSrc, $m) ? $m[0] : '');
T::ok($logBody !== '' && !str_contains($logBody, 'getMessage'), 'log da falha leva classe, arquivo e linha, sem texto que possa ter dado clínico');

echo "== I7 trilha clínica guarda o usuário\n";
Fx::act($a2);
$crs = new ClinicalRecordService($pdo);
$obs = $crs->addClinicalObservation($p, $a2['professional_id'], 'Observação TESTE', 'Texto TESTE', 'evolucao');
$uid = $pdo->query("SELECT user_id FROM audit_logs WHERE resource = 'clinical_records' ORDER BY id DESC LIMIT 1")->fetchColumn();
T::ok((int)$uid === $a2['user_id'], 'audit_logs.user_id = usuária que agiu');

echo "== retificação mantém os dados estruturados\n";
Fx::act($a);
$rv = $crs->reviseRecord((int)$res['clinical_record_id'], $p, $a['professional_id'], 'Correção TESTE de digitação da evolução', ['type' => 'revised_note', 'text' => 'Evolução TESTE corrigida']);
$rc = json_decode((string)$pdo->query('SELECT content_json FROM clinical_records WHERE id = ' . (int)($rv['id'] ?? $rv['record_id']))->fetchColumn(), true);
T::ok((int)($rc['appointment_id'] ?? 0) === $app1 && abs((float)($rc['weight_kg'] ?? 0) - 68.5) < 0.001 && isset($rc['waist_cm']), 'retificação leva consulta de origem, peso e cintura da versão anterior');
T::ok(($rc['text'] ?? '') === 'Evolução TESTE corrigida', 'texto novo prevalece');
T::ok($crs->verifyIntegrity($p)['is_valid'] === true, 'cadeia de registros continua válida (hash não mudou de algoritmo)');

echo "== I10 texto do selo condiz com o que o hash garante\n";
T::ok(str_contains($mrView, 'Encadeamento dos registros conferido, sem divergências') && str_contains($mrView, 'não é assinatura digital'), 'selo fala em encadeamento conferido e avisa que não é assinatura digital');
T::ok(!str_contains($mrView, 'autêntico') && !str_contains($mrView, 'inviolável'), 'sem promessa de autenticidade ou inviolabilidade');

echo "== I9 telas no design system\n";
$files = ['views/clinical/medical_record.php', 'views/anamnesis/form_page.php', 'views/anamnesis/version.php', 'views/consultation/room.php', 'public/assets/css/clinical-record.css'];
foreach ($files as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    T::ok(!str_contains($src, 'style="'), "{$f}: sem style=\"\" inline");
    T::ok(!preg_match('/#[0-9a-fA-F]{3,8}\b(?![^<]*\?>)/', preg_replace(['/href="#[^"]*"/', '/<\?=.*?\?>/s', '/\/\*.*?\*\//s'], '', $src)), "{$f}: sem cor fixa em hexadecimal");
}
foreach (['patientMedicalRecord' => 'views/clinical/medical_record.php', 'patientAnamnesis' => 'views/anamnesis/form_page.php', 'consultationRoom' => 'views/consultation/room.php'] as $fn => $view) {
    preg_match('/public function ' . $fn . '\(.*?\n    }\n/s', $ctrlSrc, $m);
    T::ok(isset($m[0]) && str_contains($m[0], basename(dirname($view)) . '/' . basename($view)) && !str_contains($m[0], 'style="'), "{$fn} usa {$view} e não tem marcação inline");
}
$form = (string)file_get_contents($root . '/views/anamnesis/form_page.php');
T::ok(str_contains($form, "<option value=\"\"' . (\$current === null ? ' selected' : '') . '>Não avaliado</option>"), 'formulário abre com "Não avaliado" como primeira opção das perguntas fechadas');
foreach (['water_intake_ml' => ['0', '10000'], 'sleep_hours' => ['0', '24']] as $f => [$mn, $mx]) {
    T::ok((bool)preg_match('/name="' . $f . '"[^>]*min="' . $mn . '"[^>]*max="' . $mx . '"|min="' . $mn . '"[^>]*max="' . $mx . '"[^>]*name="' . $f . '"/', $form), "{$f}: faixa {$mn}–{$mx} também no navegador");
}
T::ok(str_contains($roomView, 'CONSULT_WEIGHT_RANGE[1]') && str_contains($roomView, 'CONSULT_WAIST_RANGE[1]'), 'sala de consulta: faixa de peso e cintura vem da mesma constante do servidor');
foreach (['consult-start-panel', 'early-start-warning', 'consult-start', 'consult-closed', 'consult-submit-error', 'draft-status', 'data-consult-draft', 'data-draft-pause', 'consulta-rascunho.js?v=v54-18', 'dc9-weight_kg', 'dc10-waist_cm'] as $hook) {
    if (!str_contains($roomView, $hook)) T::ok(false, "sala de consulta manteve o gancho {$hook}");
}
T::ok(true, 'sala de consulta manteve os ganchos de teste e o script de rascunho');

T::done();
