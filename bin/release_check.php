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
    if (preg_match($namePattern, $name) && ($isScript || in_array($ext, ['html', 'htm', 'txt', 'sh', 'sql'], true))) {
        $problems[] = "{$rel}: nome de arquivo de diagnóstico/teste";
        continue;
    }
    if ($isScript && preg_match($contentPattern, (string)file_get_contents($file->getPathname()))) {
        $problems[] = "{$rel}: contém phpinfo/diagnóstico";
    }
}

if ($problems) {
    fwrite(STDERR, "FALHA release_check: artefatos proibidos em public/\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}
echo "OK   release_check: public/ sem scripts de diagnóstico, phpinfo ou teste\n";
exit(0);
