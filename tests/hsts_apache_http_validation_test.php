<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * TESTE HTTP REAL COM APACHE DE HOMOLOGAÇÃO (NI2-811 Item 4)
 * ==============================================================================
 * Executa uma instância real do Apache 2.4 com SSL em porta de homologação (18443)
 * e valida requisições HTTP/HTTPS reais para:
 *   - HTML (index.html)
 *   - CSS (style.css)
 *   - SVG (logo.svg)
 *   - Redirecionamento (301)
 *   - Erro 403 (arquivo proibido /.env)
 *   - Erro 404 (recurso inexistente /nao_existe.png)
 *
 * Cenários avaliados de ponta a ponta:
 *   1. Operação Normal (Baseline 86400 em 100% dos recursos, redirects e erros)
 *   2. Ativação de Rollback (max-age=0 forçado em 100% dos recursos estáticos, redirects e erros)
 *   3. Restauração Explícita (Retorno imediato a 86400 em 100% dos recursos)
 * ==============================================================================
 */

// Limpa variáveis de proxy para garantir comunicação direta com o Apache local
putenv('HTTP_PROXY=');
putenv('HTTPS_PROXY=');
putenv('http_proxy=');
putenv('https_proxy=');
putenv('ALL_PROXY=');
putenv('all_proxy=');
putenv('NO_PROXY=127.0.0.1,localhost');
putenv('no_proxy=127.0.0.1,localhost');
unset($_SERVER['HTTP_PROXY'], $_SERVER['HTTPS_PROXY'], $_SERVER['http_proxy'], $_SERVER['https_proxy']);

$testDir = sys_get_temp_dir() . '/clyonutri_apache_hsts_' . bin2hex(random_bytes(6));
$webRootDir = $testDir . '/app/public';
$storageDir = $testDir . '/app/storage';
$sslDir = $testDir . '/ssl';
$confFile = $testDir . '/httpd.conf';
$pidFile = $testDir . '/httpd.pid';
$errorLog = $testDir . '/error.log';
$accessLog = $testDir . '/access.log';
$port = 18443;

mkdir($webRootDir, 0755, true);
mkdir($storageDir, 0755, true);
mkdir($sslDir, 0755, true);
mkdir($testDir . '/mutex', 0755, true);

// 1. Gerar certificado SSL autoassinado para o Apache de homologação
$certFile = $sslDir . '/server.crt';
$keyFile = $sslDir . '/server.key';
exec(sprintf(
    'openssl req -x509 -nodes -days 1 -newkey rsa:2048 -keyout %s -out %s -subj "/CN=127.0.0.1" 2>&1',
    escapeshellarg($keyFile),
    escapeshellarg($certFile)
));

// 2. Criar os recursos estáticos e o arquivo .htaccess idêntico ao de produção
file_put_contents($webRootDir . '/index.html', '<!DOCTYPE html><html><body><h1>ClyoNutri Homologation</h1></body></html>');
file_put_contents($webRootDir . '/style.css', 'body { font-family: sans-serif; background: #fafafa; }');
file_put_contents($webRootDir . '/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#007bff"/></svg>');
file_put_contents($webRootDir . '/.env', 'DATABASE_URL=secret');

// Copia o public/.htaccess do projeto e adiciona regra de teste de redirect 301
$htContent = file_get_contents(__DIR__ . '/../public/.htaccess');
$htContent = str_replace(
    'RewriteEngine On',
    "RewriteEngine On\n    RewriteRule ^redirect-test$ /index.html [R=301,L]",
    $htContent
);
file_put_contents($webRootDir . '/.htaccess', $htContent);

// 3. Montar arquivo de configuração standalone do Apache
$httpdConfContent = <<<CONF
ServerRoot "{$testDir}"
DefaultRuntimeDir "{$testDir}"
PidFile "{$pidFile}"
Mutex file:{$testDir}/mutex default
Listen 127.0.0.1:{$port}
ServerName 127.0.0.1:{$port}

LoadModule mpm_prefork_module /usr/libexec/apache2/mod_mpm_prefork.so
LoadModule unixd_module /usr/libexec/apache2/mod_unixd.so
LoadModule authz_core_module /usr/libexec/apache2/mod_authz_core.so
LoadModule authz_host_module /usr/libexec/apache2/mod_authz_host.so
LoadModule rewrite_module /usr/libexec/apache2/mod_rewrite.so
LoadModule headers_module /usr/libexec/apache2/mod_headers.so
LoadModule ssl_module /usr/libexec/apache2/mod_ssl.so
LoadModule socache_shmcb_module /usr/libexec/apache2/mod_socache_shmcb.so
LoadModule mime_module /usr/libexec/apache2/mod_mime.so
LoadModule dir_module /usr/libexec/apache2/mod_dir.so
LoadModule alias_module /usr/libexec/apache2/mod_alias.so
LoadModule log_config_module /usr/libexec/apache2/mod_log_config.so
LoadModule setenvif_module /usr/libexec/apache2/mod_setenvif.so

ErrorLog "{$errorLog}"
LogLevel warn

SSLEngine on
SSLCertificateFile "{$certFile}"
SSLCertificateKeyFile "{$keyFile}"
SSLSessionCache "shmcb:{$testDir}/ssl_scache(512000)"

DocumentRoot "{$webRootDir}"

<Directory "{$webRootDir}">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

CONF;

file_put_contents($confFile, $httpdConfContent);

echo "===============================================================================\n";
echo "   CLYONUTRI II — TESTE HTTP REAL COM APACHE DE HOMOLOGAÇÃO (NI2-811)\n";
echo "===============================================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck(string $desc, bool $condition, string $ev = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
        if ($ev !== '') echo "         Evidência: {$ev}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}\n";
        if ($ev !== '') echo "         Erro: {$ev}\n";
    }
}

function fetchHeaders(string $url): array {
    $cmd = sprintf('curl --noproxy "*" -k -s -I %s', escapeshellarg($url));
    $output = shell_exec($cmd) ?? '';
    $lines = explode("\r\n", trim($output));
    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        } elseif (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
            $headers[':status'] = (int)$m[1];
        }
    }
    return $headers;
}

// 4. Iniciar daemon Apache
echo "--> Iniciando daemon Apache na porta {$port} (HTTPS)...\n";
exec(sprintf('/usr/sbin/httpd -f %s -k start 2>&1', escapeshellarg($confFile)), $startOut, $startCode);
if ($startCode !== 0) {
    echo "ERRO ao iniciar Apache:\n" . implode("\n", $startOut) . "\n";
    if (file_exists($errorLog)) {
        echo "Log de erro:\n" . file_get_contents($errorLog) . "\n";
    }
    exit(1);
}

// Espera Apache subir
usleep(300000); // 300ms

try {
    $baseUrl = "https://127.0.0.1:{$port}";
    $rollbackFlag = $storageDir . '/hsts_rollback';

    // =========================================================================
    // FASE 1: Operação Normal (Sem Rollback)
    // =========================================================================
    echo "\n--> [FASE 1] Verificando Respostas HTTP Reais em Modo Normal (Baseline 86400)...\n";

    $hHtml = fetchHeaders("{$baseUrl}/index.html");
    assertCheck(
        "HTML (index.html) recebe max-age=86400 em operação normal",
        ($hHtml['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($hHtml['strict-transport-security'] ?? 'Nenhum')
    );

    $hCss = fetchHeaders("{$baseUrl}/style.css");
    assertCheck(
        "CSS (style.css) recebe max-age=86400 em operação normal",
        ($hCss['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($hCss['strict-transport-security'] ?? 'Nenhum')
    );

    $hSvg = fetchHeaders("{$baseUrl}/logo.svg");
    assertCheck(
        "SVG (logo.svg) recebe max-age=86400 em operação normal",
        ($hSvg['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($hSvg['strict-transport-security'] ?? 'Nenhum')
    );

    $hRedir = fetchHeaders("{$baseUrl}/redirect-test");
    assertCheck(
        "Redirecionamento (301) recebe max-age=86400 em operação normal",
        ($hRedir['strict-transport-security'] ?? '') === 'max-age=86400' && ($hRedir[':status'] ?? 0) === 301,
        "Status: " . ($hRedir[':status'] ?? 0) . " | HSTS recebido: " . ($hRedir['strict-transport-security'] ?? 'Nenhum')
    );

    $h403 = fetchHeaders("{$baseUrl}/.env");
    assertCheck(
        "Erro 403 (arquivo proibido) recebe max-age=86400 em operação normal",
        ($h403['strict-transport-security'] ?? '') === 'max-age=86400' && ($h403[':status'] ?? 0) === 403,
        "Status: " . ($h403[':status'] ?? 0) . " | HSTS recebido: " . ($h403['strict-transport-security'] ?? 'Nenhum')
    );

    $h404 = fetchHeaders("{$baseUrl}/nao_existe.png");
    assertCheck(
        "Erro 404 nativo do Apache recebe max-age=86400 em operação normal",
        ($h404['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($h404['strict-transport-security'] ?? 'Nenhum')
    );

    // =========================================================================
    // FASE 2: Ativação do Rollback (Flag presente em storage/)
    // =========================================================================
    echo "\n--> [FASE 2] Ativando Rollback e Verificando max-age=0 em 100% dos Recursos HTTP...\n";

    file_put_contents($rollbackFlag, gmdate('c'));

    $hHtmlRoll = fetchHeaders("{$baseUrl}/index.html");
    assertCheck(
        "HTML (index.html) recebe max-age=0 sob rollback ativo",
        ($hHtmlRoll['strict-transport-security'] ?? '') === 'max-age=0',
        "HSTS recebido: " . ($hHtmlRoll['strict-transport-security'] ?? 'Nenhum')
    );

    $hCssRoll = fetchHeaders("{$baseUrl}/style.css");
    assertCheck(
        "CSS (style.css) recebe max-age=0 sob rollback ativo",
        ($hCssRoll['strict-transport-security'] ?? '') === 'max-age=0',
        "HSTS recebido: " . ($hCssRoll['strict-transport-security'] ?? 'Nenhum')
    );

    $hSvgRoll = fetchHeaders("{$baseUrl}/logo.svg");
    assertCheck(
        "SVG (logo.svg) recebe max-age=0 sob rollback ativo",
        ($hSvgRoll['strict-transport-security'] ?? '') === 'max-age=0',
        "HSTS recebido: " . ($hSvgRoll['strict-transport-security'] ?? 'Nenhum')
    );

    $hRedirRoll = fetchHeaders("{$baseUrl}/redirect-test");
    assertCheck(
        "Redirecionamento (301) recebe max-age=0 sob rollback ativo",
        ($hRedirRoll['strict-transport-security'] ?? '') === 'max-age=0' && ($hRedirRoll[':status'] ?? 0) === 301,
        "Status: " . ($hRedirRoll[':status'] ?? 0) . " | HSTS recebido: " . ($hRedirRoll['strict-transport-security'] ?? 'Nenhum')
    );

    $h403Roll = fetchHeaders("{$baseUrl}/.env");
    assertCheck(
        "Erro 403 (arquivo proibido) recebe max-age=0 sob rollback ativo",
        ($h403Roll['strict-transport-security'] ?? '') === 'max-age=0' && ($h403Roll[':status'] ?? 0) === 403,
        "Status: " . ($h403Roll[':status'] ?? 0) . " | HSTS recebido: " . ($h403Roll['strict-transport-security'] ?? 'Nenhum')
    );

    $h404Roll = fetchHeaders("{$baseUrl}/nao_existe.png");
    assertCheck(
        "Erro 404 nativo do Apache recebe max-age=0 sob rollback ativo",
        ($h404Roll['strict-transport-security'] ?? '') === 'max-age=0',
        "HSTS recebido: " . ($h404Roll['strict-transport-security'] ?? 'Nenhum')
    );

    // =========================================================================
    // FASE 3: Restauração Explícita (Remoção da Flag)
    // =========================================================================
    echo "\n--> [FASE 3] Restauração Explícita e Retorno ao Baseline 86400...\n";

    unlink($rollbackFlag);

    $hHtmlRest = fetchHeaders("{$baseUrl}/index.html");
    assertCheck(
        "HTML (index.html) retorna a max-age=86400 após restauração",
        ($hHtmlRest['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($hHtmlRest['strict-transport-security'] ?? 'Nenhum')
    );

    $hCssRest = fetchHeaders("{$baseUrl}/style.css");
    assertCheck(
        "CSS (style.css) retorna a max-age=86400 após restauração",
        ($hCssRest['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($hCssRest['strict-transport-security'] ?? 'Nenhum')
    );

    $hSvgRest = fetchHeaders("{$baseUrl}/logo.svg");
    assertCheck(
        "SVG (logo.svg) retorna a max-age=86400 após restauração",
        ($hSvgRest['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($hSvgRest['strict-transport-security'] ?? 'Nenhum')
    );

    $hRedirRest = fetchHeaders("{$baseUrl}/redirect-test");
    assertCheck(
        "Redirecionamento (301) retorna a max-age=86400 após restauração",
        ($hRedirRest['strict-transport-security'] ?? '') === 'max-age=86400' && ($hRedirRest[':status'] ?? 0) === 301,
        "Status: " . ($hRedirRest[':status'] ?? 0) . " | HSTS recebido: " . ($hRedirRest['strict-transport-security'] ?? 'Nenhum')
    );

    $h403Rest = fetchHeaders("{$baseUrl}/.env");
    assertCheck(
        "Erro 403 retorna a max-age=86400 após restauração",
        ($h403Rest['strict-transport-security'] ?? '') === 'max-age=86400' && ($h403Rest[':status'] ?? 0) === 403,
        "Status: " . ($h403Rest[':status'] ?? 0) . " | HSTS recebido: " . ($h403Rest['strict-transport-security'] ?? 'Nenhum')
    );

    $h404Rest = fetchHeaders("{$baseUrl}/nao_existe.png");
    assertCheck(
        "Erro 404 nativo do Apache retorna a max-age=86400 após restauração",
        ($h404Rest['strict-transport-security'] ?? '') === 'max-age=86400',
        "HSTS recebido: " . ($h404Rest['strict-transport-security'] ?? 'Nenhum')
    );

} finally {
    // 5. Encerrar daemon Apache e limpar arquivos temporários
    echo "\n--> Finalizando daemon Apache e limpando ambiente de homologação...\n";
    exec(sprintf('/usr/sbin/httpd -f %s -k stop 2>&1', escapeshellarg($confFile)));
    usleep(200000); // 200ms

    // Remoção recursiva do diretório temporário
    exec(sprintf('rm -rf %s', escapeshellarg($testDir)));
}

echo "\n===============================================================================\n";
echo "RESULTADO DO TESTE HTTP REAL COM APACHE:\n";
echo "  Total: " . ($passed + $failed) . " | Passou: {$passed} | Falhou: {$failed}\n";
echo "===============================================================================\n";

if ($failed > 0) {
    echo "\n[ERRO] Falha na validação HTTP real do Apache.\n";
    exit(1);
} else {
    echo "\n[SUCESSO] Teste HTTP real concluído com 100% de aprovação (Apache 2.4)!\n";
    exit(0);
}
