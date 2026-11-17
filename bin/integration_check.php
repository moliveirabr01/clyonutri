<?php

declare(strict_types=1);

/**
 * NI2-877 — Verificação das integrações reais (Asaas, SMTP, teleconsulta, voz da transcrição).
 *
 * Não substitui o roteiro de homologação: ajuda a executá-lo e a coletar a evidência.
 * Nunca imprime segredos; nunca cria cobrança; não envia nada sem --smtp-send.
 *
 * Uso (Terminal do cPanel ou local):
 *   php bin/integration_check.php                       # prontidão da configuração das 4 integrações
 *   php bin/integration_check.php --asaas-ping           # GET /myAccount no Asaas (só leitura; exige ASAAS_API_KEY)
 *   php bin/integration_check.php --stun                 # pedido STUN real aos servidores configurados (UDP)
 *   php bin/integration_check.php --smtp-send=voce@x.com # envia 1 mensagem de teste pelo SMTP do .env
 *   php bin/integration_check.php --evidence=CLINIC_ID   # consulta ao banco do roteiro (Asaas + transcrição), sem dados pessoais
 *   --json                  saída JSON (para anexar à NI2-877)
 *   --report=caminho.json   grava o relatório (padrão: storage/integration_validation/<data>.json)
 *   --env=caminho           arquivo .env alternativo (padrão: .env da raiz)
 *
 * Status por item: PASS (verificado agora, de verdade), READY (configurado; falta o teste manual),
 * WARNING, FAIL, BLOCKED (falta credencial/serviço/aparelho), MANUAL (só verificável por uma pessoa).
 * Código de saída: 1 se algum item der FAIL; 0 nos demais casos (BLOCKED não é aprovação).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Mail\MailMessage;
use ClyoNutri\Core\Mail\MailerFactory;
use ClyoNutri\Core\Security\Env;
use ClyoNutri\Domain\Billing\AsaasConfig;

$opts = getopt('', ['json', 'asaas-ping', 'stun', 'smtp-send:', 'evidence:', 'report:', 'env:', 'no-report']);
$envFile = (string)($opts['env'] ?? (__DIR__ . '/../.env'));
if (is_file($envFile)) {
    Env::load($envFile);
}
date_default_timezone_set((string)(Env::get('APP_TIMEZONE') ?? 'America/Sao_Paulo'));

$env = static function (string $k, string $d = ''): string {
    $v = Env::get($k);
    return ($v === null || trim((string)$v) === '') ? $d : trim((string)$v);
};
$redact = static function (string $msg) use ($env): string {
    foreach (['ASAAS_API_KEY', 'ASAAS_WEBHOOK_TOKEN', 'MAIL_PASSWORD', 'DB_PASSWORD', 'TELECONSULT_TURN_CREDENTIAL', 'DAILY_API_KEY', 'APP_KEY'] as $k) {
        $s = $env($k);
        if (strlen($s) >= 3) {
            $msg = str_replace($s, '[REDACTED]', $msg);
        }
    }
    return $msg;
};

$report = [
    'tool' => 'bin/integration_check.php (NI2-877)',
    'generated_at' => date('c'),
    'app_env' => $env('APP_ENV', 'production'),
    'app_url' => $env('APP_URL'),
    'items' => [],
];
$add = static function (string $integration, string $item, string $status, string $note, array $data = []) use (&$report): void {
    $report['items'][] = ['integration' => $integration, 'item' => $item, 'status' => $status, 'note' => $note] + ($data ? ['data' => $data] : []);
};

// ---------- 0. URL pública (links de e-mail, retorno do checkout, webhook) ----------
$appUrl = rtrim($env('APP_URL'), '/');
$appHost = (string)parse_url($appUrl, PHP_URL_HOST);
if ($appUrl === '' || !str_starts_with($appUrl, 'https://') || in_array($appHost, ['localhost', '127.0.0.1', ''], true)) {
    $add('geral', 'APP_URL', $report['app_env'] === 'production' ? 'FAIL' : 'WARNING',
        'APP_URL precisa ser https:// com o domínio público (links dos e-mails, retorno do Asaas e webhook usam esse valor).', ['app_url' => $appUrl]);
} else {
    $add('geral', 'APP_URL', 'READY', "Links e webhook usarão {$appUrl}.");
}

// ---------- 1. Asaas ----------
try {
    $asaas = AsaasConfig::fromEnv();
    $asaas->assertCoherent();
    $add('asaas', 'ambiente', 'READY', "ASAAS_ENV={$asaas->env}; host " . parse_url($asaas->baseUrl, PHP_URL_HOST) . '.');
    if (!$asaas->isSandbox()) {
        $add('asaas', 'checkout', 'WARNING', 'O checkout desta versão só abre com ASAAS_ENV=sandbox (homologação). Em produção o botão fica indisponível.');
    }
    $add('asaas', 'ASAAS_API_KEY', $asaas->hasApiKey() ? 'READY' : 'BLOCKED',
        $asaas->hasApiKey() ? 'Chave presente (não exibida).' : 'Sem chave do sandbox: checkout real não pode ser criado.');
    $add('asaas', 'ASAAS_WEBHOOK_TOKEN', $asaas->hasWebhookToken() ? 'READY' : 'BLOCKED',
        $asaas->hasWebhookToken() ? 'Token com 32+ caracteres (não exibido).' : 'Token ausente ou curto (mínimo 32): o webhook responde 401 a tudo.');
    if ($appUrl !== '') {
        $add('asaas', 'URL do webhook', 'MANUAL', "Cadastrar no painel do Asaas: {$appUrl}/webhooks/asaas (POST), com o mesmo token. Eventos: PAYMENT_CONFIRMED, PAYMENT_RECEIVED, PAYMENT_OVERDUE, PAYMENT_REFUNDED, CHECKOUT_*.");
    }
    if (isset($opts['asaas-ping'])) {
        if (!$asaas->hasApiKey()) {
            // Mesmo sem chave, confirma que o servidor alcança o host (401 = alcançável).
            $code = 0;
            if (function_exists('curl_init')) {
                $ch = curl_init($asaas->baseUrl . '/myAccount');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
                curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
            }
            $add('asaas', 'ping', 'BLOCKED', $code > 0
                ? "Host do Asaas alcançável (HTTP {$code} sem chave), mas sem ASAAS_API_KEY não há como autenticar."
                : 'Sem ASAAS_API_KEY e sem resposta do host do Asaas.', ['http_status_without_key' => $code]);
        } else {
            $ch = curl_init($asaas->baseUrl . '/myAccount');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['access_token: ' . $asaas->apiKey, 'Accept: application/json', 'User-Agent: ClyoNutri/NI2-877 integration_check'],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0) {
                $add('asaas', 'ping', 'FAIL', "Sem comunicação com o Asaas (cURL {$errno}).");
            } elseif ($code === 200) {
                $acc = json_decode((string)$body, true);
                $add('asaas', 'ping', 'PASS', 'Chave aceita pelo Asaas (GET /myAccount 200).', ['account_status' => is_array($acc) ? ($acc['status'] ?? null) : null]);
            } else {
                $add('asaas', 'ping', 'FAIL', "Asaas recusou a chave (HTTP {$code}). Confira se é a chave do mesmo ambiente de ASAAS_ENV.");
            }
        }
    }
} catch (\Throwable $e) {
    $add('asaas', 'configuração', 'FAIL', $redact($e->getMessage()));
}
$add('asaas', 'checkout + pagamento + webhook', 'MANUAL', 'Roteiro NI2-877 §1: escolher plano em Configurações › Assinatura, pagar no sandbox (aprovado, recusado e cancelamento) e depois rodar --evidence=<clínica>.');

// ---------- 2. SMTP ----------
$mail = MailerFactory::describe();
if ($mail['problems']) {
    $add('smtp', 'configuração', 'FAIL', implode('; ', $mail['problems']), ['driver' => $mail['driver']]);
} elseif (!$mail['delivers']) {
    $add('smtp', 'configuração', $report['app_env'] === 'production' ? 'FAIL' : 'BLOCKED',
        "MAIL_DRIVER={$mail['driver']}: nenhuma mensagem sai do servidor (vai para o log).", ['driver' => $mail['driver']]);
} else {
    $add('smtp', 'configuração', 'READY', "driver={$mail['driver']} host={$mail['host']} porta={$mail['port']} criptografia=" . ($mail['encryption'] ?: 'nenhuma') . " remetente={$mail['from']}.");
    $fromDomain = strtolower((string)substr((string)strrchr((string)$mail['from'], '@'), 1));
    if ($appHost !== '' && $fromDomain !== '' && !str_ends_with($appHost, $fromDomain) && !str_ends_with($fromDomain, $appHost)) {
        $add('smtp', 'remetente', 'WARNING', "Remetente @{$fromDomain} difere do domínio do sistema ({$appHost}): maior chance de cair no spam (SPF/DKIM).");
    }
}
if (isset($opts['smtp-send'])) {
    $to = trim((string)$opts['smtp-send']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $add('smtp', 'envio', 'FAIL', 'Destinatário inválido em --smtp-send.');
    } elseif (!$mail['delivers'] || $mail['problems']) {
        $add('smtp', 'envio', 'BLOCKED', 'SMTP não configurado; nada enviado.');
    } else {
        $token = 'NI2-877-' . bin2hex(random_bytes(4));
        try {
            $t0 = microtime(true);
            MailerFactory::fromEnv()->send(new MailMessage(
                $to,
                '',
                "Teste de integração Clyo Nutri — {$token}",
                '<p>Teste de integração do <strong>Clyo Nutri</strong> (NI2-877).</p><p>Acentuação: ação, atenção, nutrição, ç ã é ô.</p><p>Link: <a href="' . htmlspecialchars($appUrl . '/login', ENT_QUOTES) . '">' . htmlspecialchars($appUrl . '/login', ENT_QUOTES) . '</a></p><p>Código: ' . $token . '</p>',
                "Teste de integração do Clyo Nutri (NI2-877).\nAcentuação: ação, atenção, nutrição, ç ã é ô.\nLink: {$appUrl}/login\nCódigo: {$token}\n"
            ));
            $add('smtp', 'envio', 'READY', sprintf('Servidor SMTP aceitou a mensagem em %.1f s. Falta conferir a chegada (e o spam) em %s procurando o código %s.', microtime(true) - $t0, $to, $token), ['token' => $token]);
        } catch (\Throwable $e) {
            $add('smtp', 'envio', 'FAIL', 'Servidor recusou ou não respondeu: ' . $redact($e->getMessage()));
        }
    }
}
$add('smtp', 'chegada na caixa', 'MANUAL', 'Roteiro NI2-877 §2: convite do app, nova senha (profissional e paciente), lembrete (cron), OTP do aceite de transcrição e confirmação do agendamento online.');

// ---------- 3. Teleconsulta ----------
$provider = $env('TELECONSULT_PROVIDER', 'webrtc_p2p');
if ($provider === 'daily_co') {
    $ok = $env('DAILY_API_KEY') !== '' && $env('DAILY_DOMAIN') !== '';
    $add('teleconsulta', 'provedor', $ok ? 'READY' : 'BLOCKED', $ok ? 'Daily configurado (chave não exibida).' : 'TELECONSULT_PROVIDER=daily_co sem DAILY_API_KEY/DAILY_DOMAIN.');
} else {
    $stun = array_values(array_filter(array_map('trim', explode(',', $env('TELECONSULT_STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'))), static fn($u) => str_starts_with($u, 'stun:')));
    $turn = $env('TELECONSULT_TURN_URLS');
    $add('teleconsulta', 'provedor', 'READY', 'WebRTC próprio (webrtc_p2p) com sinalização em /teleconsulta/sinal/{sala}; ' . count($stun) . ' servidor(es) STUN.');
    $add('teleconsulta', 'TURN', $turn !== '' ? 'READY' : 'WARNING', $turn !== ''
        ? 'TURN configurado (credencial não exibida).'
        : 'Sem TURN: em 4G/CGNAT ou rede corporativa a chamada pode não conectar. Registrar no teste em 4G.');
    if (isset($opts['stun'])) {
        foreach ($stun as $url) {
            $hp = substr($url, 5);
            [$h, $p] = array_pad(explode(':', $hp, 2), 2, '3478');
            $res = stunBinding($h, (int)$p);
            // O servidor não fica no caminho da mídia: sem resposta aqui (UDP bloqueado na hospedagem) não prova falha no navegador.
            $add('teleconsulta', "STUN {$hp}", $res['ok'] ? 'PASS' : 'WARNING', $res['ok']
                ? "Resposta STUN real em {$res['ms']} ms (endereço público visto: {$res['mapped']})."
                : "Sem resposta STUN deste servidor ({$res['error']}). Pode ser só UDP bloqueado na hospedagem; a prova é a chamada entre aparelhos.", ['ms' => $res['ms'] ?? null]);
        }
    }
}
$add('teleconsulta', 'chamada entre dois aparelhos', 'MANUAL', 'Roteiro NI2-877 §3: redes diferentes (uma em 4G), áudio e vídeo nos dois sentidos, admissão e encerramento.');

// ---------- 4. Voz da transcrição ----------
$add('transcrição', 'captação de voz', 'MANUAL', 'A voz é captada no navegador (Web Speech API). O servidor não participa: testar em Chrome com microfone (roteiro §4). Firefox/Safari devem mostrar "transcrição indisponível" sem gravar texto.');

// ---------- 5. Evidência no banco ----------
if (isset($opts['evidence'])) {
    $clinicId = (int)$opts['evidence'];
    try {
        $db = \ClyoNutri\Core\Database\Connection::getInstance();
        $q = static function (string $sql, array $p = []) use ($db): array {
            $st = $db->prepare($sql);
            $st->execute($p);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        };
        $ev = [
            'clinic_id' => $clinicId,
            'asaas_checkouts' => $q('SELECT id, plan_id, status, billing_cycle, amount_cents, created_at FROM asaas_checkouts WHERE clinic_id = ? ORDER BY id DESC LIMIT 10', [$clinicId]),
            'asaas_webhook_events' => $q('SELECT id, event_type, processing_status, received_at FROM asaas_webhook_events WHERE clinic_id = ? ORDER BY id DESC LIMIT 20', [$clinicId]),
            'clinic_subscriptions' => $q('SELECT id, plan_id, status, billing_cycle, current_period_end FROM clinic_subscriptions WHERE clinic_id = ?', [$clinicId]),
            'subscription_invoices' => $q('SELECT id, status, amount_cents, payment_method, paid_at FROM subscription_invoices WHERE clinic_id = ? ORDER BY id DESC LIMIT 10', [$clinicId]),
            'asaas_payment_receipts' => $q('SELECT id, invoice_id, event_type, amount_cents, paid_at FROM asaas_payment_receipts WHERE clinic_id = ? ORDER BY id DESC LIMIT 10', [$clinicId]),
            'transcricao_ultimas_sessoes' => $q('SELECT s.id, s.status, s.started_at_utc, (SELECT COUNT(*) FROM consultation_transcription_segments g WHERE g.session_id = s.id) AS segmentos FROM consultation_transcription_sessions s WHERE s.clinic_id = ? ORDER BY s.id DESC LIMIT 5', [$clinicId]),
        ];
        $paidInvoices = count(array_filter($ev['subscription_invoices'], static fn($r) => ($r['paid_at'] ?? null) !== null));
        $confirmed = count(array_filter($ev['asaas_webhook_events'], static fn($r) => in_array($r['event_type'], ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true) && $r['processing_status'] === 'processed'));
        $active = count(array_filter($ev['clinic_subscriptions'], static fn($r) => $r['status'] === 'active'));
        $status = ($ev['asaas_checkouts'] && $confirmed > 0 && $active > 0 && $paidInvoices > 0 && $ev['asaas_payment_receipts']) ? 'PASS' : 'BLOCKED';
        $add('asaas', "evidência no banco (clínica {$clinicId})", $status, $status === 'PASS'
            ? "Checkout criado pelo sistema, {$confirmed} pagamento(s) confirmado(s) por webhook, assinatura ativa, fatura paga e recibo registrados."
            : 'Cadeia incompleta: checkout ' . count($ev['asaas_checkouts']) . ', webhooks confirmados ' . $confirmed . ', assinatura ativa ' . $active . ', faturas pagas ' . $paidInvoices . ', recibos ' . count($ev['asaas_payment_receipts']) . '.', $ev);
    } catch (\Throwable $e) {
        $add('asaas', 'evidência no banco', 'FAIL', 'Consulta falhou: ' . $redact($e->getMessage()));
    }
}

// ---------- Saída ----------
$counts = array_count_values(array_column($report['items'], 'status'));
$report['summary'] = $counts;
if (!isset($opts['no-report'])) {
    $path = (string)($opts['report'] ?? (__DIR__ . '/../storage/integration_validation/' . date('Ymd-His') . '.json'));
    @mkdir(dirname($path), 0750, true);
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $report['report_file'] = $path;
}
if (isset($opts['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo "== Integrações reais (NI2-877) — " . $report['generated_at'] . " ==\n";
    foreach ($report['items'] as $it) {
        printf("[%-7s] %s › %s: %s\n", $it['status'], $it['integration'], $it['item'], $it['note']);
    }
    echo "\nResumo: " . implode(', ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($counts), $counts)) . "\n";
    if (isset($report['report_file'])) {
        echo "Relatório: {$report['report_file']}\n";
    }
}
exit(isset($counts['FAIL']) ? 1 : 0);

/** Pedido STUN Binding (RFC 5389) por UDP. @return array{ok:bool,ms?:int,mapped?:string,error?:string} */
function stunBinding(string $host, int $port): array
{
    $ip = gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        return ['ok' => false, 'error' => 'DNS não resolveu'];
    }
    $sock = @stream_socket_client("udp://{$ip}:{$port}", $errno, $errstr, 5);
    if (!$sock) {
        return ['ok' => false, 'error' => "socket: {$errstr}"];
    }
    stream_set_timeout($sock, 4);
    $tid = random_bytes(12);
    $t0 = microtime(true);
    fwrite($sock, pack('nnN', 0x0001, 0, 0x2112A442) . $tid);
    $resp = fread($sock, 512);
    fclose($sock);
    if ($resp === false || strlen($resp) < 20) {
        return ['ok' => false, 'error' => 'tempo esgotado'];
    }
    $hdr = unpack('ntype/nlen/Ncookie', substr($resp, 0, 8));
    if ($hdr['type'] !== 0x0101 || substr($resp, 8, 12) !== $tid) {
        return ['ok' => false, 'error' => 'resposta inválida'];
    }
    $mapped = '?';
    $off = 20;
    while ($off + 4 <= strlen($resp)) {
        $a = unpack('ntype/nlen', substr($resp, $off, 4));
        $val = substr($resp, $off + 4, $a['len']);
        if ($a['type'] === 0x0020 && strlen($val) >= 8) { // XOR-MAPPED-ADDRESS (IPv4)
            $xport = unpack('n', substr($val, 2, 2))[1] ^ 0x2112;
            $xip = unpack('N', substr($val, 4, 4))[1] ^ 0x2112A442;
            $mapped = long2ip($xip) . ':' . $xport;
            break;
        }
        $off += 4 + $a['len'] + ((4 - $a['len'] % 4) % 4);
    }
    return ['ok' => true, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'mapped' => $mapped];
}
