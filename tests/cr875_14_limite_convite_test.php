<?php
// CR875-14 / NI2-890 (v54.76): links de convite e de nova senha.
//  - 10 links inválidos do mesmo IP em 15 min => 11º recebe 429 (nos dois tipos), sem travar quem tem link válido antes disso;
//  - o tipo do link fica gravado (migração 101): convite não abre tela de nova senha e vice-versa;
//  - aceite real do convite pela tela grava a auditoria TEAM_INVITE_ACCEPTED e mostra sucesso (antes: erro de classe);
//  - convite de equipe da plataforma (console) aponta para /convite/ e funciona.
// Servidor PHP real + SQLite isolado (fixture ni2_433). Sem mocks.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$dir = '/tmp/ni2-433-cr87514-' . getmypid(); @mkdir($dir); $db = "$dir/test.sqlite";
$envArr = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'APP_ENV' => 'production', 'FORCE_HTTPS' => 'false', 'APP_TIMEZONE' => 'America/Sao_Paulo', 'MAIL_DRIVER' => 'null', 'TZ' => 'America/Sao_Paulo'];
$env = implode(' ', array_map(fn($k, $v) => $k . '=' . escapeshellarg($v), array_keys($envArr), $envArr));
exec("cd " . escapeshellarg($root) . " && env $env " . escapeshellarg(PHP_BINARY) . " tests/support/ni2_433_fixture.php prepare 2>&1", $o, $rc);
$ok($rc === 0, 'fixture TESTE (clínica A, Dra. Ana dona)');
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env PHP_CLI_SERVER_WORKERS=4 " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -d session.save_path=" . escapeshellarg($dir) . " -S 127.0.0.1:$port -t public", [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$dir/server.log", 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$mk = function (string $ip = '127.0.0.1') use ($port) {
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    return function (string $path, $data = null) use ($port, $jar) {
        $c = curl_init("http://127.0.0.1:$port$path");
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
        if ($data !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($data)); }
        $raw = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
        return [$code, substr($raw, $hs), substr($raw, 0, $hs)];
    };
};
$csrf = fn(string $h) => preg_match('/name="_csrf_token" value="([^"]+)"/', $h, $m) ? $m[1] : '';
$pdo = new PDO('sqlite:' . $db); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$clearLimiter = fn() => $pdo->exec("DELETE FROM login_attempts");
try {
    $hasCol = false; foreach ($pdo->query('PRAGMA table_info(password_resets)') as $c) { if ($c['name'] === 'purpose') $hasCol = true; }
    $ok($hasCol, 'migração 101: password_resets.purpose existe');
    $purposeOf = function (string $email) use ($pdo, $hasCol) {
        if (!$hasCol) return 'sem-coluna';
        $st = $pdo->prepare('SELECT purpose FROM password_resets WHERE email = ?'); $st->execute([$email]); return $st->fetchColumn();
    };
    // 1) dona convida pela tela de equipe; o link aparece uma vez (sem e-mail)
    $owner = $mk();
    [, $h] = $owner('/login'); $owner('/login', ['_csrf_token' => $csrf($h), 'email' => 'dona.a@example.test', 'password' => 'Agenda@2026']);
    [, $h] = $owner('/configuracoes/equipe');
    [$c] = $owner('/configuracoes/equipe', ['_csrf_token' => $csrf($h), 'name' => 'Bia TESTE Convite', 'email' => 'bia.convite@example.test', 'role' => 'nutritionist', 'crn' => '12345', 'crn_region' => 'CRN-3']);
    [, $h] = $owner('/configuracoes/equipe');
    preg_match('#/convite/([a-f0-9]{64})#', $h, $m); $invite = $m[1] ?? '';
    $ok(strlen($invite) === 64, "dona convida pela tela de equipe (HTTP $c) e vê o link /convite/ uma vez");
    $p = $purposeOf('bia.convite@example.test');
    $ok($p === 'invite', "link gravado com purpose=invite ($p)");
    // 2) atacante: 10 links inválidos de convite => 11º é 429 com Retry-After; nem o link válido abre enquanto travado
    $att = $mk(); $codes = [];
    for ($i = 0; $i < 10; $i++) { [$codes[]] = $att('/convite/' . bin2hex(random_bytes(32))); }
    [$c11, $b11, $h11] = $att('/convite/' . bin2hex(random_bytes(32)));
    $ok(count(array_filter($codes, fn($x) => $x === 429)) === 0, 'as 10 primeiras tentativas inválidas não são bloqueadas (' . implode(',', array_unique($codes)) . ')');
    $ok($c11 === 429 && stripos($h11, 'Retry-After:') !== false && str_contains($b11, 'tentativas'), "11ª tentativa inválida de convite: HTTP $c11 com Retry-After e aviso em português");
    $ok(!str_contains($b11, 'Este convite é inválido') && str_contains($b11, 'team-invite-blocked'), 'tela travada mostra só o aviso de espera (sem o texto de convite inválido abaixo)');
    [$cv] = $att('/convite/' . $invite);
    $ok($cv === 429, "do mesmo IP travado, até o link válido espera (HTTP $cv)");
    $att('/recuperar-senha/' . bin2hex(random_bytes(32)));
    $bucketInv = (int)$pdo->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn();
    $ok($bucketInv >= 1 && !(bool)$pdo->query("SELECT COUNT(*) FROM login_attempts WHERE bucket_key LIKE '%convite%' OR bucket_key LIKE '%127.0.0.1%'")->fetchColumn(), 'contador guardado em login_attempts por chave sha256 (sem IP em claro)');
    // POST com token inválido também conta e também trava
    [, $hx] = $att('/login');
    $before = $pdo->query("SELECT password_hash FROM users WHERE email = 'bia.convite@example.test'")->fetchColumn();
    [$cp, , $hp] = $att('/convite/' . $invite, ['_csrf_token' => $csrf($hx), 'password' => 'Nova@Senha2026', 'password_confirmation' => 'Nova@Senha2026', 'terms_accepted' => '1']);
    $after0 = $pdo->query("SELECT password_hash FROM users WHERE email = 'bia.convite@example.test'")->fetchColumn();
    [$cpg, $bpg] = $att('/convite/' . $invite);
    $ok($cp === 302 && $before === $after0 && $cpg === 429 && str_contains($bpg, 'tentativas'), "POST de aceite com IP travado não cria senha (HTTP $cp → tela com 429 e aviso)");
    // 3) reset: mesmo limite separado
    $clearLimiter();
    [, $b10x] = $att('/convite/' . bin2hex(random_bytes(32)));
    [, $r10x] = $att('/recuperar-senha/' . bin2hex(random_bytes(32)));
    $ok(str_contains($b10x, 'Este convite é inválido') && !str_contains($b10x, 'tentativas') && str_contains($r10x, 'Link inválido ou expirado') && !str_contains($r10x, 'tentativas'), 'link inválido sem trava continua com o texto de link inválido (convite e nova senha)');
    $clearLimiter(); $rcodes = [];
    for ($i = 0; $i < 10; $i++) { [$rcodes[]] = $att('/recuperar-senha/' . bin2hex(random_bytes(32))); }
    [$r11, $rb11] = $att('/recuperar-senha/' . bin2hex(random_bytes(32)));
    $ok(!in_array(429, $rcodes, true) && $r11 === 429 && str_contains($rb11, 'tentativas'), "nova senha: 10 inválidos passam, 11º HTTP $r11");
    $ok(!str_contains($rb11, 'Link inválido ou expirado') && str_contains($rb11, 'reset-blocked'), 'nova senha travada: só o aviso de espera e o botão de pedir novo link (sem o cartão de link inválido)');
    // 4) troca de tipo: convite em /recuperar-senha e reset em /convite não abrem
    $clearLimiter();
    $user = $mk();
    [$cr, $br] = $user('/recuperar-senha/' . $invite);
    $ok(!str_contains($br, 'name="password_confirmation"'), "link de convite em /recuperar-senha não mostra o formulário de senha (HTTP $cr)");
    // reset de dona (ativa) pelo fluxo real "esqueci a senha"
    [, $h] = $user('/recuperar-senha');
    $user('/recuperar-senha', ['_csrf_token' => $csrf($h), 'email' => 'dona.a@example.test']);
    $rp = $purposeOf('dona.a@example.test');
    $ok($rp === 'reset', "pedido de nova senha pela tela grava purpose=reset (" . var_export($rp, true) . ")");
    // token de reset legado (sem purpose) segue regra antiga: ativo => só reset
    $legacy = bin2hex(random_bytes(32));
    $pdo->prepare("DELETE FROM password_resets WHERE email = 'dona.a@example.test'")->execute();
    $pdo->prepare("INSERT INTO password_resets (email, token, created_at, expires_at) VALUES ('dona.a@example.test', ?, ?, ?)")
        ->execute([hash('sha256', $legacy), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + 3600)]);
    [$cl1, $bl1] = $user('/convite/' . $legacy);
    [$cl2, $bl2] = $user('/recuperar-senha/' . $legacy);
    $ok(!str_contains($bl1, 'terms_accepted') && str_contains($bl2, 'name="password_confirmation"'), "link antigo sem tipo (purpose NULL) de conta ativa: /convite recusa, /recuperar-senha abre ($cl1/$cl2)");
    // 5) convidada aceita pela tela real: sucesso, auditoria, entra com a nova senha
    $bia = $mk();
    [$cg, $hg] = $bia('/convite/' . $invite);
    $ok($cg === 200 && str_contains($hg, 'terms_accepted'), "convidada abre o próprio link (HTTP $cg) quando o IP não está travado");
    [$ca, $ba, $ha] = $bia('/convite/' . $invite, ['_csrf_token' => $csrf($hg), 'password' => 'Bia@Convite2026', 'password_confirmation' => 'Bia@Convite2026', 'terms_accepted' => '1']);
    $loc = preg_match('/^Location:\s*(\S+)/mi', $ha, $mm) ? $mm[1] : '';
    $st = $pdo->query("SELECT status FROM users WHERE email = 'bia.convite@example.test'")->fetchColumn();
    $aud = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'TEAM_INVITE_ACCEPTED'")->fetchColumn();
    [, $after] = $bia($loc !== '' ? parse_url($loc, PHP_URL_PATH) . (parse_url($loc, PHP_URL_QUERY) ? '?' . parse_url($loc, PHP_URL_QUERY) : '') : '/login');
    $ok($st === 'active' && $aud === 1 && !str_contains($after . $ba, 'Não foi possível concluir o aceite'), "aceite real: conta ativa, auditoria TEAM_INVITE_ACCEPTED=$aud, sem mensagem de erro (HTTP $ca → $loc)");
    $bia2 = $mk(); [, $h] = $bia2('/login'); [$cl, , $hl] = $bia2('/login', ['_csrf_token' => $csrf($h), 'email' => 'bia.convite@example.test', 'password' => 'Bia@Convite2026']);
    $ok($cl === 302 && !preg_match('#Location:\s*\S*/login#i', $hl), "convidada entra com a senha criada (HTTP $cl)");
    [$cz, $bz] = $mk()('/convite/' . $invite);
    $ok(!str_contains($bz, 'terms_accepted'), 'link de convite já usado não abre de novo');
    // 6) console da plataforma: convite de equipe interna aponta para /convite/ e funciona
    $hash = password_hash('Plataforma@2026', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (uuid, name, email, password_hash, role, is_verified, status) VALUES ('cr87514-sa', 'Super TESTE', 'super.cr87514@example.test', ?, 'superadmin', 1, 'active')")->execute([$hash]);
    $pdo->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) SELECT 1, id, 'admin', 'active' FROM users WHERE email = 'super.cr87514@example.test'");
    $sa = $mk(); [, $h] = $sa('/login'); $sa('/login', ['_csrf_token' => $csrf($h), 'email' => 'super.cr87514@example.test', 'password' => 'Plataforma@2026']);
    [$cf, $hf] = $sa('/admin/usuarios/novo');
    [$cs, $bs] = $sa('/admin/usuarios', ['_csrf_token' => $csrf($hf), 'name' => 'Rafa TESTE Suporte', 'email' => 'rafa.suporte@example.test', 'role' => 'support_agent', 'confirmar' => '1']);
    preg_match('#(/convite/|/recuperar-senha/)([a-f0-9]{64})#', $bs, $mx); $staffPath = $mx[1] ?? ''; $staffTok = $mx[2] ?? '';
    $sp = $purposeOf('rafa.suporte@example.test');
    $ok($cf === 200 && $staffPath === '/convite/' && $sp === 'invite', "console cria usuário interno (HTTP $cs): link $staffPath, purpose=" . var_export($sp, true));
    $rafa = $mk();
    [$cg2, $hg2] = $rafa(($staffPath ?: '/convite/') . $staffTok);
    [$ca2] = $rafa('/convite/' . $staffTok, ['_csrf_token' => $csrf($hg2), 'password' => 'Rafa@Suporte2026', 'password_confirmation' => 'Rafa@Suporte2026', 'terms_accepted' => '1']);
    $st2 = $pdo->query("SELECT status FROM users WHERE email = 'rafa.suporte@example.test'")->fetchColumn();
    $ok($cg2 === 200 && str_contains($hg2, 'terms_accepted') && $st2 === 'active', "usuário interno abre o link e cria a senha (HTTP $cg2/$ca2, status $st2)");
    $log = (string)@file_get_contents("$dir/server.log");
    $ok(!preg_match('/Fatal|Class .* not found|Uncaught/i', $log), 'servidor sem erro fatal no log');
} finally {
    proc_terminate($proc); if (getenv("KEEP")) { echo "dir $dir\n"; } else array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
}
echo "\nCR875-14 limite de links de convite/nova senha: " . ($n - $fail) . "/$n\n";
exit($fail ? 1 : 0);
