<?php
// v54.36: valor "400.00" na baixa (bug R$ 400 -> R$ 40.000), exportação CSV/PDF e recibo (profissional + link do paciente).
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
require $root . '/vendor/autoload.php';
if (!class_exists(\ClyoNutri\Domain\Finance\Money::class)) require $root . '/src/Domain/Finance/Money.php';
use ClyoNutri\Domain\Finance\Money;
foreach (['400' => 40000, '400.00' => 40000, '400,00' => 40000, '1.400,00' => 140000, '1.250,50' => 125050, 'R$ 1.250,50' => 125050,
          '1,400.00' => 140000, '0,01' => 1, '12.5' => 1250, '1.400' => 140000, '' => 0, 'abc' => 0] as $in => $exp) {
    $ok(Money::parseToCents((string)$in) === $exp, "Money::parseToCents('$in') = $exp (" . Money::parseToCents((string)$in) . ')');
}
$db = sys_get_temp_dir() . '/v5436-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' base 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE');
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo";
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$jar = tempnam(sys_get_temp_dir(), 'jar');
$req = function (string $path, ?array $post = null, bool $cookies = true) use ($port, $jar) {
    $c = curl_init("http://127.0.0.1:$port$path");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: text/html'], CURLOPT_HEADER => false];
    if ($cookies) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    curl_setopt_array($c, $o);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE); curl_close($c);
    return [$code, $b, $ct];
};
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/financeiro');
    [$code, $html] = $req('/financeiro');
    $ok($code === 200, "GET /financeiro 200 ($code)");
    $ok(str_contains($html, 'href="/financeiro/exportar/csv"') && str_contains($html, 'href="/financeiro/exportar/pdf"'), 'botões Exportar CSV e Exportar PDF');
    preg_match('#name="_csrf_token" value="([a-f0-9]{64})"#', $html, $m); $tok = $m[1] ?? '';
    $pdo = new PDO('sqlite:' . $db);
    $before = (int)$pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE clinic_id = 1")->fetchColumn();
    $req('/financeiro/recebiveis/novo', ['patient_id' => 2, 'description' => 'TESTE Consulta v54.36', 'amount' => '400', 'due_date' => date('Y-m-d'), '_csrf_token' => $tok]);
    $rid = (int)$pdo->query("SELECT id FROM receivables WHERE description = 'TESTE Consulta v54.36'")->fetchColumn();
    $ok((int)$pdo->query("SELECT amount_cents FROM receivables WHERE id = $rid")->fetchColumn() === 40000, 'lançamento "400" = 40000 centavos');
    // o modal de baixa preenche <input type=number> com "400.00"
    [$code] = $req("/financeiro/recebiveis/$rid/pagar", ['payment_method' => 'pix', 'amount' => '400.00', '_csrf_token' => $tok]);
    $ok($code === 302, "baixa aceita ($code)");
    $pay = $pdo->query("SELECT amount_cents, receipt_code FROM payments WHERE receivable_id = $rid")->fetch(PDO::FETCH_ASSOC);
    $ok((int)($pay['amount_cents'] ?? 0) === 40000, 'baixa "400.00" grava 40000 centavos (não 4000000): ' . ($pay['amount_cents'] ?? 'nada'));
    $ok((string)$pdo->query("SELECT status FROM receivables WHERE id = $rid")->fetchColumn() === 'paid', 'recebível quitado');
    $after = (int)$pdo->query("SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE clinic_id = 1")->fetchColumn();
    $ok($after - $before === 40000, 'soma de pagamentos cresce exatamente R$ 400,00');
    [$code, $html] = $req('/financeiro');
    $code_ = (string)($pay['receipt_code'] ?? '');
    $ok($code_ !== '' && str_contains($html, 'href="/financeiro/recibos/' . $code_ . '"'), 'código do recibo vira link');
    $ok(!str_contains($html, '40.000,00') || $before >= 3960000, 'card não mostra R$ 40.000,00');
    [$code, $csv, $ct] = $req('/financeiro/exportar/csv');
    $ok($code === 200 && str_contains($ct, 'text/csv'), "CSV 200 text/csv ($code $ct)");
    $ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'TESTE Consulta v54.36') && str_contains($csv, '400,00') && str_contains($csv, $code_), 'CSV com BOM, lançamento, valor e recibo');
    [$code, $pdf, $ct] = $req('/financeiro/exportar/pdf');
    $ok($code === 200 && str_starts_with($pdf, '%PDF-'), "PDF do relatório ($code " . strlen($pdf) . ' bytes)');
    [$code, $rh] = $req('/financeiro/recibos/' . $code_);
    $ok($code === 200 && str_contains($rh, 'Recibo de pagamento') && str_contains($rh, 'R$ 400,00') && str_contains($rh, 'PIX'), "recibo do profissional ($code)");
    preg_match('#data-url="([^"]+)"#', $rh, $mm); $link = html_entity_decode($mm[1] ?? '');
    $ok(preg_match('#/recibo/' . preg_quote($code_, '#') . '\?t=[a-f0-9]{40}$#', $link) === 1, 'link do paciente assinado: ' . $link);
    [$code, $pdf] = $req('/financeiro/recibos/' . $code_ . '/pdf');
    $ok($code === 200 && str_starts_with($pdf, '%PDF-'), "PDF do recibo ($code)");
    $path = (string)parse_url($link, PHP_URL_PATH) . '?' . (string)parse_url($link, PHP_URL_QUERY);
    [$code, $ph] = $req($path, null, false);
    $ok($code === 200 && str_contains($ph, 'R$ 400,00') && !str_contains($ph, 'rc-copy'), "paciente abre o recibo sem login ($code)");
    [$code, $pdf] = $req($path . '&pdf=1', null, false);
    $ok($code === 200 && str_starts_with($pdf, '%PDF-'), "paciente baixa PDF ($code)");
    [$code] = $req('/recibo/' . $code_ . '?t=' . str_repeat('a', 40), null, false);
    $ok($code === 404, "token errado = 404 ($code)");
    [$code] = $req('/recibo/' . $code_, null, false);
    $ok($code === 404, "sem token = 404 ($code)");
    [$code, $b] = $req('/financeiro/exportar/csv', null, false);
    $ok($code !== 200 || !str_contains($b, 'TESTE Consulta'), "CSV exige login ($code)");
    // outra clínica não vê o recibo da clínica 1
    $svc = new \ClyoNutri\Domain\Finance\FinanceDocumentService($pdo);
    $ok($svc->receipt(1, $code_) !== null && $svc->receipt(2, $code_) === null, 'serviço: recibo só existe na própria clínica');
    $ok(!str_contains(json_encode($svc->listEntries(2)), 'TESTE Consulta v54.36'), 'serviço: export da clínica 2 não traz lançamento da clínica 1');
    @unlink($jar); touch($jar);
    $req('/__teste_login?u=1&c=999&v=0&to=/financeiro');
    [$code, $b] = $req('/financeiro/recibos/' . $code_);
    $ok(!str_contains($b, 'R$ 400,00'), "recibo não aparece fora da clínica ($code)");
} finally {
    proc_terminate($proc); proc_close($proc); foreach (glob($db . '*') as $f) @unlink($f); @unlink($jar);
}
echo "\n" . ($n - $fail) . " PASS, $fail FAIL\n"; exit($fail ? 1 : 0);
