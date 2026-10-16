<?php
/**
 * Verificação de release (auditoria 14/10/2026, F04): falha se public/ contiver scripts de
 * diagnóstico, phpinfo ou teste. Uso: php bin/release_check.php [raiz-do-projeto]
 * Código de saída 0 = OK, 1 = artefato proibido encontrado.
 */
declare(strict_types=1);

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$public = $root . '/public';
if (!is_dir($public)) {
    fwrite(STDERR, "FALHA release_check: pasta public/ não encontrada em {$root}\n");
    exit(1);
}

$allowedPhp = ['index.php'];
$namePattern = '/(^_?diag|diagnos|phpinfo|^info\.php$|^test|_test\.php$|^teste|^debug|adminer|^php\.php$|^i\.php$)/i';
$contentPattern = '/\b(phpinfo|php_uname|getenv\s*\(\s*[\'"]DB_PASSWORD|opcache_get_status)\s*\(/i';

$problems = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }
    $rel = substr($file->getPathname(), strlen($root) + 1);
    $name = $file->getFilename();
    $ext = strtolower($file->getExtension());
    $isScript = in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc'], true);

    if ($isScript && !($file->getPath() === $public && in_array($name, $allowedPhp, true))) {
        $problems[] = "{$rel}: script PHP fora do front-controller";
        continue;
    }
    if (preg_match('/\.(sql|sqlite3?|db|zip|tar|gz|tgz|rar|7z|bak|old|orig|swp|log|env|ini)$|^error_log$/i', $name)) {
        $problems[] = "{$rel}: dump/backup/log/config em public/";
        continue;
    }
    if (preg_match($namePattern, $name) && ($isScript || in_array($ext, ['html', 'htm', 'txt', 'sh', 'sql'], true))) {
        $problems[] = "{$rel}: nome de arquivo de diagnóstico/teste";
        continue;
    }
    if ($isScript && preg_match($contentPattern, (string)file_get_contents($file->getPathname()))) {
        $problems[] = "{$rel}: contém phpinfo/diagnóstico";
    }
}

// v10 (achado "Endpoint público de diagnóstico" / "HTTPS não obrigatório"): checagens do código.
$front = $public . '/index.php';
$frontSrc = is_file($front) ? (string)file_get_contents($front) : '';
if ($frontSrc !== '') {
    if (preg_match_all('/\$router->(?:get|post|put|patch|delete|any)\(\s*[\'"]([^\'"]+)[\'"]/i', $frontSrc, $rm)) {
        foreach ($rm[1] as $route) {
            if (preg_match('#(^|/)(_?diag\w*|debug\w*|phpinfo|info|version|env|_?test\w*|teste\w*|_ignition|telescope|status-servidor|server-status)(/|$)#i', $route)) {
                $problems[] = "public/index.php: rota pública de diagnóstico/teste: {$route}";
            }
        }
    }
    foreach (['/health', '/up'] as $hp) {
        $pos = strpos($frontSrc, "\$router->get('{$hp}'");
        if ($pos === false) continue;
        $end = strpos($frontSrc, '});', $pos);
        $body = substr($frontSrc, $pos, ($end === false ? 1500 : $end - $pos));
        if (preg_match('/[\'"](version|timestamp|service|database|scheduler\w*|environment|php|path)[\'"]\s*=>/i', $body)) {
            $problems[] = "public/index.php: {$hp} expõe detalhes (versão/horário/serviço/banco); deve responder só {\"status\":\"ok\"}";
        }
    }
    if (!str_contains($frontSrc, 'ErrorExposure::configure()')) {
        $problems[] = 'public/index.php: falta ErrorExposure::configure() (stack trace pode vazar com APP_DEBUG=true)';
    }
    if (!str_contains($frontSrc, 'Transport::enforce()')) {
        $problems[] = 'public/index.php: falta Transport::enforce() (HTTPS obrigatório no PHP)';
    }
}
foreach (['.htaccess', 'public/.htaccess'] as $ht) {
    $c = is_file($root . '/' . $ht) ? (string)file_get_contents($root . '/' . $ht) : '';
    if (!str_contains($c, 'RewriteCond %{HTTPS} !=on') || !preg_match('#RewriteRule \^ https://%1%\{REQUEST_URI\} \[R=301,L\]#', $c)) {
        $problems[] = "{$ht}: sem o redirecionamento HTTP->HTTPS (301)";
    }
    if (!str_contains($c, '(sql|sqlite3?|db|zip')) {
        $problems[] = "{$ht}: sem o bloqueio de dumps/backups/logs (FilesMatch v10)";
    }
}
foreach (glob($root . '/*.php') ?: [] as $rootPhp) {
    if (basename($rootPhp) !== 'index.php') {
        $problems[] = basename($rootPhp) . ': script PHP solto na raiz do projeto';
    }
}

if ($problems) {
    fwrite(STDERR, "FALHA release_check: artefatos proibidos\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}
echo "OK   release_check: public/ sem scripts de diagnóstico, phpinfo ou teste; /health mínimo; HTTPS nos dois .htaccess\n";
exit(0);
