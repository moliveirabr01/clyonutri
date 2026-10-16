<?php
// Sobe o front controller real em `php -S` com variáveis de ambiente controladas (testes NI2-426).
// Uso: ni2426_server(array $env): array{base:string, stop:callable}
function ni2426_server(array $env, int $port): array
{
    $root = realpath(__DIR__ . '/../..');
    $env += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root . '/storage/testing.sqlite'];
    $envStr = '';
    foreach ($env as $k => $v) { $envStr .= escapeshellarg("$k=$v") . ' '; }
    $log = sys_get_temp_dir() . "/ni2426_srv_$port.log";
    $cmd = "cd " . escapeshellarg($root) . " && env $envStr php -S 127.0.0.1:$port -t public public/index.php > " . escapeshellarg($log) . " 2>&1 & echo $!";
    $pid = (int)trim((string)shell_exec($cmd));
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
    return ['base' => "http://127.0.0.1:$port", 'stop' => function () use ($pid) { if ($pid > 0) { exec("kill $pid 2>/dev/null"); exec("pkill -P $pid 2>/dev/null"); } }];
}
/** Requisição crua; retorna [status, headers(lowercase=>list), body]. */
function ni2426_req(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers, CURLOPT_NOBODY => $method === 'HEAD']);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $h = [];
    foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))][] = trim($v); }
    }
    return [$status, $h, substr($raw, $hs)];
}
