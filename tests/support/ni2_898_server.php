<?php
declare(strict_types=1);
/** NI2-898: servidor HTTP real, porta dinâmica, schema e sessões temporários por instância. */
function ni2898_server(array $settings): array
{
    $root = dirname(__DIR__, 2);
    $dir = '/tmp/ni2-898-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) throw new RuntimeException('Não foi possível isolar o servidor de teste.');
    $db = new PDO('sqlite:' . $dir . '/test.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new \ClyoNutri\Core\Database\Migrator($db, $root . '/database/migrations'))->run();
    $db = null;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('BLOCKED: bind local: ' . $error);
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    // O handler só redireciona o arquivo de sessão. Cookies, HTTPS, autenticação e rotas
    // continuam sendo os do front controller, inclusive sua ordem de execução.
    $wrapper = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';'
        . 'session_set_save_handler(new class extends SessionHandler { public function open(string $path,string $name): bool {return parent::open(__DIR__,$name);} },true);'
        . 'require ' . var_export($root . '/public/index.php', true) . ';';
    file_put_contents($dir . '/front.php', $wrapper);
    $env = $settings + [
        'APP_ENV' => 'production', 'APP_URL' => 'https://clyonutri.com', 'APP_DEBUG' => 'false',
        'FORCE_HTTPS' => '', 'TRUSTED_PROXIES' => '', 'MAIL_DRIVER' => 'null',
        'HSTS_MAX_AGE' => '15552000', 'HSTS_SEND_ZERO' => 'false',
        'CLINICAL_ENCRYPTION_KEY' => 'test-clinical-key-ni2-898-not-for-prod',
        'APP_KEY' => 'test-application-key-ni2-898-not-for-prod',
    ];
    $env['DB_CONNECTION'] = 'sqlite'; $env['DB_DATABASE'] = $dir . '/test.sqlite';
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $dir . '/front.php'],
        [0 => ['file','/dev/null','r'], 1 => ['file',$dir.'/server.log','a'], 2 => ['file',$dir.'/server.log','a']], $pipes, $root, $env);
    if (!is_resource($proc)) throw new RuntimeException('BLOCKED: processo HTTP não iniciou.');
    $stopped = false;
    $stop = static function () use ($proc, $dir, &$stopped): void {
        if ($stopped) return; $stopped = true;
        proc_terminate($proc); proc_close($proc);
        foreach (glob($dir . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
        rmdir($dir);
    };
    register_shutdown_function($stop);
    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($conn) { fclose($conn); return ['base'=>'http://127.0.0.1:'.$port,'stop'=>$stop]; }
        if (!proc_get_status($proc)['running']) break;
        usleep(50000);
    }
    $log = (string)@file_get_contents($dir . '/server.log'); $stop();
    throw new RuntimeException('BLOCKED: servidor de teste indisponível: ' . $log);
}
