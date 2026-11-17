<?php
// NI2-891: revisão do catálogo geral de exames (CSV → importação com revisor/fonte → SQL idempotente),
// aviso só para dona/administradora e some quando tudo está revisado; textos de medidas e do PDF.
// Servidor PHP real + banco SQLite temporário (seed TESTE), sem mocks.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/ni2891-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' base 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE');
require_once $root . '/vendor/autoload.php';
$pdo = new PDO('sqlite:' . $db); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once $root . '/database/migrations/2026_11_17_000100_ni2_891_exam_catalog_review.php';
(new Ni2ExamCatalogReview())->up($pdo); (new Ni2ExamCatalogReview())->up($pdo);
$svc = new \ClyoNutri\Domain\LaboratoryExam\ExamCatalogReviewService($pdo);
$ok($svc->ready(), 'migração 100 idempotente (rodou 2x)');
$pdo->exec("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('u-891', 'Nutri Equipe TESTE 891', 'equipe891@teste.invalid', 'x', 'nutritionist', 1, 'active')");
$staff = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, $staff, 'nutritionist', 'active')");

$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo";
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$jar = tempnam(sys_get_temp_dir(), 'jar');
$req = function (string $path) use ($port, $jar) {
    $c = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c); return [$code, $r];
};
try {
    $st = $svc->stats();
    $ok($st['total'] === 22 && $st['pending'] === 22, "catálogo geral: 22 exames, 22 aguardando ({$st['total']}/{$st['pending']})");
    $req('/__teste_login?u=1&c=1&v=0&to=/exames/catalogo'); [$c, $b] = $req('/exames/catalogo');
    $ok($c === 200 && str_contains($b, 'data-testid="exam-catalog-review-notice"') && str_contains($b, '22 exames do catálogo geral aguardam'), 'dona vê o aviso com a contagem (22)');
    $ok(!str_contains($b, 'licença de uso') && !str_contains($b, 'exame(s)'), 'aviso sem o texto antigo');
    $req("/__teste_login?u=$staff&c=1&v=0&to=/exames/catalogo"); [$c, $b] = $req('/exames/catalogo');
    $ok($c === 200 && !str_contains($b, 'exam-catalog-review-notice'), 'nutricionista da equipe (não gestora) não vê o aviso');

    $csv = $svc->exportCsv();
    $rows = array_values(array_filter(preg_split('/\r?\n/', trim($csv))));
    $ok(str_starts_with($csv, "\xEF\xBB\xBF") && count($rows) === 23 && str_contains($rows[0], 'codigo_interno;exame;material'), 'exportação: BOM + cabeçalho + 22 linhas');

    $sheet = "codigo_interno;sistema;codigo_padrao;justificativa_sem_codigo;fonte\n"
        . "GLICEMIA_JEJUM;TUSS;40302040;;\n"
        . "HEMOGRAMA_COMPLETO;TUSS;40304361;;\n"
        . "SODIO_POTASSIO;;;Painel: na TUSS são dois códigos (sódio e potássio), cadastrados no pedido separadamente;\n"
        . "FERRO_SERICO;TUSS;4030;;\n"
        . "NAO_EXISTE;TUSS;40300000;;\n"
        . "ZINCO_SERICO;;;;\n"
        . "GLICEMIA_JEJUM;TUSS;40302040;;\n";
    $threw = false; try { $svc->importCsv($sheet, 'Fulana sem registro', 'Tabela TESTE'); } catch (InvalidArgumentException $e) { $threw = true; }
    $ok($threw, 'revisor sem CRN é recusado');
    $dry = $svc->importCsv($sheet, 'Revisora TESTE — CRN-3 00000', 'Tabela TUSS TESTE', true);
    $ok($dry['applied'] === 3 && count($dry['rejected']) === 4 && $svc->stats()['reviewed'] === 0, 'simulação: 3 válidas, 4 rejeitadas, nada gravado');
    $r = $svc->importCsv($sheet, 'Revisora TESTE — CRN-3 00000', 'Tabela TUSS TESTE', false, '2026-11-17 10:00:00');
    $why = array_column($r['rejected'], 'reason', 'code');
    $ok($r['applied'] === 3 && str_contains($why['FERRO_SERICO'] ?? '', '8 dígitos') && str_contains($why['NAO_EXISTE'] ?? '', 'não existe')
        && str_contains($why['ZINCO_SERICO'] ?? '', 'sem código e sem justificativa'), 'importação: linhas inválidas rejeitadas sozinhas, com motivo');
    $g = $pdo->query("SELECT standard_code_system, standard_code, clinical_review_status, clinical_reviewed_by, clinical_review_source FROM exam_catalog WHERE code='GLICEMIA_JEJUM' AND clinic_id IS NULL")->fetch(PDO::FETCH_ASSOC);
    $ok($g['standard_code'] === '40302040' && $g['clinical_review_status'] === 'reviewed' && str_contains($g['clinical_reviewed_by'], 'CRN') && $g['clinical_review_source'] === 'Tabela TUSS TESTE', 'revisor, data e fonte gravados');
    $again = $svc->importCsv($sheet, 'Revisora TESTE — CRN-3 00000', 'Tabela TUSS TESTE');
    $ok($again['applied'] === 0 && $again['unchanged'] === 3, 'reimportar a mesma planilha não muda nada');

    $req('/__teste_login?u=1&c=1&v=0&to=/exames/catalogo'); [, $b] = $req('/exames/catalogo');
    $ok(str_contains($b, '19 exames do catálogo geral aguardam') && str_contains($b, 'TUSS 40302040') && str_contains($b, 'Sem código: Painel'), 'tela: contagem 19, código TUSS e justificativa');

    // SQL de produção: aplicado 2x num banco "de produção" sem a revisão → mesmo estado, sem duplicar.
    $sql = $svc->productionSql();
    $ok(substr_count($sql, 'UPDATE exam_catalog') === 3 && str_contains($sql, "WHERE code = 'GLICEMIA_JEJUM' AND clinic_id IS NULL"), 'SQL de produção: 3 UPDATEs por código do catálogo geral');
    $prod = sys_get_temp_dir() . '/ni2891-prod-' . getmypid() . '.sqlite';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($prod) . ' base 2>&1', $o2, $rc2);
    $pp2 = new PDO('sqlite:' . $prod); $pp2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new Ni2ExamCatalogReview())->up($pp2);
    $before = (int)$pp2->query('SELECT COUNT(*) FROM exam_catalog')->fetchColumn();
    $pp2->exec($sql); $snap1 = $pp2->query('SELECT code, standard_code, clinical_review_status, clinical_reviewed_at FROM exam_catalog ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $pp2->exec($sql); $snap2 = $pp2->query('SELECT code, standard_code, clinical_review_status, clinical_reviewed_at FROM exam_catalog ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $ok($snap1 === $snap2 && (int)$pp2->query('SELECT COUNT(*) FROM exam_catalog')->fetchColumn() === $before
        && (int)$pp2->query("SELECT COUNT(*) FROM exam_catalog WHERE clinical_review_status='reviewed'")->fetchColumn() === 3, 'SQL de produção roda 2x: mesmo resultado, sem linha nova');
    unset($pp2); @unlink($prod);

    // Tudo revisado → o aviso some para a dona.
    $all = "codigo_interno;sistema;codigo_padrao;justificativa_sem_codigo\n";
    foreach ($svc->rows() as $x) if ($x['clinical_review_status'] !== 'reviewed') $all .= $x['code'] . ";;;Sem código TUSS confirmado nesta revisão TESTE\n";
    $svc->importCsv($all, 'Revisora TESTE — CRN-3 00000', 'Tabela TUSS TESTE');
    [, $b] = $req('/exames/catalogo');
    $ok($svc->stats()['pending'] === 0 && !str_contains($b, 'exam-catalog-review-notice'), 'catálogo revisado: aviso não aparece');

    // Medidas caseiras: texto diz o que fazer agora, com link para o cadastro.
    [$c, $m] = $req('/medidas-caseiras');
    $ok($c === 200 && !str_contains($m, 'não traz uma base de medidas caseiras pronta') && str_contains($m, 'href="#cm-add"') && str_contains($m, 'data-testid="measures-base-status"'), 'medidas: texto novo com link para cadastrar');
    $ok(\ClyoNutri\Domain\Clinical\WorkflowState::label('issued') === 'Emitido' && !str_contains((string)file_get_contents($root . '/public/assets/js/operational-ui.js'), 'sem assinatura digital'), 'estado "Emitido" sem "sem assinatura digital" (PHP e JS)');
} finally {
    proc_terminate($proc); @unlink($db); @unlink($jar);
}
echo "\nNI2-891 catálogo: " . ($n - $fail) . "/$n\n";
exit($fail ? 1 : 0);
