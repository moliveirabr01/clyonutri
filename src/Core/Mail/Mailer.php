<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

use ClyoNutri\Core\Security\Env;
use RuntimeException;

/**
 * Envio de e-mail transacional sem dependências externas.
 *
 * Drivers (MAIL_DRIVER):
 *   smtp  — servidor SMTP autenticado (HostGator: caixa criada no cPanel), SSL (465) ou STARTTLS (587)
 *   mail  — função mail() do PHP (sendmail do servidor)
 *   log   — grava a mensagem em storage/logs/mail.log (desenvolvimento e testes; NÃO entrega)
 *
 * Variáveis: MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION (ssl|tls|none), MAIL_USERNAME, MAIL_PASSWORD,
 * MAIL_FROM_ADDRESS, MAIL_FROM_NAME, MAIL_TIMEOUT (s), MAIL_VERIFY_PEER (true|false), MAIL_LOG_PATH.
 * Em produção, sem MAIL_DRIVER configurado o envio falha de forma explícita (nunca "finge" que enviou).
 */
final class Mailer
{
    public function __construct(private array $config = [])
    {
        $env = static fn(string $k, ?string $d = null): ?string => ($v = Env::get($k)) === null || $v === '' ? $d : (string)$v;
        $production = ($env('APP_ENV', 'production') === 'production');
        $this->config += [
            'driver' => $env('MAIL_DRIVER', $production ? null : 'log'),
            'host' => $env('MAIL_HOST'),
            'port' => (int)$env('MAIL_PORT', '465'),
            'encryption' => strtolower((string)$env('MAIL_ENCRYPTION', 'ssl')),
            'username' => $env('MAIL_USERNAME'),
            'password' => $env('MAIL_PASSWORD'),
            'from_address' => $env('MAIL_FROM_ADDRESS', $env('MAIL_USERNAME')),
            'from_name' => $env('MAIL_FROM_NAME', 'ClyoNutri'),
            'timeout' => (int)$env('MAIL_TIMEOUT', '15'),
            'verify_peer' => !in_array(strtolower((string)$env('MAIL_VERIFY_PEER', 'true')), ['0', 'false', 'no', 'off'], true),
            'log_path' => $env('MAIL_LOG_PATH', dirname(__DIR__, 3) . '/storage/logs/mail.log'),
        ];
    }

    /** Resumo da configuração, sem segredos (healthcheck). */
    public function describe(): array
    {
        $c = $this->config;
        $problems = [];
        if ($c['driver'] === null) {
            $problems[] = 'MAIL_DRIVER não definido';
        } elseif (!in_array($c['driver'], ['smtp', 'mail', 'log'], true)) {
            $problems[] = "MAIL_DRIVER inválido: {$c['driver']}";
        }
        if ($c['driver'] === 'smtp') {
            foreach (['host' => 'MAIL_HOST', 'username' => 'MAIL_USERNAME', 'password' => 'MAIL_PASSWORD'] as $k => $name) {
                if (empty($c[$k])) {
                    $problems[] = "{$name} não definido";
                }
            }
            if (!in_array($c['encryption'], ['ssl', 'tls', 'none'], true)) {
                $problems[] = 'MAIL_ENCRYPTION deve ser ssl, tls ou none';
            }
        }
        if (empty($c['from_address']) || !filter_var($c['from_address'], FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'MAIL_FROM_ADDRESS inválido ou ausente';
        }
        return [
            'driver' => $c['driver'],
            'host' => $c['host'],
            'port' => $c['port'],
            'encryption' => $c['encryption'],
            'from' => $c['from_address'],
            'delivers' => in_array($c['driver'], ['smtp', 'mail'], true),
            'problems' => $problems,
        ];
    }

    public function send(MailMessage $message): void
    {
        $info = $this->describe();
        if ($info['problems']) {
            throw new RuntimeException('E-mail não configurado: ' . implode('; ', $info['problems']) . '.');
        }
        $domain = substr((string)strrchr((string)$this->config['from_address'], '@'), 1) ?: 'localhost';
        $mime = $message->build((string)$this->config['from_address'], (string)$this->config['from_name'], $domain);

        match ($this->config['driver']) {
            'smtp' => (new SmtpTransport(
                (string)$this->config['host'],
                $this->config['port'],
                $this->config['encryption'],
                (string)$this->config['username'],
                (string)$this->config['password'],
                $this->config['timeout'],
                $this->config['verify_peer'],
            ))->send((string)$this->config['from_address'], $message->to, $mime['headers'], $mime['body']),
            'mail' => $this->sendWithMailFunction($message, $mime),
            'log' => $this->writeToLog($message, $mime),
        };
    }

    private function sendWithMailFunction(MailMessage $message, array $mime): void
    {
        $headers = $mime['headers'];
        $to = $headers['To'];
        $subject = $headers['Subject'];
        unset($headers['To'], $headers['Subject']);
        $headerLines = implode("\r\n", array_map(static fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers));
        $from = (string)$this->config['from_address'];
        if (!mail($to, $subject, $mime['body'], $headerLines, '-f' . $from)) {
            throw new RuntimeException('A função mail() do servidor recusou a mensagem.');
        }
    }

    private function writeToLog(MailMessage $message, array $mime): void
    {
        $path = (string)$this->config['log_path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $entry = [
            'logged_at' => date('c'),
            'to' => $message->to,
            'subject' => $message->subject,
            'text' => $message->text,
            'html' => $message->html,
            'headers' => $mime['headers'],
        ];
        if (file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Não foi possível gravar o log de e-mail em {$path}.");
        }
    }
}
