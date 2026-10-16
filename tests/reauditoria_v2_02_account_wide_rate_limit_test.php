<?php

declare(strict_types=1);

// Reauditoria V2-02 — contador agregado por conta, independente de IP, com atraso progressivo e nunca permanente.
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Core\Security\LoginRateLimiter;

echo "V2-02 — limite agregado por conta entre IPs\n";
$fx = aud_fixture();
$db = $fx['db'];
$db->exec("UPDATE platform_settings SET setting_value = '5' WHERE setting_key = 'security_max_login_attempts'");
$acctLimit = 5 * LoginRateLimiter::ACCOUNT_MULTIPLIER;
aud_check($acctLimit === 20, 'limite agregado padrão = 20 por janela de 15 min');

$now = 1_800_000_000;
$lim = new LoginRateLimiter($db, static function () use (&$now) { return $now; }, 5);
$ipN = 0;
$newIp = static function () use (&$ipN): string { $ipN++; return '198.51.' . intdiv($ipN, 250) . '.' . ($ipN % 250 + 1); };

// IP conhecido da vítima: login bem-sucedido ANTES do ataque
aud_check($lim->attempt('staff', 'vitima@aud.test', '203.0.113.7')['allowed'], 'vítima entra normalmente do IP de costume');
$lim->success('staff', 'vitima@aud.test', '203.0.113.7');

// Ataque distribuído: 1 tentativa por IP, cada uma de um IP novo
$allowed = 0;
for ($i = 0; $i < 30; $i++) {
    $ip = $newIp();
    $r = $lim->attempt('staff', $i % 2 ? 'VITIMA@aud.test ' : 'vitima@aud.test', $ip);
    if ($r['allowed']) { $allowed++; $lim->failure('staff', 'vitima@aud.test', $ip); }
}
// o login legítimo inicial (de IP ainda desconhecido) também contou 1 no balde agregado
aud_check($allowed === $acctLimit - 1, "IPs diferentes compartilham o limite da conta: {$allowed} de 30 tentativas passaram (+1 login legítimo = 20)");
$r = $lim->attempt('staff', 'vitima@aud.test', $newIp());
aud_check(!$r['allowed'] && $r['retry_after'] > 0 && $r['retry_after'] <= LoginRateLimiter::MAX_LOCK_SECONDS, "conta estourada: atraso temporário (retry_after={$r['retry_after']}s, máx. 15 min)");
aud_check((int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE scope = 'account' AND locked_until IS NOT NULL")->fetchColumn() === 1, 'atraso registrado no balde agregado da conta');

// Contas distintas não são bloqueadas globalmente
aud_check($lim->attempt('staff', 'outra@aud.test', $newIp())['allowed'], 'outra conta, de outro IP, não é afetada');
aud_check($lim->attempt('patient', 'vitima@aud.test', $newIp())['allowed'], 'mesmo identificador em outro realm não é afetado');

// Vítima no IP conhecido continua entrando (proteção contra bloqueio induzido)
$r = $lim->attempt('staff', 'vitima@aud.test', '203.0.113.7');
aud_check($r['allowed'], 'IP conhecido da vítima não é barrado pelo balde agregado');
$lim->success('staff', 'vitima@aud.test', '203.0.113.7');
aud_check(!$lim->attempt('staff', 'vitima@aud.test', $newIp())['allowed'], 'sucesso da vítima NÃO recarrega o orçamento do atacante');
// IP conhecido continua sujeito ao balde conta+IP (5)
$k = 0;
while ($k < 10 && $lim->attempt('staff', 'vitima@aud.test', '203.0.113.7')['allowed']) { $lim->failure('staff', 'vitima@aud.test', '203.0.113.7'); $k++; }
aud_check($k === 5, "IP conhecido continua limitado pelo balde conta+IP ({$k} erros)");

// Progressivo e nunca permanente
$now += LoginRateLimiter::BASE_LOCK_SECONDS + 1;
$r = $lim->attempt('staff', 'vitima@aud.test', $ip1 = $newIp());
aud_check($r['allowed'], 'após o 1º atraso (60s), uma nova tentativa é liberada');
$lim->failure('staff', 'vitima@aud.test', $ip1);
$r = $lim->attempt('staff', 'vitima@aud.test', $newIp());
aud_check(!$r['allowed'] && $r['retry_after'] > LoginRateLimiter::BASE_LOCK_SECONDS, "atraso progressivo: 2º atraso maior que 60s ({$r['retry_after']}s)");
$now += LoginRateLimiter::WINDOW_SECONDS + LoginRateLimiter::MAX_LOCK_SECONDS + 1;
$r = $lim->attempt('staff', 'vitima@aud.test', $ipL = $newIp());
aud_check($r['allowed'], 'expirados janela e atraso, login legítimo de IP novo volta a ser aceito (sem bloqueio permanente)');
$lim->success('staff', 'vitima@aud.test', $ipL);

// Paciente: e-mail, maiúsculas e telefone resolvem para a mesma identidade
$db->prepare('UPDATE patients SET phone = ? WHERE id = ?')->execute(['(11) 98888-7777', $fx['patientA']]);
$idE = LoginRateLimiter::resolvePatientIdentity($db, 'paciente.alfa@aud.test', '');
$idU = LoginRateLimiter::resolvePatientIdentity($db, '  PACIENTE.Alfa@aud.test', '');
$idP = LoginRateLimiter::resolvePatientIdentity($db, '', '11 988887777');
$idP2 = LoginRateLimiter::resolvePatientIdentity($db, '', '(11) 98888-7777');
aud_check($idE === "patient#{$fx['patientA']}" && $idE === $idU && $idE === $idP && $idE === $idP2, 'paciente: e-mail, e-mail em maiúsculas e telefone (formatos diferentes) -> mesma identidade');
aud_check(LoginRateLimiter::resolvePatientIdentity($db, '', 'abc') === 'abc' && LoginRateLimiter::resolvePatientIdentity($db, 'ninguem@aud.test', '') === 'ninguem@aud.test', 'identificador sem correspondência cai no identificador normalizado (sem casar telefone vazio)');
$db->exec('DELETE FROM login_attempts');
$now += 10;
$allowed = 0;
for ($i = 0; $i < 30; $i++) {
    $alias = $i % 2
        ? LoginRateLimiter::resolvePatientIdentity($db, '', $i % 3 ? '11988887777' : '(11) 98888-7777')
        : LoginRateLimiter::resolvePatientIdentity($db, $i % 4 ? 'paciente.alfa@aud.test' : 'PACIENTE.ALFA@AUD.TEST', '');
    $ip = $newIp();
    if ($lim->attempt('patient', $alias, $ip)['allowed']) { $allowed++; $lim->failure('patient', $alias, $ip); }
}
aud_check($allowed === $acctLimit, "paciente: alternar e-mail/telefone entre IPs não zera o limite ({$allowed} de 30)");

// ---------------------------------------------------------------- HTTP real: alternância de alias no mesmo IP
$db->exec('DELETE FROM login_attempts');
$db->prepare('UPDATE patients SET password_hash = ? WHERE id = ?')->execute([password_hash('SenhaPaciente@1', PASSWORD_BCRYPT), $fx['patientA']]);
$port = 18000 + random_int(0, 900);
$srv = aud_http_server($fx['path'], $port);
$pat = static fn(array $b): array => aud_http('POST', "{$srv['base']}/api/v1/auth/patient-login", json_encode($b), ['Content-Type: application/json']);
$codes = [];
foreach ([['email' => 'paciente.alfa@aud.test'], ['phone' => '11988887777'], ['email' => 'PACIENTE.ALFA@aud.test'], ['phone' => '(11) 98888-7777'], ['email' => 'paciente.alfa@aud.test']] as $i => $id) {
    [$codes[]] = $pat($id + ['password' => "errada{$i}"]);
}
[$s6, $h6, $b6] = $pat(['phone' => '11 98888 7777', 'password' => 'SenhaPaciente@1']);
aud_check($codes === [401, 401, 401, 401, 401] && $s6 === 429, 'HTTP: 5 erros alternando e-mail/telefone -> 6ª (senha correta, outro formato) recebe 429');
aud_check(str_contains($h6, 'retry-after:') && !str_contains($b6, 'Alfa') && !str_contains($b6, 'paciente.alfa'), 'HTTP: resposta genérica com Retry-After, sem dados da conta');
[$sx, , $bx] = $pat(['email' => 'nao.existe@aud.test', 'password' => 'x']);
aud_check($sx === 401, 'HTTP: outra conta no mesmo IP não fica bloqueada');
$db->exec("UPDATE login_attempts SET locked_until = '2000-01-01 00:00:00', window_started_at = '2000-01-01 00:00:00'");
[$s7, , $b7] = $pat(['phone' => '(11) 98888-7777', 'password' => 'SenhaPaciente@1']); // formato exato exigido pelo login
aud_check($s7 === 200 && !empty(json_decode($b7, true)['token']), 'HTTP: expirada a janela, login legítimo autentica');
$rows = $db->query('SELECT bucket_key, scope FROM login_attempts')->fetchAll();
aud_check(!array_filter($rows, static fn($r) => str_contains($r['bucket_key'], '@') || !preg_match('/^[0-9a-f]{64}$/', $r['bucket_key'])), 'login_attempts guarda só hashes (inclusive IP conhecido)');

echo "V2-02: OK\n";
