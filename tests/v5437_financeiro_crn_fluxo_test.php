<?php
// v54.37: CRN do nutricionista no recibo; lançamentos de Entrada e Saída (categoria + descrição livre); exportação com Tipo/Categoria.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
require $root . '/vendor/autoload.php';
use ClyoNutri\Domain\Finance\Money;
use ClyoNutri\Domain\Finance\FinanceDocumentService as Doc;
foreach (['400' => 40000, '400.00' => 40000, '1.400,00' => 140000, '89,90' => 8990] as $in => $exp) {
    $ok(Money::parseToCents((string)$in) === $exp, "parser de dinheiro: '$in' = $exp");
}
$ok(Doc::crnLine(['crn' => '12345', 'crn_region' => 'CRN-3']) === 'CRN 12345/3', 'crnLine CRN-3 -> "CRN 12345/3"');
$ok(Doc::crnLine(['crn' => '', 'crn_region' => '']) === 'CRN não informado' && Doc::crnLine(null) === 'CRN não informado', 'sem CRN -> "CRN não informado"');
$db = sys_get_temp_dir() . '/v5437-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' base 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE');
$pdo = new PDO('sqlite:' . $db);
$ok((int)$pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '2026_11_04_000088_finance_entries_expenses'")->fetchColumn() === 1, 'migração 88 registrada');
$cols = array_column($pdo->query('PRAGMA table_info(payments)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$ok(in_array('recorded_by_user_id', $cols, true), 'payments.recorded_by_user_id');
$ok(in_array('category', array_column($pdo->query('PRAGMA table_info(receivables)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true), 'receivables.category');
// migração 88 idempotente
require_once $root . '/database/migrations/2026_11_04_000088_finance_entries_expenses.php';
try { (new FinanceEntriesExpenses())->up($pdo); $ok(true, 'migração 88 roda de novo sem erro'); } catch (Throwable $e) { $ok(false, 'migração 88 repetida: ' . $e->getMessage()); }
// CRN TESTE do usuário 1 (perfil profissional)
$pdo->exec("UPDATE professionals SET crn = '99999', crn_region = 'CRN-3' WHERE user_id = 1");
$userName = (string)$pdo->query('SELECT name FROM users WHERE id = 1')->fetchColumn();

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
    $ok(str_contains($html, 'name="direction" value="entrada"') && str_contains($html, 'name="direction" value="saida"'), 'modal com Tipo Entrada/Saída');
    $ok(str_contains($html, '>Internet<') && str_contains($html, '>Aluguel<') && str_contains($html, '>Software/assinatura<'), 'presets de saída');
    $ok(str_contains($html, 'data-testid="card-balance"') && str_contains($html, 'Saídas pagas'), 'cartões com saídas e saldo');
    preg_match('#name="_csrf_token" value="([a-f0-9]{64})"#', $html, $m); $tok = $m[1] ?? '';
    // Saída: Internet
    [$code] = $req('/financeiro/recebiveis/novo', ['direction' => 'saida', 'expense_category' => 'Internet', 'description' => 'TESTE Internet fibra', 'amount' => '119,90', 'due_date' => date('Y-m-d'), 'already_paid' => '1', '_csrf_token' => $tok]);
    $ex = $pdo->query("SELECT * FROM finance_expenses WHERE description = 'TESTE Internet fibra'")->fetch(PDO::FETCH_ASSOC);
    $ok($code === 302 && $ex && (int)$ex['clinic_id'] === 1 && $ex['category'] === 'Internet' && (int)$ex['amount_cents'] === 11990 && $ex['status'] === 'paid', 'saída Internet gravada (R$ 119,90, paga, clínica 1)');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM receivables WHERE description = 'TESTE Internet fibra'")->fetchColumn() === 0, 'saída não vira recebível');
    // Saída a pagar e depois paga
    $req('/financeiro/recebiveis/novo', ['direction' => 'saida', 'expense_category' => 'Aluguel', 'description' => '', 'amount' => '1.500,00', 'due_date' => date('Y-m-d'), '_csrf_token' => $tok]);
    $al = $pdo->query("SELECT * FROM finance_expenses WHERE category = 'Aluguel' AND clinic_id = 1")->fetch(PDO::FETCH_ASSOC);
    $ok($al && $al['status'] === 'pending' && $al['description'] === 'Aluguel' && (int)$al['amount_cents'] === 150000, 'saída sem "já paga" fica a pagar; descrição vazia = categoria');
    $req('/financeiro/recebiveis/novo', ['direction' => 'saida', 'expense_category' => 'Hackeado', 'description' => 'TESTE x', 'amount' => '10', 'due_date' => date('Y-m-d'), '_csrf_token' => $tok]);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM finance_expenses WHERE description = 'TESTE x'")->fetchColumn() === 0, 'categoria fora da lista recusada');
    // Entrada continua igual (com categoria)
    $req('/financeiro/recebiveis/novo', ['direction' => 'entrada', 'category' => 'Consulta', 'patient_id' => 2, 'description' => 'TESTE Consulta v54.37', 'amount' => '400', 'due_date' => date('Y-m-d'), '_csrf_token' => $tok]);
    $rc_ = $pdo->query("SELECT * FROM receivables WHERE description = 'TESTE Consulta v54.37'")->fetch(PDO::FETCH_ASSOC);
    $ok($rc_ && (int)$rc_['amount_cents'] === 40000 && $rc_['category'] === 'Consulta' && (int)$rc_['patient_id'] === 2, 'entrada com paciente e categoria Consulta');
    $req('/financeiro/recebiveis/novo', ['patient_id' => 2, 'description' => 'TESTE Entrada sem tipo', 'amount' => '50', 'due_date' => date('Y-m-d'), '_csrf_token' => $tok]);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM receivables WHERE description = 'TESTE Entrada sem tipo'")->fetchColumn() === 1, 'POST antigo (sem direction) continua criando entrada');
    $rid = (int)$rc_['id'];
    $req("/financeiro/recebiveis/$rid/pagar", ['payment_method' => 'pix', 'amount' => '400.00', '_csrf_token' => $tok]);
    $pay = $pdo->query("SELECT * FROM payments WHERE receivable_id = $rid")->fetch(PDO::FETCH_ASSOC);
    $ok((int)$pay['amount_cents'] === 40000 && (int)$pay['recorded_by_user_id'] === 1, 'baixa 400.00 = 40000 e guarda quem deu a baixa');
    $code_ = (string)$pay['receipt_code'];
    [$code] = $req('/financeiro/saidas/' . (int)$al['id'] . '/pagar', ['_csrf_token' => $tok]);
    $ok($code === 302 && $pdo->query('SELECT status FROM finance_expenses WHERE id = ' . (int)$al['id'])->fetchColumn() === 'paid', 'saída a pagar marcada como paga');
    [$code, $html] = $req('/financeiro');
    $ok(str_contains($html, 'TESTE Internet fibra') && str_contains($html, 'data-testid="expenses-table"'), 'tabela de saídas na tela');
    preg_match('#data-testid="card-expenses">([^<]+)<#', $html, $ce);
    $ok(trim($ce[1] ?? '') === 'R$ 1.619,90', 'card Saídas pagas = R$ 1.619,90 (' . ($ce[1] ?? '?') . ')');
    // Exportações com Tipo e Categoria
    [$code, $csv] = $req('/financeiro/exportar/csv');
    $lines = explode("\n", $csv);
    $ok(str_contains($lines[0], 'Tipo;Categoria;Descrição'), 'CSV com colunas Tipo e Categoria');
    $ok((bool)preg_grep('/^Saída;Internet;"?TESTE Internet fibra/', $lines) && (bool)preg_grep('/^Entrada;Consulta;"?TESTE Consulta v54\.37/', $lines), 'CSV traz entrada e saída');
    $ok(str_contains($csv, '-119,90'), 'saída sai negativa no CSV');
    [$code, $pdf] = $req('/financeiro/exportar/pdf');
    $ok($code === 200 && str_starts_with($pdf, '%PDF-'), 'PDF do relatório com entradas e saídas');
    $docSvc = new Doc($pdo);
    $body = $docSvc->reportHtml('TESTE', $docSvc->totals(1, ['total_pending_cents' => 0, 'total_paid_cents' => 40000, 'total_count' => 1]), $docSvc->listAll(1));
    $ok(str_contains($body, '<th>Tipo</th><th>Categoria</th>') && str_contains($body, 'TESTE Internet fibra') && str_contains($body, 'TESTE Consulta v54.37'), 'HTML do relatório PDF com Tipo/Categoria e os dois');
    // Recibo com CRN
    [$code, $rh] = $req('/financeiro/recibos/' . $code_);
    $ok($code === 200 && str_contains($rh, 'CRN 99999/3') && str_contains($rh, htmlspecialchars($userName)), "recibo do profissional mostra nome + CRN 99999/3 ($code)");
    preg_match('#data-url="([^"]+)"#', $rh, $mm); $link = html_entity_decode($mm[1] ?? '');
    $path = (string)parse_url($link, PHP_URL_PATH) . '?' . (string)parse_url($link, PHP_URL_QUERY);
    [$code, $ph] = $req($path, null, false);
    $ok($code === 200 && str_contains($ph, 'CRN 99999/3'), "link do paciente mostra o CRN ($code)");
    [$code, $pdf] = $req($path . '&pdf=1', null, false);
    $ok($code === 200 && str_starts_with($pdf, '%PDF-'), 'PDF do recibo do paciente');
    // fallback: pagamento sem autor -> profissional da clínica com CRN; sem profissional -> "CRN não informado"
    $pdo->exec("UPDATE payments SET recorded_by_user_id = NULL WHERE receipt_code = '$code_'");
    \ClyoNutri\Core\Database\SchemaInfo::reset();
    $r = $docSvc->receipt(1, $code_);
    $ok(($r['professional']['crn'] ?? '') === '99999', 'sem autor gravado: usa o profissional da clínica com CRN');
    $pdo->exec("UPDATE professionals SET crn = '', crn_region = '' WHERE user_id = 1");
    $r = $docSvc->receipt(1, $code_);
    $ok(str_contains($docSvc->receiptHtml($r), 'CRN não informado'), 'sem perfil profissional: "CRN não informado"');
    // Isolamento por clínica
    $ok(!str_contains(json_encode($docSvc->listAll(2)), 'TESTE Internet fibra'), 'clínica 2 não vê a saída da clínica 1');
    $ok((new \ClyoNutri\Domain\Finance\ExpenseService($pdo))->summary(2)['paid_cents'] === 0 || !str_contains(json_encode($docSvc->listAll(2)), 'TESTE'), 'resumo da clínica 2 sem saídas TESTE da 1');
    @unlink($jar); touch($jar);
    $req('/__teste_login?u=1&c=2&v=0&to=/financeiro');
    [$code, $h2] = $req('/financeiro');
    preg_match('#name="_csrf_token" value="([a-f0-9]{64})"#', $h2, $m2); $tok2 = $m2[1] ?? '';
    $ok(!str_contains($h2, 'TESTE Internet fibra'), 'tela da clínica 2 não mostra saída da 1');
    $req('/financeiro/saidas/' . (int)$ex['id'] . '/cancelar', ['_csrf_token' => $tok2]);
    $ok($pdo->query('SELECT status FROM finance_expenses WHERE id = ' . (int)$ex['id'])->fetchColumn() === 'paid', 'clínica 2 não cancela saída da clínica 1');
    [$code, $csv2] = $req('/financeiro/exportar/csv');
    $ok(!str_contains($csv2, 'TESTE Internet fibra'), 'CSV da clínica 2 sem saída da 1');
} finally {
    proc_terminate($proc); proc_close($proc); foreach (glob($db . '*') as $f) @unlink($f); @unlink($jar);
}
echo "\n" . ($n - $fail) . " PASS, $fail FAIL\n"; exit($fail ? 1 : 0);
