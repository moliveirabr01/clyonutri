<?php
/**
 * v54.76 — NI2-877: bin/integration_check.php (prontidão das integrações) e SMTP que não prende a requisição.
 * Executa a ferramenta real como processo, com .env temporários; o servidor SMTP "travado" é um socket TCP real.
 * Sem rede externa (não usa --asaas-ping/--stun). Dados só de TESTE.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/ni2-877-' . getmypid();
@mkdir($tmp, 0700, true);
$pass = 0; $fail = 0; $fails = [];
$ok = static function (bool $c, string $l) use (&$pass, &$fail, &$fails): void {
    if ($c) { $pass++; echo "  PASS  {$l}\n"; } else { $fail++; $fails[] = $l; echo "  FAIL  {$l}\n"; }
};
$run = static function (string $envBody, array $args, ?callable $whileRunning = null) use ($root, $tmp): array {
    $envFile = $tmp . '/' . bin2hex(random_bytes(4)) . '.env';
    file_put_contents($envFile, $envBody);
    $cmd = array_merge([PHP_BINARY, $root . '/bin/integration_check.php', '--env=' . $envFile, '--json', '--no-report'], $args);
    // Ambiente mínimo: nada herdado do runner (o .env temporário é a única configuração).
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $t0 = microtime(true);
    while (true) {
        $st = proc_get_status($p);
        $out .= (string)stream_get_contents($pipes[1]);
        if ($whileRunning) { $whileRunning(); }
        if (!$st['running']) { $code = $st['exitcode']; break; }
        if (microtime(true) - $t0 > 90) { proc_terminate($p); $code = -1; break; }
        usleep(50000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    proc_close($p);
    $j = json_decode($out, true);
    $items = [];
    foreach (($j['items'] ?? []) as $it) { $items[$it['integration'] . '|' . $it['item']] = $it; }
    return ['code' => $code, 'items' => $items, 'raw' => $out, 'secs' => microtime(true) - $t0];
};

echo "== 1. Produção sem SMTP e sem Asaas: FAIL/BLOCKED claros, nada inventado\n";
$r = $run("APP_ENV=production\nAPP_URL=https://clyonutri.com\nMAIL_DRIVER=log\nASAAS_ENV=sandbox\n", []);
$ok($r['code'] === 1, 'MAIL_DRIVER=log em produção reprova (saída 1)');
$ok(($r['items']['smtp|configuração']['status'] ?? '') === 'FAIL', 'SMTP: FAIL quando nada sai do servidor');
$ok(($r['items']['asaas|ASAAS_API_KEY']['status'] ?? '') === 'BLOCKED', 'Asaas sem chave: BLOCKED (não READY)');
$ok(($r['items']['asaas|ASAAS_WEBHOOK_TOKEN']['status'] ?? '') === 'BLOCKED', 'Webhook sem token: BLOCKED');
$ok(($r['items']['teleconsulta|TURN']['status'] ?? '') === 'WARNING', 'Sem TURN: WARNING (risco em 4G registrado)');
$ok(($r['items']['transcrição|captação de voz']['status'] ?? '') === 'MANUAL', 'Voz: MANUAL (só verificável no navegador)');
$passes = array_filter($r['items'], static fn($i) => $i['status'] === 'PASS');
$ok($passes === [], 'Nenhum PASS sem verificação real');

echo "== 2. Configuração coerente: READY sem expor segredos\n";
$key = '$aact_hmlg_TESTE_' . bin2hex(random_bytes(10));
$tok = bin2hex(random_bytes(20));
$r = $run("APP_ENV=production\nAPP_URL=https://clyonutri.com\nMAIL_DRIVER=smtp\nMAIL_HOST=mail.clyonutri.com\nMAIL_PORT=465\nMAIL_ENCRYPTION=ssl\nMAIL_USERNAME=nao-responda@clyonutri.com\nMAIL_PASSWORD=SenhaTesteNI2877\nMAIL_FROM_ADDRESS=nao-responda@clyonutri.com\nASAAS_ENV=sandbox\nASAAS_API_KEY={$key}\nASAAS_WEBHOOK_TOKEN={$tok}\nTELECONSULT_TURN_URLS=turn:turn.clyonutri.com:3478\nTELECONSULT_TURN_USERNAME=u\nTELECONSULT_TURN_CREDENTIAL=CredTurnNI2877\n", []);
$ok($r['code'] === 0, 'Configuração completa: saída 0');
$ok(($r['items']['smtp|configuração']['status'] ?? '') === 'READY', 'SMTP: READY (falta a chegada, que é MANUAL)');
$ok(($r['items']['asaas|ASAAS_API_KEY']['status'] ?? '') === 'READY' && ($r['items']['asaas|ASAAS_WEBHOOK_TOKEN']['status'] ?? '') === 'READY', 'Asaas: chave e token READY');
$ok(str_contains($r['items']['asaas|URL do webhook']['note'] ?? '', 'https://clyonutri.com/webhooks/asaas'), 'Mostra a URL do webhook a cadastrar no painel');
$ok(!str_contains($r['raw'], $key) && !str_contains($r['raw'], $tok) && !str_contains($r['raw'], 'SenhaTesteNI2877') && !str_contains($r['raw'], 'CredTurnNI2877'), 'Nenhum segredo na saída');

echo "== 3. Asaas incoerente e APP_URL local\n";
$r = $run("APP_ENV=production\nAPP_URL=http://localhost:8080\nMAIL_DRIVER=log\nASAAS_ENV=sandbox\nASAAS_BASE_URL=https://api.asaas.com/v3\nASAAS_API_KEY={$key}\n", []);
$ok(($r['items']['asaas|configuração']['status'] ?? '') === 'FAIL', 'Sandbox apontando para a URL de produção: FAIL');
$ok(($r['items']['geral|APP_URL']['status'] ?? '') === 'FAIL', 'APP_URL http://localhost em produção: FAIL');

echo "== 4. Servidor SMTP que aceita e para de responder (socket TCP real)\n";
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int)substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
stream_set_blocking($srv, false);
$conns = [];
$serve = static function () use ($srv, &$conns): void {
    if ($c = @stream_socket_accept($srv, 0)) { stream_set_blocking($c, false); fwrite($c, "220 teste ESMTP\r\n"); $conns[] = ['c' => $c, 'step' => 0]; }
    foreach ($conns as &$cn) {
        $in = (string)fread($cn['c'], 1000);
        if ($cn['step'] === 0 && str_contains(strtoupper($in), 'EHLO')) { fwrite($cn['c'], "250-teste\r\n250 AUTH LOGIN PLAIN\r\n"); $cn['step'] = 1; }
        // depois disso, silêncio: não responde ao AUTH nem ao QUIT
    }
};
$r = $run("APP_ENV=production\nAPP_URL=https://clyonutri.com\nMAIL_DRIVER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT={$port}\nMAIL_ENCRYPTION=none\nMAIL_USERNAME=u@clyonutri.com\nMAIL_PASSWORD=SenhaTesteNI2877\nMAIL_FROM_ADDRESS=nao-responda@clyonutri.com\nMAIL_TIMEOUT=2\n", ['--smtp-send=destino@clyonutri.test'], $serve);
$ok(($r['items']['smtp|envio']['status'] ?? '') === 'FAIL', 'Envio para servidor travado: FAIL');
$ok($r['secs'] < 20, sprintf('Desiste em %.1f s com MAIL_TIMEOUT=2 (antes: 300 s por resposta)', $r['secs']));
$ok(!str_contains($r['raw'], 'SenhaTesteNI2877'), 'Senha do SMTP não aparece no erro');

echo "\n{$pass} PASS, {$fail} FAIL\n";
foreach ($fails as $f) { echo "  - {$f}\n"; }
exit($fail === 0 ? 0 : 1);
