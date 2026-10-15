<?php

declare(strict_types=1);

// F03 — limitador persistente de tentativas de login (web, API profissional, paciente senha/PIN).
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Core\Security\LoginRateLimiter;

echo "F03 — limitação de tentativas de senha/PIN\n";
$fx = aud_fixture();
$db = $fx['db'];
$db->exec("UPDATE platform_settings SET setting_value = '5' WHERE setting_key = 'security_max_login_attempts'");
$db->prepare("UPDATE patients SET password_hash = ?, access_pin = ? WHERE id = ?")
    ->execute([password_hash('SenhaPaciente@1', PASSWORD_BCRYPT), password_hash('4321', PASSWORD_BCRYPT), $fx['patientA']]);
$resetIp = static fn() => $db->exec("DELETE FROM login_attempts WHERE scope = 'ip'"); // isola cenários (o balde de IP é testado à parte)

// ---------------------------------------------------------------- unidade (relógio controlado)
$now = 1_800_000_000;
$lim = new LoginRateLimiter($db, static function () use (&$now) { return $now; }, 5);
for ($i = 1; $i <= 5; $i++) {
    $r = $lim->attempt('staff', 'Unit@Aud.test ', '10.0.0.1');
    assert($r['allowed'], "tentativa {$i} permitida");
    $lim->failure('staff', 'unit@aud.test', '10.0.0.1');
}
$r = $lim->attempt('staff', 'unit@aud.test', '10.0.0.1');
aud_check(!$r['allowed'] && $r['retry_after'] > 0, 'unidade: 6ª tentativa bloqueada com retry_after');
aud_check(!$lim->attempt('staff', '  UNIT@aud.test', '10.0.0.1')['allowed'], 'unidade: conta normalizada (maiúsculas/espaços) compartilha o contador');
aud_check($lim->attempt('staff', 'unit@aud.test', '10.0.0.2')['allowed'], 'unidade: outro IP tem balde conta+IP próprio');
$now += 61;
aud_check($lim->attempt('staff', 'unit@aud.test', '10.0.0.1')['allowed'], 'unidade: após o bloqueio de 60s, 1 nova tentativa é liberada');
$lim->failure('staff', 'unit@aud.test', '10.0.0.1');
$r = $lim->attempt('staff', 'unit@aud.test', '10.0.0.1');
aud_check(!$r['allowed'] && $r['retry_after'] > 60, 'unidade: bloqueio progressivo (segundo bloqueio maior que 60s)');
$now += LoginRateLimiter::WINDOW_SECONDS + LoginRateLimiter::MAX_LOCK_SECONDS + 1;
$r = $lim->attempt('staff', 'unit@aud.test', '10.0.0.1');
aud_check($r['allowed'], 'unidade: expirada a janela, a conta é desbloqueada (sem bloqueio permanente)');
$lim->success('staff', 'unit@aud.test', '10.0.0.1');
// balde por IP: muitas contas diferentes do mesmo IP
$blockedIp = false;
for ($i = 0; $i < 5 * LoginRateLimiter::IP_MULTIPLIER + 1; $i++) {
    $r = $lim->attempt('staff', "conta{$i}@aud.test", '10.9.9.9');
    if (!$r['allowed']) { $blockedIp = true; break; }
    $lim->failure('staff', "conta{$i}@aud.test", '10.9.9.9');
}
aud_check($blockedIp && $i === 5 * LoginRateLimiter::IP_MULTIPLIER, 'unidade: limite por IP (50) bloqueia rodízio de contas');
$db->exec('DELETE FROM login_attempts');

// ---------------------------------------------------------------- HTTP real (php -S, 4 workers)
$port = 18000 + random_int(0, 900);
$srv = aud_http_server($fx['path'], $port, ['PHP_CLI_SERVER_WORKERS' => '4']);
$base = $srv['base'];
$jar = static fn(): string => tempnam(sys_get_temp_dir(), 'audjar');
$webLogin = static function (string $email, string $password, string $jarFile) use ($base): string {
    [, , $html] = aud_http('GET', "{$base}/login", null, [], $jarFile);
    preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m);
    aud_http('POST', "{$base}/login", http_build_query(['_csrf_token' => $m[1] ?? '', 'email' => $email, 'password' => $password]), [], $jarFile);
    [, , $after] = aud_http('GET', "{$base}/login", null, [], $jarFile);
    if (preg_match('/<div class="alert-error">\s*(.*?)\s*<\/div>/s', $after, $e)) {
        return trim(strip_tags($e[1]));
    }
    [$st, $h] = aud_http('GET', "{$base}/dashboard", null, [], $jarFile);
    return $st === 200 ? 'LOGADO' : "status {$st}";
};

// Web
$j = $jar();
$msgExisting = $webLogin('alfa@aud.test', 'errada', $j);
$msgMissing = $webLogin('ninguem@aud.test', 'errada', $jar());
aud_check($msgExisting === $msgMissing && str_contains($msgExisting, 'E-mail ou senha incorretos.'), "web: mensagem genérica igual para conta existente e inexistente ('{$msgExisting}')");
for ($i = 2; $i <= 5; $i++) {
    $webLogin('alfa@aud.test', 'errada', $j);
}
$msg = $webLogin('alfa@aud.test', 'SenhaAlfa@2026', $j);
aud_check(str_contains($msg, 'Muitas tentativas'), "web: acima do limite até a senha correta é barrada ('{$msg}')");
$msg = $webLogin('alfa@aud.test', 'SenhaAlfa@2026', $jar());
aud_check(str_contains($msg, 'Muitas tentativas'), 'web: nova sessão/cookie não reinicia a contagem');
$db->exec("UPDATE login_attempts SET locked_until = '2000-01-01 00:00:00', window_started_at = '2000-01-01 00:00:00'");
$msg = $webLogin('alfa@aud.test', 'SenhaAlfa@2026', $jar());
aud_check($msg === 'LOGADO', 'web: expirados bloqueio e janela, login correto funciona');
$resetIp();

// API profissional (mesmo realm do web)
$pro = static fn(string $email, string $pw): array => aud_http('POST', "{$base}/api/v1/auth/professional-login", json_encode(['email' => $email, 'password' => $pw]), ['Content-Type: application/json']);
[$s1, , $b1] = $pro('beta@aud.test', 'errada');
[$s2, , $b2] = $pro('naoexiste@aud.test', 'errada');
aud_check($s1 === 401 && $s2 === 401 && json_decode($b1, true)['error'] === json_decode($b2, true)['error'], 'API pro: 401 com mesma mensagem para conta existente e inexistente');
for ($i = 2; $i <= 5; $i++) {
    $pro('beta@aud.test', 'errada');
}
[$s, $h] = $pro('beta@aud.test', 'SenhaBeta@2026');
aud_check($s === 429 && str_contains($h, 'retry-after:'), "API pro: acima do limite -> 429 com Retry-After (mesmo com a senha correta)");
$msg = $webLogin('beta@aud.test', 'SenhaBeta@2026', $jar());
aud_check(str_contains($msg, 'Muitas tentativas'), 'API pro e login web compartilham o contador da conta');
$db->exec("UPDATE login_attempts SET locked_until = '2000-01-01 00:00:00', window_started_at = '2000-01-01 00:00:00'");
[$s] = $pro('beta@aud.test', 'SenhaBeta@2026');
aud_check($s === 200, 'API pro: após expirar, login correto retorna 200');
$resetIp();

// Paciente: senha e PIN alternados compartilham o contador
$pat = static fn(array $body): array => aud_http('POST', "{$base}/api/v1/auth/patient-login", json_encode($body), ['Content-Type: application/json']);
[$sx, , $bx] = $pat(['email' => 'paciente.alfa@aud.test', 'password' => 'errada']);
[$sy, , $by] = $pat(['email' => 'nao.existe@aud.test', 'password' => 'errada']);
aud_check($sx === 401 && $sy === 401 && json_decode($bx, true)['error'] === json_decode($by, true)['error'], 'paciente: 401 genérico igual para conta existente e inexistente');
$pat(['email' => 'paciente.alfa@aud.test', 'pin' => '0000']);
$pat(['email' => 'paciente.alfa@aud.test', 'password' => 'errada2']);
$pat(['email' => 'paciente.alfa@aud.test', 'pin' => '1111']);
$pat(['email' => 'paciente.alfa@aud.test', 'password' => 'errada3']);
[$s] = $pat(['email' => 'paciente.alfa@aud.test', 'pin' => '4321']);
aud_check($s === 429, "paciente: senha/PIN alternados (5 erros) -> 6ª com PIN correto recebe {$s}");
[$s] = $pat(['email' => 'PACIENTE.ALFA@aud.test', 'password' => 'SenhaPaciente@1']);
aud_check($s === 429, 'paciente: senha correta também bloqueada (contador único senha/PIN)');
$db->exec("UPDATE login_attempts SET locked_until = '2000-01-01 00:00:00', window_started_at = '2000-01-01 00:00:00'");
[$s, , $b] = $pat(['email' => 'paciente.alfa@aud.test', 'pin' => '4321']);
aud_check($s === 200 && !empty(json_decode($b, true)['token']), 'paciente: após expirar, PIN correto autentica');
$resetIp();

// Concorrência: 12 tentativas erradas simultâneas não passam do limite de 5 verificações
$db->exec('DELETE FROM login_attempts');
$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 12; $i++) {
    $ch = curl_init("{$base}/api/v1/auth/professional-login");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['email' => 'alfa@aud.test', 'password' => "x{$i}"]), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.2);
} while ($running > 0);
$codes = array_map(static fn($ch) => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
$c401 = count(array_filter($codes, static fn($c) => $c === 401));
$c429 = count(array_filter($codes, static fn($c) => $c === 429));
aud_check($c401 <= 5 && $c401 + $c429 === 12, "concorrência: 12 simultâneas -> {$c401}x401 (≤5) e {$c429}x429");

// Auditoria sem segredos
$rows = $db->query("SELECT action, details FROM audit_logs WHERE action IN ('LOGIN_FAILED','LOGIN_RATE_LIMITED')")->fetchAll();
$blob = json_encode($rows);
aud_check(count($rows) > 0 && str_contains($blob, 'LOGIN_RATE_LIMITED'), 'eventos LOGIN_FAILED/LOGIN_RATE_LIMITED auditados');
aud_check(!preg_match('/errada|SenhaAlfa|SenhaBeta|4321|0000|aud\.test/', $blob), 'auditoria não contém senha, PIN nem e-mail em claro');
$stored = json_encode($db->query('SELECT * FROM login_attempts')->fetchAll());
aud_check(!str_contains($stored, 'aud.test'), 'login_attempts guarda só hash da conta');

echo "F03: OK\n";
