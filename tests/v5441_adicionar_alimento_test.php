<?php
// v54.41: Base de Alimentos > Adicionar alimento: cria alimento próprio da clínica, aparece na lista e na busca
// do plano, entra num plano alimentar e não vaza para outra clínica. Dados TESTE.
$root = dirname(__DIR__); $fail = 0; $n = 0;
$ok = function ($c, $m) use (&$fail, &$n) { $n++; echo ($c ? '  PASS  ' : '  FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$db = sys_get_temp_dir() . '/v5441-' . getmypid() . '.sqlite';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/e2e/lib/seed.php") . ' ' . escapeshellarg($db) . ' base 2>&1', $o, $rc);
$ok($rc === 0, 'seed TESTE');
$pdo = new PDO('sqlite:' . $db);
$s = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s);
$env = "DB_CONNECTION=sqlite DB_DATABASE=" . escapeshellarg($db) . " APP_ENV=local APP_URL=http://127.0.0.1:$port APP_KEY=base64:dGVzdGVrZXl0ZXN0ZWtleXRlc3Rla2V5dGVzdGVrZXk= MAIL_DRIVER=log APP_TIMEZONE=America/Sao_Paulo";
$proc = proc_open("cd " . escapeshellarg($root) . " && exec env $env " . escapeshellarg(PHP_BINARY) . " -d opcache.enable=0 -S 127.0.0.1:$port -t public tests/_v54_router.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$jar = tempnam(sys_get_temp_dir(), 'jar');
$req = function (string $path, ?array $post = null) use ($port, $jar) {
    $c = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post !== null) { curl_setopt($c, CURLOPT_POST, true); curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
    return [$code, substr($r, $hs), substr($r, 0, $hs)];
};
$tok = function (string $h) { return preg_match('#name="_csrf_token" value="([^"]+)"#', $h, $t) ? $t[1] : ''; };
$loc = fn(string $hdr) => preg_match('#Location: (\S+)#i', $hdr, $m) ? $m[1] : '';
$food = ['name' => 'Pão de queijo TESTE da clínica', 'category' => 'Personalizado', 'serving_size_g' => '50', 'serving_unit' => 'unidade',
    'energy_kcal' => '363,5', 'protein_g' => '5.1', 'carbohydrate_g' => '34,2', 'lipid_g' => '24', 'fiber_g' => '0,6', 'allergens' => 'leite'];
try {
    $req('/__teste_login?u=1&c=1&v=0&to=/alimentos');
    [$code, $h] = $req('/alimentos');
    $ok($code === 200 && str_contains($h, 'data-testid="link-food-create"') && str_contains($h, 'Adicionar alimento'), 'botão "Adicionar alimento" em /alimentos');
    [$code, $h] = $req('/alimentos/novo');
    $ok($code === 200 && str_contains($h, 'data-testid="food-create-form"') && str_contains($h, 'Composição em 100 g'), "formulário abre ($code)");
    foreach (['name', 'serving_size_g', 'serving_unit', 'energy_kcal', 'protein_g', 'carbohydrate_g', 'lipid_g', 'fiber_g', 'allergens', 'category'] as $k) { if (!str_contains($h, 'name="' . $k . '"')) { $ok(false, "campo $k"); } }
    $t = $tok($h);
    [$code, , $hdr] = $req('/alimentos/novo', ['name' => '  ', '_csrf_token' => $t] + $food);
    [, $h2] = $req('/alimentos/novo');
    $ok($code === 302 && str_contains($h2, 'Informe o nome do alimento') && str_contains($h2, 'value="363,5"'), 'sem nome: volta ao formulário com erro e mantém os valores');
    $req('/alimentos/novo', ['_csrf_token' => $t, 'protein_g' => '60', 'carbohydrate_g' => '50'] + $food);
    [, $h2] = $req('/alimentos/novo');
    $ok(str_contains($h2, 'passam de 100 g'), 'macros acima de 100 g em 100 g recusados');
    $req('/alimentos/novo', ['_csrf_token' => $t, 'serving_unit' => 'balde'] + $food);
    [, $h2] = $req('/alimentos/novo');
    $ok(str_contains($h2, 'unidade da lista'), 'unidade fora da lista recusada');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM foods WHERE is_custom = 1")->fetchColumn() === 0, 'nada gravado com dados inválidos');
    [$code, , $hdr] = $req('/alimentos/novo', ['_csrf_token' => $t] + $food);
    $to = $loc($hdr);
    $ok($code === 302 && str_starts_with($to, '/alimentos?source=clinic'), "salvar volta para a lista ($to)");
    $row = $pdo->query("SELECT * FROM foods WHERE is_custom = 1")->fetch(PDO::FETCH_ASSOC);
    $ok($row && (int)$row['clinic_id'] === 1 && $row['source'] === 'CUSTOM' && (float)$row['energy_kcal'] === 363.5 && (float)$row['protein_g'] === 5.1 && (float)$row['serving_size_g'] === 50.0 && $row['serving_unit'] === 'unidade' && $row['allergens'] === 'leite' && trim((string)$row['search_text']) !== '', 'gravado na clínica 1 com vírgula decimal, porção, alérgeno e índice de busca');
    $fid = (int)($row['id'] ?? 0);
    [, $h] = $req($to);
    $ok(str_contains($h, 'Pão de queijo TESTE da clínica') && str_contains($h, 'data-testid="food-create-feedback"') && str_contains($h, 'data-testid="food-clinic-badge"'), 'lista mostra o novo alimento com aviso e selo "Minha clínica"');
    [, $h] = $req('/alimentos?q=' . rawurlencode('pao de queijo teste'));
    $ok(str_contains($h, 'Pão de queijo TESTE da clínica'), 'busca sem acento encontra o alimento');
    [$code, , $hdr] = $req('/alimentos/novo', ['_csrf_token' => $t, 'name' => 'pão de QUEIJO teste da clínica'] + $food);
    [, $h2] = $req('/alimentos/novo');
    $ok(str_contains($h2, 'já tem um alimento') && (int)$pdo->query("SELECT COUNT(*) FROM foods WHERE is_custom = 1")->fetchColumn() === 1, 'mesmo nome (maiúsculas/acentos) não duplica');
    [$code, $b] = $req('/catalogo/alimentos/busca?q=' . rawurlencode('pao de queijo teste'));
    $ids = array_column(json_decode($b, true)['data'] ?? [], 'id');
    $ok(in_array($fid, $ids, true), 'aparece na busca do editor de plano alimentar');
    [, $h] = $req('/planos/1');
    $req('/planos/1/itens', ['_csrf_token' => $tok($h), 'meal_id' => '1', 'food_id' => (string)$fid, 'amount' => '100', 'measure_unit' => 'g']);
    $item = $pdo->query("SELECT * FROM meal_plan_items WHERE food_id = $fid")->fetch(PDO::FETCH_ASSOC);
    $ok($item !== false, 'entra no plano alimentar da clínica');
    // clínica 2: não vê nem usa
    $req('/__teste_login?u=2&c=2&v=0&to=/alimentos');
    [, $h] = $req('/alimentos?q=' . rawurlencode('pao de queijo'));
    $ok(!str_contains($h, 'Pão de queijo TESTE da clínica'), 'outra clínica não vê o alimento na lista');
    [, $h] = $req('/alimentos?source=clinic');
    $ok(!str_contains($h, 'Pão de queijo TESTE'), 'outra clínica: filtro "Minha clínica" vazio');
    [, $b] = $req('/catalogo/alimentos/busca?q=' . rawurlencode('pao de queijo teste'));
    $ok(!in_array($fid, array_column(json_decode($b, true)['data'] ?? [], 'id'), true), 'outra clínica não acha na busca do plano');
    [$code] = $req("/catalogo/alimentos/$fid/conversao?amount=1&unit=g");
    $ok($code === 404 || $code === 403, "outra clínica não abre a conversão do alimento ($code)");
    [, $h] = $req('/alimentos/novo');
    $req('/alimentos/novo', ['_csrf_token' => $tok($h)] + $food);
    $ok((int)$pdo->query("SELECT COUNT(*) FROM foods WHERE is_custom = 1 AND clinic_id = 2")->fetchColumn() === 1, 'outra clínica pode ter o seu com o mesmo nome (sem conflito)');
    // recepção: sem botão e sem acesso
    $pdo->exec("INSERT INTO users (id, uuid, name, email, password_hash, role, is_verified, status) VALUES (30, 'rec-teste-41', 'Recepção TESTE', 'recepcao41@teste.local', 'x', 'receptionist', 1, 'active')");
    $pdo->exec("INSERT INTO clinic_members (clinic_id, user_id, role, status) VALUES (1, 30, 'receptionist', 'active')");
    $req('/__teste_login?u=30&c=1&v=0&to=/alimentos');
    [, $h] = $req('/alimentos');
    $ok(!str_contains($h, 'data-testid="link-food-create"'), 'recepção não vê o botão');
    [$code] = $req('/alimentos/novo', ['name' => 'Recepção TESTE'] + $food);
    $ok($code === 403 || $code === 302 || $code === 419, "recepção não grava ($code)");
    $ok((int)$pdo->query("SELECT COUNT(*) FROM foods WHERE name = 'Recepção TESTE'")->fetchColumn() === 0, 'nada gravado pela recepção');
} finally { proc_terminate($proc); @unlink($db); @unlink($jar); }
echo "\nv54.41 adicionar alimento: " . ($n - $fail) . " PASS, $fail FAIL\n"; exit($fail ? 1 : 0);
