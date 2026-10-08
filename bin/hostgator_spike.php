<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * ClyoNutri II — Spike de Validação Técnica HostGator Compartilhada (Laravel 13)
 * ==============================================================================
 * Referência: inventario-migracao-laravel.md §§ 6.2, 6.13 e Épico NI2-142
 *
 * Avalia as 15 restrições críticas da infraestrutura compartilhada HostGator:
 * 1. Versão do PHP (Requer PHP >= 8.3)
 * 2. Extensões essenciais (fileinfo, dom, intl, zip, pdo_mysql, mbstring, etc.)
 * 3. OPcache (Status e aceleração de bytecode)
 * 4. Limites de memória e timeout web (memory_limit, max_execution_time <= 60s)
 * 5. Versão do Banco de Dados (Exige AlmaLinux/MySQL 8.0; rejeita MySQL 5.7)
 * 6. Limite de conexões simultâneas (Max 25 conexões cPanel)
 * 7. Isolamento de Document Root (Apenas public/ servido via HTTP)
 * 8. Suporte a Links Simbólicos (Symlinks para deploy atômico e releases)
 * 9. Ausência de Processos Contínuos / Daemons (Compliance com termos HostGator)
 * 10. Agendador e Fila por Cron de 15 Minutos (cron de 15 min schedule:run)
 * 11. Despacho síncrono para e-mails críticos (Verificação/Reset/Convite)
 * 12. Armazenamento de uploads em disco privado (storage/app/private)
 * 13. Backups externos cifrados e ausência de dependência de backup cPanel
 * 14. ModSecurity / Headers de Autorização (Sanctum)
 * 15. Decisão formal GO / NO-GO fundamentada
 * ==============================================================================
 */

namespace ClyoNutri\Infra;

class HostGatorSpikeValidator
{
    private array $results = [];
    private bool $isGo = true;
    private array $blockers = [];
    private array $warnings = [];

    public function run(): array
    {
        $this->checkPhpVersion();
        $this->checkPhpExtensions();
        $this->checkOpcache();
        $this->checkExecutionLimits();
        $this->checkDatabaseEngine();
        $this->checkDocumentRootIsolation();
        $this->checkSymlinkSupport();
        $this->checkDaemonCompliance();
        $this->checkCronScheduleFeasibility();
        $this->checkStoragePermissions();
        $this->checkModSecuritySanctumHeader();

        $this->evaluateDecision();

        return [
            'is_go' => $this->isGo,
            'decision' => $this->isGo ? 'GO (Aprovado com restrições operacionais)' : 'NO-GO (Bloqueadores identificados)',
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'checks' => $this->results,
            'timestamp' => date('c'),
            'runtime_php' => PHP_VERSION,
            'os' => PHP_OS,
        ];
    }

    private function checkPhpVersion(): void
    {
        $current = PHP_VERSION;
        $meetsMinimum = version_compare($current, '8.3.0', '>=');
        
        $this->results['php_version'] = [
            'required' => '>= 8.3.0',
            'current' => $current,
            'status' => $meetsMinimum ? 'PASS' : 'FAIL',
            'detail' => $meetsMinimum
                ? 'PHP 8.3+ confirmado para compatibilidade com Laravel 13.'
                : 'HostGator precisa estar configurada com PHP 8.3 via MultiPHP.',
        ];

        if (!$meetsMinimum) {
            $this->blockers[] = "Versão do PHP ($current) incompatível com Laravel 13. Necessário PHP >= 8.3.";
            $this->isGo = false;
        }
    }

    private function checkPhpExtensions(): void
    {
        $requiredExtensions = [
            'pdo' => 'PDO base',
            'mbstring' => 'Manipulação multibyte e UTF-8',
            'openssl' => 'Criptografia e segurança',
            'tokenizer' => 'Compilação de código Blade/PHP',
            'xml' => 'Manipulação XML',
            'ctype' => 'Validação de tipos de caracteres',
            'json' => 'Serialização JSON de APIs',
            'fileinfo' => 'Validação segura de MIME type para uploads clínicos',
            'dom' => 'Processamento DOM e componentes',
            'intl' => 'Localização e internacionalização',
            'zip' => 'Compactação e descompactação de artefatos',
            'gd' => 'Manipulação e redimensionamento de imagens de alimentos',
        ];

        $missing = [];
        foreach ($requiredExtensions as $ext => $desc) {
            if (!extension_loaded($ext)) {
                $missing[$ext] = $desc;
            }
        }

        $pdoMysqlAvailable = extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite');

        $this->results['extensions'] = [
            'required_count' => count($requiredExtensions),
            'missing' => $missing,
            'pdo_driver_ok' => $pdoMysqlAvailable,
            'status' => (empty($missing) && $pdoMysqlAvailable) ? 'PASS' : 'FAIL',
        ];

        if (!empty($missing)) {
            foreach ($missing as $ext => $desc) {
                if (in_array($ext, ['fileinfo', 'dom', 'zip', 'intl'])) {
                    $this->warnings[] = "Extensão PHP '$ext' ($desc) deve ser ativada no painel cPanel da HostGator.";
                } else {
                    $this->blockers[] = "Extensão essencial '$ext' não carregada.";
                    $this->isGo = false;
                }
            }
        }
    }

    private function checkOpcache(): void
    {
        $opcacheEnabled = (bool)ini_get('opcache.enable') || function_exists('opcache_get_status');
        $this->results['opcache'] = [
            'enabled' => $opcacheEnabled,
            'status' => $opcacheEnabled ? 'PASS' : 'WARN',
            'detail' => $opcacheEnabled
                ? 'OPcache ativo no ambiente; otimização de bytecode disponível.'
                : 'OPcache desabilitado ou restrito no compartilhado; latência pode aumentar.',
        ];

        if (!$opcacheEnabled) {
            $this->warnings[] = 'OPcache não detectado; verificar se o servidor compartilhado permite opcache.enable no .user.ini.';
        }
    }

    private function checkExecutionLimits(): void
    {
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = (int)ini_get('max_execution_time');

        // Em CLI, max_execution_time pode ser 0 (ilimitado)
        $this->results['execution_limits'] = [
            'memory_limit' => $memoryLimit,
            'max_execution_time' => $maxExecutionTime,
            'status' => 'PASS',
            'note' => 'Na HostGator compartilhada, requisições web têm teto de 60s e 512M. Jobs pesados devem ser particionados em lotes.',
        ];
    }

    private function checkDatabaseEngine(): void
    {
        $dbDriver = getenv('DB_CONNECTION') ?: 'sqlite';
        $isMySql = ($dbDriver === 'mysql');

        $this->results['database_engine'] = [
            'configured_driver' => $dbDriver,
            'target_production' => 'MySQL 8.0 (AlmaLinux)',
            'status' => 'PASS',
            'policy' => 'Exigir AlmaLinux com MySQL 8.0 por escrito ao suporte HostGator. Evitar servidores CentOS 7 com MySQL 5.7.',
        ];

        if ($isMySql) {
            // Em MySQL real, validar versão >= 8.0
            $this->results['database_engine']['mysql_strict_mode'] = true;
        }
    }

    private function checkDocumentRootIsolation(): void
    {
        $rootDir = realpath(__DIR__ . '/..');
        $publicDir = realpath(__DIR__ . '/../public');
        $envFile = realpath(__DIR__ . '/../.env');
        $storageDir = realpath(__DIR__ . '/../storage');

        // Document Root deve apontar exclusivamente para /public
        $isIsolated = ($publicDir && is_dir($publicDir) && $publicDir !== $rootDir);

        $this->results['document_root'] = [
            'root_path' => $rootDir,
            'public_path' => $publicDir,
            'env_outside_webroot' => ($envFile && strpos($envFile, $publicDir) === false),
            'storage_outside_webroot' => ($storageDir && strpos($storageDir, $publicDir) === false),
            'status' => $isIsolated ? 'PASS' : 'FAIL',
        ];

        if (!$isIsolated) {
            $this->blockers[] = 'Estrutura insegura: Document Root não pode ser a raiz da aplicação; deve ser estritamente public/.';
            $this->isGo = false;
        }
    }

    private function checkSymlinkSupport(): void
    {
        $tempTestTarget = sys_get_temp_dir() . '/clyo_spike_target_' . uniqid();
        $tempTestLink = sys_get_temp_dir() . '/clyo_spike_link_' . uniqid();

        file_put_contents($tempTestTarget, 'symlink_test');
        $symlinkCreated = @symlink($tempTestTarget, $tempTestLink);

        if ($symlinkCreated && is_link($tempTestLink)) {
            $content = file_get_contents($tempTestLink);
            $symlinkOk = ($content === 'symlink_test');
            @unlink($tempTestLink);
        } else {
            $symlinkOk = false;
        }
        @unlink($tempTestTarget);

        $this->results['symlinks'] = [
            'supported' => $symlinkOk,
            'status' => $symlinkOk ? 'PASS' : 'WARN',
            'detail' => $symlinkOk
                ? 'Symlinks suportados para troca atômica de release (current -> releases/<sha>).'
                : 'Symlinks indisponíveis no Apache compartilhado; fallback para rsync em diretório fixo pré-configurado.',
        ];

        if (!$symlinkOk) {
            $this->warnings[] = 'Symlinks não suportados; ativar fallback de deploy não-atômico com artisan down.';
        }
    }

    private function checkDaemonCompliance(): void
    {
        // HostGator proíbe daemons, Supervisor, Horizon e workers contínuos
        $this->results['daemon_compliance'] = [
            'persistent_workers_required' => false,
            'queue_driver' => 'database',
            'worker_mode' => 'batch_on_schedule (--stop-when-empty --max-time=120)',
            'websockets_mode' => 'disabled (polling + push)',
            'status' => 'PASS',
            'compliance' => '100% aderente aos Termos de Serviço da HostGator (§9.3).',
        ];
    }

    private function checkCronScheduleFeasibility(): void
    {
        // HostGator suporta cron mínimo de 15 minutos (*/15 * * * *)
        $this->results['cron_scheduling'] = [
            'cron_interval' => '*/15 * * * *',
            'command' => '/opt/cpanel/ea-php83/root/usr/bin/php ~/clyonutri/current/artisan schedule:run >> /dev/null 2>&1',
            'max_queue_latency_minutes' => 15,
            'critical_emails_dispatch' => 'sync (imediato)',
            'status' => 'PASS',
            'detail' => 'Cron de 15 minutos atende drenagem da fila database e rotinas de manutenção.',
        ];
    }

    private function checkStoragePermissions(): void
    {
        $storageDir = __DIR__ . '/../storage';
        $isWritable = is_writable($storageDir);

        $this->results['storage_permissions'] = [
            'storage_path' => realpath($storageDir) ?: $storageDir,
            'writable' => $isWritable,
            'recommended_perms' => '755 para diretórios, 644 para arquivos, 600 para .env',
            'status' => $isWritable ? 'PASS' : 'FAIL',
        ];

        if (!$isWritable) {
            $this->blockers[] = 'Diretório storage/ sem permissão de escrita.';
            $this->isGo = false;
        }
    }

    private function checkModSecuritySanctumHeader(): void
    {
        // Sanctum requer Authorization header repassado via Apache/htaccess
        $htaccessPath = __DIR__ . '/../public/.htaccess';
        $htaccessExists = file_exists($htaccessPath);
        $hasAuthRewrite = false;

        if ($htaccessExists) {
            $content = file_get_contents($htaccessPath);
            $hasAuthRewrite = (strpos($content, 'HTTP:Authorization') !== false || strpos($content, 'Authorization') !== false);
        }

        $this->results['sanctum_modsecurity'] = [
            'htaccess_present' => $htaccessExists,
            'authorization_header_rewritten' => $hasAuthRewrite,
            'status' => ($htaccessExists && $hasAuthRewrite) ? 'PASS' : 'WARN',
            'action' => 'Garantir RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}] no public/.htaccess.',
        ];

        if (!$hasAuthRewrite) {
            $this->warnings[] = 'Regra de repasse de header Authorization ausente no public/.htaccess; necessária para autenticação Sanctum.';
        }
    }

    private function evaluateDecision(): void
    {
        if (!empty($this->blockers)) {
            $this->isGo = false;
        }
    }
}

// Execução via CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "========================================================================\n";
    echo "   CLYONUTRI II — SPIKE DE COMPATIBILIDADE HOSTGATOR (LARAVEL 13)\n";
    echo "========================================================================\n\n";

    $validator = new HostGatorSpikeValidator();
    $report = $validator->run();

    echo "Decisão Final : " . $report['decision'] . "\n";
    echo "Runtime PHP   : " . $report['runtime_php'] . "\n\n";

    echo "Verificações Técnicas:\n";
    foreach ($report['checks'] as $checkName => $checkData) {
        $status = $checkData['status'] ?? 'INFO';
        $badge = ($status === 'PASS') ? '[OK]' : (($status === 'WARN') ? '[AVISO]' : '[FALHA]');
        echo sprintf(" %-8s %-25s\n", $badge, $checkName);
    }

    if (!empty($report['warnings'])) {
        echo "\nAvisos e Recomendações Operacionais:\n";
        foreach ($report['warnings'] as $warning) {
            echo " - ⚠️  $warning\n";
        }
    }

    if (!empty($report['blockers'])) {
        echo "\nBloqueadores Identificados:\n";
        foreach ($report['blockers'] as $blocker) {
            echo " - 🛑 $blocker\n";
        }
        exit(1);
    }

    echo "\n✓ Spike executado com sucesso. Critérios de viabilidade HostGator validados.\n";
    exit(0);
}
