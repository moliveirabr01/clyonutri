<?php

declare(strict_types=1);

// CSRF — /api/v1/subscription/cancel autenticada por sessão web exige token + Origin (auditoria 14/10/2026).
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

use ClyoNutri\Domain\Subscription\SubscriptionService;

echo "CSRF — API de sessão web\n";
$fx = aud_fixture();
$db = $fx['db'];
(new SubscriptionService($db))->initializeDefaultSubscription($fx['clinicA']);
$status = static fn(): string => (string)$db->query("SELECT status FROM clinic_subscriptions WHERE clinic_id = {$fx['clinicA']}")->fetchColumn();
$initial = $status();
aud_check($initial !== '' && $initial !== 'canceled', "assinatura inicial ativa ({$initial})");

$port = 19000 + random_int(0, 900);
$base = aud_http_server($fx['path'], $port)['base'];
$jar = tempnam(sys_get_temp_dir(), 'audjar');
[, , $html] = aud_http('GET', "{$base}/login", null, [], $jar);
preg_match('/name="_csrf_token" value="([^"]+)"/', $html, $m);
aud_http('POST', "{$base}/login", http_build_query(['_csrf_token' => $m[1], 'email' => 'alfa@aud.test', 'password' => 'SenhaAlfa@2026']), [], $jar);
[$st, , $dash] = aud_http('GET', "{$base}/configuracoes/assinatura", null, [], $jar);
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $dash, $mm);
$token = $mm[1] ?? '';
aud_check($st === 200 && strlen($token) === 64, 'sessão web autenticada e token CSRF exposto via <meta name="csrf-token">');

$cancel = static fn(array $headers, array $body = ['reason_category' => 'price']): array => aud_http('POST', "{$base}/api/v1/subscription/cancel", json_encode($body), array_merge(['Content-Type: application/json'], $headers), $jar);
$origin = "Origin: {$base}";

[$s] = $cancel([$origin]);
aud_check($s === 403 && $status() === $initial, 'sem token -> 403 e assinatura inalterada');
[$s] = $cancel([$origin, 'X-CSRF-Token: ' . str_repeat('a', 64)]);
aud_check($s === 403 && $status() === $initial, 'token inválido -> 403 e assinatura inalterada');
[$s] = $cancel(['Origin: https://evil.example', "X-CSRF-Token: {$token}"]);
aud_check($s === 403 && $status() === $initial, 'token válido + Origin de outro site -> 403');
[$s] = $cancel(["Origin: http://sub.127.0.0.1:{$port}", "X-CSRF-Token: {$token}"]);
aud_check($s === 403 && $status() === $initial, 'token válido + origem same-site não autorizada (subdomínio) -> 403');
[$s] = $cancel(["X-CSRF-Token: {$token}"]);
aud_check($s === 403 && $status() === $initial, 'token válido sem Origin/Referer -> 403');
[$s] = $cancel(['Referer: https://evil.example/x'], ['_csrf_token' => $token]);
aud_check($s === 403 && $status() === $initial, 'token no corpo + Referer estrangeiro -> 403');

[$s, , $b] = $cancel([$origin, "X-CSRF-Token: {$token}"]);
aud_check($s === 200 && $status() === 'canceled', 'token válido + Origin própria -> 200 e assinatura cancelada');

// Fluxos bearer não mudam: logout profissional por bearer sem CSRF
[$s, , $b] = aud_http('POST', "{$base}/api/v1/auth/professional-login", json_encode(['email' => 'beta@aud.test', 'password' => 'SenhaBeta@2026']), ['Content-Type: application/json']);
$bearer = (string)(json_decode($b, true)['token'] ?? '');
[$s] = aud_http('POST', "{$base}/api/v1/auth/professional-logout", '{}', ['Content-Type: application/json', "Authorization: Bearer {$bearer}"]);
aud_check($bearer !== '' && $s === 200, 'API bearer (logout profissional) continua sem exigir CSRF');

echo "CSRF: OK\n";
