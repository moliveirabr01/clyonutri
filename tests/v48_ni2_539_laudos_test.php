<?php
/**
 * v48 · NI2-539: laudos anexados à solicitação. Inspeção de arquivo (arquivos maliciosos), guarda do original,
 * envio pela nutricionista e pela paciente, revisão, correção por versão, links que expiram, isolamento e trilha.
 * Dados e arquivos só de TESTE.
 */
declare(strict_types=1);
require __DIR__ . '/_v47_exam_bootstrap.php';

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Domain\LaboratoryExam\ExamFileInspector;
use ClyoNutri\Domain\LaboratoryExam\ExamPdfIntegrityChecker;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestPdfService;
use ClyoNutri\Domain\LaboratoryExam\ExamRequestService;
use ClyoNutri\Domain\LaboratoryExam\ExamResultDocumentService;

putenv('APP_KEY=chave-de-teste-v48-0123456789abcdef');
$tmpDir = sys_get_temp_dir() . '/v48-arquivos-' . getmypid();
@mkdir($tmpDir, 0700, true);

// ---- arquivos de TESTE
$pdfOk = (new ExamRequestPdfService())->render(['request_number' => 'LAUDO-TESTE', 'status' => 'issued', 'status_label' => 'x', 'issued_date' => '', 'issued_at_local' => '', 'cancelled_at_local' => '', 'cancellation_reason' => '', 'policy_version' => '', 'version_number' => 1, 'supersedes_request_number' => '',
    'clinic' => ['name' => 'Laboratório TESTE', 'document' => '', 'address' => '', 'city' => '', 'phone' => '', 'email' => '', 'header_text' => '', 'footer_text' => ''],
    'patient' => ['name' => 'Paciente TESTE', 'birth_date' => '', 'age' => null], 'professional' => ['name' => 'x', 'crn' => 'x', 'specialty' => ''],
    'items' => [['name' => 'Glicemia de jejum: 92 mg/dL (TESTE)', 'sample_type' => 'Plasma', 'instructions' => '']], 'justification' => 'Laudo TESTE', 'diagnostic' => '', 'coverage_notice' => '', 'legal_basis' => '']);
function f(string $name, string $bytes): array { global $tmpDir; $p = $tmpDir . '/' . bin2hex(random_bytes(4)); file_put_contents($p, $bytes); return ['name' => $name, 'tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]; }
$img = imagecreatetruecolor(40, 30); imagefill($img, 0, 0, imagecolorallocate($img, 200, 220, 200));
ob_start(); imagepng($img); $png = (string)ob_get_clean();
ob_start(); imagejpeg($img); $jpg = (string)ob_get_clean();
$pdfJs = "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert('x')) >> >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
$pdfJsCompact = "%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj\n%%EOF\n";
$pdfLaunch = "%PDF-1.4\n1 0 obj << /Type /Action /S /Launch /F (cmd.exe) >> endobj\n%%EOF\n";
$pdfEnc = "%PDF-1.4\ntrailer << /Encrypt 5 0 R >>\n%%EOF\n";
$pdfEmb = "%PDF-1.4\n1 0 obj << /EmbeddedFiles 2 0 R >> endobj\n%%EOF\n";
$pdfTrunc = substr($pdfOk, 0, (int)(strlen($pdfOk) / 2));
// PDF legítimo com "/JS" e "<?php" só dentro de um fluxo compactado (dados binários): não pode dar falso positivo.
$pdfStream = "%PDF-1.4\n1 0 obj << /Length 40 >>\nstream\n\x00\x01/JS <?php \xff\xfe binario\nendstream\nendobj\n%%EOF\n";
$poly = $jpg . "\n<?php system(\$_GET['c']); ?>";
$phpAsPdf = "<?php echo 'oi'; ?>";
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
$html = "<html><body><script>alert(1)</script></body></html>";

echo "== Inspeção de arquivo (sem antivírus no ambiente de teste: modo auto)\n";
$insp = new ExamFileInspector(fn() => ['available' => false, 'clean' => null, 'engine' => 'clamav'], 'auto');
$ok = fn(array $file, string $label) => T::ok((function () use ($insp, $file) { try { $insp->inspect($file['tmp_name'], $file['name']); return true; } catch (\Throwable $e) { echo '      (' . $e->getMessage() . ")\n"; return false; } })(), $label);
$bad = fn(array $file, string $label, string $msg) => T::throws(fn() => $insp->inspect($file['tmp_name'], $file['name']), $label, $msg);
$r = $insp->inspect(f('laudo.pdf', $pdfOk)['tmp_name'], 'laudo.pdf');
T::ok($r['mime'] === 'application/pdf' && $r['sha256'] === hash('sha256', $pdfOk) && str_contains($r['security_check'], 'antivirus:indisponivel'), 'PDF de laudo aceito, hash dos bytes e registro de antivírus indisponível');
$ok(f('foto do laudo.JPEG', $jpg), 'JPEG aceito (extensão maiúscula)');
$ok(f('laudo.png', $png), 'PNG aceito');
$ok(f('stream.pdf', $pdfStream), 'PDF com bytes suspeitos só dentro de fluxo compactado não dá falso positivo');
$bad(f('laudo.pdf', $pdfJs), 'PDF com JavaScript recusado', 'JavaScript');
$bad(f('laudo.pdf', $pdfJsCompact), 'PDF com JavaScript sem espaços recusado', 'conteúdo ativo');
$bad(f('laudo.pdf', $pdfLaunch), 'PDF que abre programa recusado', 'abre programa');
$bad(f('laudo.pdf', $pdfEmb), 'PDF com arquivo embutido recusado', 'arquivo embutido');
$bad(f('laudo.pdf', $pdfEnc), 'PDF com senha recusado', 'senha');
$bad(f('laudo.pdf', "%PDF-1.4\n1 0 obj << /S /J#61vaScript /J#53 (x) >> endobj\n%%EOF\n"), 'PDF com JavaScript disfarçado em hexadecimal recusado', 'JavaScript');
$bad(f('laudo.pdf', "%PDF-1.4\n1 0 obj << /Type /EmbeddedFile >> endobj\n%%EOF\n"), 'PDF com /EmbeddedFile (singular) recusado', 'arquivo embutido');
$bad(f('laudo.pdf', $pdfTrunc), 'PDF incompleto recusado', 'incompleto');
$bad(f('foto.jpg', $poly), 'imagem com código PHP escondido recusada', 'não permitido');
$bad(f('laudo.pdf', $phpAsPdf), 'PHP com nome .pdf recusado (tipo real)', 'Formato não aceito');
$bad(f('laudo.svg', $svg), 'SVG recusado', 'Formato não aceito');
$bad(f('laudo.html', $html), 'HTML recusado', 'Formato não aceito');
$bad(f('laudo.pdf', $png), 'imagem com nome .pdf recusada (nome não combina)', 'não combina');
$bad(f('laudo.php.pdf', $pdfOk), 'nome com .php no meio recusado', 'Nome de arquivo');
$bad(f('laudo', $pdfOk), 'nome sem extensão recusado', 'não combina');
$bad(f('vazio.pdf', ''), 'arquivo vazio recusado', 'vazio');
$bigFile = f('grande.pdf', '%PDF-1.4' . str_repeat('A', ExamFileInspector::MAX_BYTES) . "\n%%EOF");
$bad($bigFile, 'acima de 15 MB recusado', '15 MB');
unlink($bigFile['tmp_name']);
T::ok(ExamFileInspector::cleanName("../../etc/pass\x00wd<script>.pdf") === 'pass_wd_script_.pdf', 'nome limpo, sem caminho nem caracteres de controle');
$infected = new ExamFileInspector(fn() => ['available' => true, 'clean' => false, 'engine' => 'clamdscan'], 'auto');
T::throws(fn() => $infected->inspect(f('laudo.pdf', $pdfOk)['tmp_name'], 'laudo.pdf'), 'antivírus acusa: recusado', 'verificação de segurança');
$clean = new ExamFileInspector(fn() => ['available' => true, 'clean' => true, 'engine' => 'clamdscan'], 'auto');
T::ok(str_contains($clean->inspect(f('laudo.pdf', $pdfOk)['tmp_name'], 'laudo.pdf')['security_check'], 'antivirus:clamdscan-ok'), 'antivírus limpo fica registrado');
$required = new ExamFileInspector(fn() => ['available' => false, 'clean' => null, 'engine' => 'clamav'], 'required');
T::throws(fn() => $required->inspect(f('laudo.pdf', $pdfOk)['tmp_name'], 'laudo.pdf'), 'modo "required" sem antivírus recusa', 'Não foi possível verificar');

// ---- cenário
$cA = Fx::clinic($pdo, 'Clínica TESTE A');
$cB = Fx::clinic($pdo, 'Clínica TESTE B');
$ownerA = Fx::user($pdo, $cA, 'Dona TESTE A', 'owner');
$nutriA = Fx::user($pdo, $cA, 'Nutri TESTE A2', 'nutritionist');
$secA = Fx::user($pdo, $cA, 'Secretaria TESTE A', 'secretary', null, null, 'secretary');
$ownerB = Fx::user($pdo, $cB, 'Dona TESTE B', 'owner');
$pA = Fx::patient($pdo, $cA, 'Paciente TESTE A');
$pA2 = Fx::patient($pdo, $cA, 'Paciente TESTE A2');
$pB = Fx::patient($pdo, $cB, 'Paciente TESTE B');
$req = new ExamRequestService($pdo);
$svc = new ExamResultDocumentService($pdo, $insp);
$J = 'Avaliar glicemia e reservas de ferro para ajuste do plano alimentar.';
Fx::act($ownerA);
$r = $req->saveDraft($pA, $ownerA['user_id'], ['exam_ids' => [Fx::examId($pdo, 'GLICEMIA_JEJUM'), Fx::examId($pdo, 'FERRITINA_SERICA')], 'clinical_justification' => $J]);
$draftId = (int)$r['id'];
[$itG, $itF] = array_map(fn($i) => (int)$i['id'], $r['items']);
T::throws(fn() => $svc->uploadByProfessional($draftId, $ownerA['user_id'], f('laudo.pdf', $pdfOk), ['item_ids' => [$itG]]), 'rascunho não recebe laudo', 'emitida');
$req->submitForReview($draftId, $ownerA['user_id']);
$req->issue($draftId, $ownerA['user_id'], true);
$rid = $draftId;
$rB = $req->saveDraft($pA2, $ownerA['user_id'], ['exam_ids' => [Fx::examId($pdo, 'GLICEMIA_JEJUM')], 'clinical_justification' => $J]);
$req->submitForReview((int)$rB['id'], $ownerA['user_id']);
$req->issue((int)$rB['id'], $ownerA['user_id'], true);
$otherItem = (int)$rB['items'][0]['id'];

echo "== Nutricionista anexa (aceito na hora, com exames cobertos)\n";
T::throws(fn() => $svc->uploadByProfessional($rid, $ownerA['user_id'], f('laudo.pdf', $pdfOk), ['item_ids' => []]), 'exige marcar os exames cobertos', 'Marque');
T::throws(fn() => $svc->uploadByProfessional($rid, $ownerA['user_id'], f('laudo.pdf', $pdfOk), ['item_ids' => [$itG], 'collected_at' => date('Y-m-d', strtotime('+2 days'))]), 'coleta no futuro recusada', 'futuro');
T::throws(fn() => $svc->uploadByProfessional($rid, $ownerA['user_id'], f('laudo.pdf', $pdfOk), ['item_ids' => [$itG], 'collected_at' => '2026-10-20', 'report_issued_at' => '2026-10-19']), 'laudo antes da coleta recusado', 'antes da data da coleta');
T::throws(fn() => $svc->uploadByProfessional($rid, $ownerA['user_id'], f('laudo.pdf', $pdfOk), ['item_ids' => [$itG], 'collected_at' => '20/10/2026']), 'data em formato errado recusada', 'formato');
Fx::act($secA);
T::throws(fn() => $svc->uploadByProfessional($rid, $secA['user_id'], f('laudo.pdf', $pdfOk), ['item_ids' => [$itG]]), 'secretária não anexa laudo', 'Acesso negado');
Fx::act($ownerA);
$d1 = $svc->uploadByProfessional($rid, $ownerA['user_id'], f('Laudo Lab TESTE.pdf', $pdfOk), ['item_ids' => [$itG, $otherItem], 'laboratory_name' => 'Laboratório TESTE', 'collected_at' => '2026-10-20', 'report_issued_at' => '2026-10-22', 'notes' => 'Original do laboratório (TESTE)']);
T::ok($d1['status'] === 'accepted' && (int)$d1['reviewed_by_user_id'] === $ownerA['user_id'], 'anexo da nutricionista entra aceito e revisado por ela');
T::ok(count($d1['items']) === 1 && $d1['items'][0]['exam_request_item_id'] === $itG, 'só exames DESTA solicitação ficam vinculados (item de outra é descartado)');
T::ok($d1['laboratory_name'] === 'Laboratório TESTE' && $d1['collected_at'] === '2026-10-20' && $d1['report_issued_at'] === '2026-10-22' && $d1['original_name'] === 'Laudo Lab TESTE.pdf', 'metadados do laudo guardados');
$abs = $svc->resolve($d1['stored_path']);
T::ok($abs !== null && is_file($abs) && file_get_contents($abs) === $pdfOk && $d1['sha256'] === hash('sha256', $pdfOk), 'original guardado byte a byte, com SHA-256');
T::ok(!str_contains($d1['stored_path'], 'Laudo') && str_starts_with($d1['stored_path'], 'exam_results/' . $cA . '/') && substr(sprintf('%o', fileperms($abs)), -3) === '640', 'nome aleatório, pasta da clínica, permissão 640');
T::ok(is_file(getenv('EXAM_PDF_STORAGE_DIR') . '/exam_results/.htaccess'), 'pasta dos laudos com bloqueio de acesso web');
T::throws(fn() => $svc->uploadByProfessional($rid, $ownerA['user_id'], f('copia.pdf', $pdfOk), ['item_ids' => [$itG]]), 'mesmo arquivo de novo na mesma solicitação é recusado', 'já está anexado');
$cov = $svc->coverageByItem($rid, $cA);
T::ok(($cov[$itG]['accepted'] ?? 0) === 1 && !isset($cov[$itF]), 'situação por exame: glicemia com laudo, ferritina sem');
T::ok($svc->resolve('../../../etc/passwd') === null && $svc->resolve('exam_results/1/x.php') === null, 'caminho fora do padrão não é aberto');

echo "== Paciente envia (aguarda revisão, não vira dado clínico)\n";
TenantContext::set($cA, ['id' => $pA, 'role' => 'patient']);
T::throws(fn() => $svc->uploadByPatient($pA, (int)$rB['id'], f('laudo.jpg', $jpg), []), 'paciente não envia para solicitação de outra paciente', 'não encontrada');
T::throws(fn() => $svc->uploadByPatient($pA, $rid, f('laudo.pdf', $pdfJs), []), 'paciente: PDF com JavaScript recusado', 'JavaScript');
$filesBefore = count(glob(getenv('EXAM_PDF_STORAGE_DIR') . '/exam_results/' . $cA . '/*'));
T::throws(fn() => $svc->uploadByPatient($pA, $rid, f('foto.jpg', $poly), []), 'paciente: imagem com código escondido recusada', 'não permitido');
T::ok(count(glob(getenv('EXAM_PDF_STORAGE_DIR') . '/exam_results/' . $cA . '/*')) === $filesBefore && (int)$pdo->query('SELECT COUNT(*) FROM exam_result_documents')->fetchColumn() === 1, 'arquivo recusado não é guardado nem registrado');
$dp = $svc->uploadByPatient($pA, $rid, f('foto laudo.jpg', $jpg), ['laboratory_name' => 'Lab TESTE', 'collected_at' => '2026-10-21', 'item_ids' => [$itF]]);
T::ok($dp['status'] === 'pending_review' && $dp['uploaded_by_type'] === 'patient' && (int)$dp['uploaded_by_patient_id'] === $pA && $dp['reviewed_by_user_id'] === null, 'envio da paciente fica aguardando revisão');
T::ok(!isset($svc->coverageByItem($rid, $cA)[$itF]), 'aguardando revisão NÃO conta como resultado recebido');
$ov = $svc->patientOverview($pA, $cA);
T::ok(count($ov) === 1 && count($ov[0]['results']) === 2 && !array_key_exists('stored_path', $ov[0]['results'][0]) && !array_key_exists('sha256', $ov[0]['results'][0]), 'app: só as solicitações emitidas dela, sem caminho nem hash');
T::ok(count($svc->patientOverview($pA2, $cA)[0]['results']) === 0, 'app: a outra paciente não vê os laudos dela');
$pdo->prepare("UPDATE exam_result_documents SET created_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$dp['id']]);
for ($i = 0; $i < ExamResultDocumentService::PATIENT_DAILY_LIMIT - 1; $i++) {
    $pdo->prepare("INSERT INTO exam_result_documents (clinic_id, patient_id, exam_request_id, uploaded_by_type, uploaded_by_patient_id, original_name, stored_path, mime_type, size_bytes, sha256, security_check, status, created_at) VALUES (?, ?, ?, 'patient', ?, 'x.jpg', 'x', 'image/jpeg', 1, ?, 'x', 'rejected', ?)")
        ->execute([$cA, $pA, $rid, $pA, str_repeat('0', 64), date('Y-m-d H:i:s')]);
}
T::throws(fn() => $svc->uploadByPatient($pA, $rid, f('foto.jpg', $jpg), []), 'limite diário de envios da paciente', 'Limite');
$pdo->exec("DELETE FROM exam_result_documents WHERE stored_path = 'x'");

echo "== Revisão pela nutricionista\n";
Fx::act($nutriA);
T::throws(fn() => $svc->review((int)$dp['id'], $nutriA['user_id'], true, '', []), 'aceitar exige marcar exames', 'marque quais exames');
T::throws(fn() => $svc->review((int)$dp['id'], $nutriA['user_id'], false, 'x'), 'recusar exige motivo', 'motivo');
Fx::act($ownerB);
T::throws(fn() => $svc->review((int)$dp['id'], $ownerB['user_id'], true, '', [$itF]), 'outra clínica não revisa', 'não encontrado');
Fx::act($nutriA);
$acc = $svc->review((int)$dp['id'], $nutriA['user_id'], true, 'Conferido (TESTE)', [$itF, $otherItem]);
T::ok($acc['status'] === 'accepted' && (int)$acc['reviewed_by_user_id'] === $nutriA['user_id'] && count($acc['items']) === 1, 'aceito com os exames desta solicitação e quem revisou');
T::ok(($svc->coverageByItem($rid, $cA)[$itF]['accepted'] ?? 0) === 1, 'depois de aceito, conta como resultado recebido');
T::throws(fn() => $svc->review((int)$dp['id'], $nutriA['user_id'], false, 'Mudei de ideia'), 'não revisa duas vezes', 'já foi revisado');
TenantContext::set($cA, ['id' => $pA, 'role' => 'patient']);
$dr = $svc->uploadByPatient($pA, $rid, f('outro.png', $png), []);
Fx::act($nutriA);
$rej = $svc->review((int)$dr['id'], $nutriA['user_id'], false, 'Imagem ilegível, envie de novo (TESTE)');
T::ok($rej['status'] === 'rejected' && is_file($svc->resolve($rej['stored_path'])), 'recusado fica guardado (original preservado) com o motivo');
$ovr = array_values(array_filter($svc->patientOverview($pA, $cA)[0]['results'], fn($x) => $x['id'] === (int)$dr['id']));
T::ok($ovr[0]['status'] === 'rejected' && str_contains((string)$ovr[0]['review_note'], 'ilegível'), 'app mostra a recusa com o motivo');

echo "== Correção por nova versão (sem substituir o original)\n";
T::throws(fn() => $svc->newVersion((int)$d1['id'], $nutriA['user_id'], f('laudo2.pdf', $pdfOk), 'x'), 'correção exige motivo', 'mínimo');
T::throws(fn() => $svc->newVersion((int)$dr['id'], $nutriA['user_id'], f('laudo2.pdf', $pdfOk), 'Corrigir TESTE'), 'recusado não tem correção', 'recusado');
$pdf2 = $pdfOk . "\n% retificado TESTE\n%%EOF\n";
$v2 = $svc->newVersion((int)$d1['id'], $nutriA['user_id'], f('Laudo retificado.pdf', $pdf2), 'Laboratório retificou o valor (TESTE)');
$d1b = $svc->get((int)$d1['id'], $cA);
T::ok((int)$v2['version_number'] === 2 && (int)$v2['supersedes_document_id'] === (int)$d1['id'] && (int)$v2['root_document_id'] === (int)$d1['id'], 'versão 2 aponta para a 1');
T::ok($v2['laboratory_name'] === 'Laboratório TESTE' && $v2['collected_at'] === '2026-10-20' && count($v2['items']) === 1, 'versão 2 herda laboratório, datas e exames');
T::ok($d1b['is_superseded'] && (int)$d1b['superseded_by_id'] === (int)$v2['id'] && $d1b['sha256'] === $d1['sha256'] && file_get_contents($svc->resolve($d1b['stored_path'])) === $pdfOk, 'versão 1 continua guardada, intacta, marcada como substituída');
T::ok(($svc->coverageByItem($rid, $cA)[$itG]['accepted'] ?? 0) === 1, 'substituído não conta duas vezes');
T::throws(fn() => $svc->newVersion((int)$d1['id'], $nutriA['user_id'], f('laudo3.pdf', $pdfOk), 'Outra correção TESTE'), 'não corrige versão já substituída', 'já foi substituído');
T::ok($svc->countsByRequest([$rid, (int)$rB['id']], $cA) === [$rid => ['accepted' => 2, 'pending' => 0]], 'contagem por solicitação (vigentes aceitos e aguardando)');

echo "== Abrir e baixar: link que expira, hash conferido, isolamento\n";
$tok = $svc->professionalLink((int)$v2['id'], $nutriA['user_id']);
$vl = ExamResultDocumentService::verifyLink($tok);
T::ok($vl['kind'] === 'p' && $vl['document_id'] === (int)$v2['id'] && $vl['subject_id'] === $nutriA['user_id'] && $vl['clinic_id'] === $cA, 'link da nutricionista amarrado a laudo, usuária e clínica');
T::throws(fn() => ExamResultDocumentService::verifyLink(substr($tok, 0, -2) . 'xx'), 'link adulterado recusado', 'inválido');
$parts = explode('-', $tok); $parts[5] = (string)(time() + 999999); $forged = implode('-', array_slice($parts, 0, 6)) . '-' . $parts[6];
T::throws(fn() => ExamResultDocumentService::verifyLink($forged), 'prazo esticado à mão recusado', 'inválido');
$expBody = 'p-' . $v2['id'] . '-' . $nutriA['user_id'] . '-' . $cA . '-' . (time() - 1);
$expTok = $expBody . '-' . rtrim(strtr(base64_encode(hash_hmac('sha256', $expBody, hash_hmac('sha256', 'clyonutri-laudo-exame-v1', getenv('APP_KEY'), true), true)), '+/', '-_'), '=');
T::throws(fn() => ExamResultDocumentService::verifyLink($expTok), 'link vencido recusado', 'expirou');
$file = $svc->fileFor((int)$v2['id'], $cA, ['type' => 'user', 'id' => $nutriA['user_id']]);
T::ok($file['bytes'] === $pdf2 && $file['mime_type'] === 'application/pdf', 'entrega o original conferido');
T::throws(fn() => $svc->fileFor((int)$v2['id'], $cB, ['type' => 'user', 'id' => $ownerB['user_id']]), 'outra clínica não abre (não encontrado)', 'não encontrado');
T::throws(fn() => $svc->fileFor((int)$v2['id'], $cA, ['type' => 'patient', 'id' => $pA2]), 'outra paciente não abre', 'não encontrado');
T::ok($svc->fileFor((int)$v2['id'], $cA, ['type' => 'patient', 'id' => $pA])['bytes'] === $pdf2, 'a própria paciente abre');
TenantContext::set($cA, ['id' => $pA2, 'role' => 'patient']);
T::throws(fn() => $svc->patientLink((int)$v2['id'], $pA2), 'app: outra paciente não gera link', 'não encontrado');
TenantContext::set($cA, ['id' => $pA, 'role' => 'patient']);
T::ok(ExamResultDocumentService::verifyLink($svc->patientLink((int)$v2['id'], $pA))['kind'] === 'q', 'app: link da paciente');
Fx::act($ownerB);
T::throws(fn() => $svc->professionalLink((int)$v2['id'], $ownerB['user_id']), 'outra clínica não gera link', 'não encontrado');
file_put_contents($svc->resolve($v2['stored_path']), $pdf2 . ' ');
T::throws(fn() => $svc->fileFor((int)$v2['id'], $cA, ['type' => 'user', 'id' => $nutriA['user_id']]), 'arquivo alterado no disco não é entregue', 'não confere');
file_put_contents($svc->resolve($v2['stored_path']), $pdf2);
$chk = (new ExamPdfIntegrityChecker($pdo, new ExamRequestPdfService()))->run();
T::ok(($chk['results_checked'] ?? 0) === 4 && ($chk['results_ok'] ?? 0) === 4, 'conferência de integridade cobre também os laudos');

echo "== Trilha\n";
$acts = $pdo->query("SELECT action FROM exam_audit_logs WHERE entity_type = 'exam_result_documents' AND clinic_id = {$cA}")->fetchAll(PDO::FETCH_COLUMN);
foreach (['EXAM_RESULT_UPLOADED_BY_PROFESSIONAL', 'EXAM_RESULT_UPLOADED_BY_PATIENT', 'EXAM_RESULT_ACCEPTED', 'EXAM_RESULT_REJECTED', 'EXAM_RESULT_NEW_VERSION', 'EXAM_RESULT_OPENED', 'EXAM_RESULT_OPENED_BY_PATIENT', 'EXAM_RESULT_INTEGRITY_FAILED'] as $a) {
    T::ok(in_array($a, $acts, true), "trilha registra {$a}");
}
$all = implode(' ', $pdo->query("SELECT COALESCE(details,'') FROM exam_audit_logs WHERE entity_type = 'exam_result_documents'")->fetchAll(PDO::FETCH_COLUMN));
T::ok(!str_contains($all, 'Laboratório') && !str_contains($all, 'ilegível') && !str_contains($all, 'Laudo'), 'trilha sem texto do laudo, nome de arquivo ou motivo');

T::done();
