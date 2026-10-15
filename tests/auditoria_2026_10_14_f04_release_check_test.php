<?php

declare(strict_types=1);

// F04 — nenhum diagnóstico em public/ e checagem de release que reprova (auditoria 14/10/2026).
require_once __DIR__ . '/support/auditoria_2026_10_14_fixture.php';

echo "F04 — diagnóstico fora de public/\n";
$root = realpath(__DIR__ . '/..');
$php = escapeshellarg(PHP_BINARY);
$check = static function (string $dir) use ($php, $root): int {
    exec("{$php} " . escapeshellarg("{$root}/bin/release_check.php") . ' ' . escapeshellarg($dir) . ' 2>&1', $o, $rc);
    return $rc;
};

$diag = glob("{$root}/public/_diag_*.php") ?: [];
aud_check($diag === [], 'fonte: nenhum public/_diag_*.php');
aud_check($check($root) === 0, 'release_check aprova o public/ atual');

$tmp = sys_get_temp_dir() . '/aud_rel_' . bin2hex(random_bytes(4));
mkdir("{$tmp}/public/assets", 0777, true);
copy("{$root}/public/index.php", "{$tmp}/public/index.php");
copy("{$root}/public/.htaccess", "{$tmp}/public/.htaccess"); // v10: release_check exige HTTPS nos dois .htaccess
copy("{$root}/.htaccess", "{$tmp}/.htaccess");
aud_check($check($tmp) === 0, 'cópia limpa aprovada');
$cases = [
    'public/_diag_99ba259d91b45cec0606e89f.php' => "<?php echo PHP_VERSION;",
    'public/info.php' => "<?php phpinfo();",
    'public/teste.php' => "<?php echo 1;",
    'public/assets/x.php' => "<?php echo 1;",
    'public/test_mail.html' => "oi",
];
foreach ($cases as $rel => $content) {
    file_put_contents("{$tmp}/{$rel}", $content);
    aud_check($check($tmp) === 1, "release_check reprova {$rel}");
    unlink("{$tmp}/{$rel}");
}
file_put_contents("{$tmp}/public/index.php", "<?php phpinfo();");
aud_check($check($tmp) === 1, 'release_check reprova phpinfo() mesmo no index.php');
exec('rm -rf ' . escapeshellarg($tmp));

// Diagnóstico CLI existe, recusa web e não imprime segredos
$cli = (string)file_get_contents("{$root}/bin/diagnostico.php");
aud_check(str_contains($cli, "PHP_SAPI !== 'cli'"), 'bin/diagnostico.php recusa execução fora da CLI');
$envDir = sys_get_temp_dir() . '/aud_diag_' . bin2hex(random_bytes(4));
mkdir("{$envDir}/bin", 0777, true);
mkdir("{$envDir}/public", 0777, true);
copy("{$root}/bin/diagnostico.php", "{$envDir}/bin/diagnostico.php");
copy("{$root}/bin/release_check.php", "{$envDir}/bin/release_check.php");
copy("{$root}/public/index.php", "{$envDir}/public/index.php");
file_put_contents("{$envDir}/.env", "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_PASSWORD=SegredoDb!42\nMAIL_PASSWORD=SegredoMail!42\nAPP_DEBUG=false\n");
$out = (string)shell_exec("{$php} " . escapeshellarg("{$envDir}/bin/diagnostico.php") . ' 2>&1');
aud_check(str_contains($out, 'DB_PASSWORD preenchido') && !str_contains($out, 'SegredoDb') && !str_contains($out, 'SegredoMail'), 'diagnóstico CLI roda e não mostra senhas');
exec('rm -rf ' . escapeshellarg($envDir));

// HTTP: URL antiga do diag e o caminho do diagnóstico CLI não são servidos
$fx = aud_fixture();
$base = aud_http_server($fx['path'], 20000 + random_int(0, 900))['base'];
[$s1] = aud_http('GET', "{$base}/_diag_99ba259d91b45cec0606e89f.php");
[$s2] = aud_http('GET', "{$base}/bin/diagnostico.php");
aud_check($s1 === 404 && $s2 === 404, "URL do diag antigo -> {$s1}; /bin/diagnostico.php -> {$s2}");

// Pacote (quando informado): zip sem diag e com release_check
$zip = getenv('AUD_PACKAGE_ZIP');
if ($zip && is_file($zip)) {
    $list = (string)shell_exec('unzip -Z1 ' . escapeshellarg($zip));
    aud_check(!preg_match('#public/[^\n]*(diag|phpinfo|teste?)[^\n]*\.php#i', $list), 'pacote: nenhum diag/phpinfo/teste em public/');
    aud_check(str_contains($list, 'bin/diagnostico.php') && str_contains($list, 'bin/release_check.php'), 'pacote: inclui bin/diagnostico.php e bin/release_check.php');
}

echo "F04: OK\n";
