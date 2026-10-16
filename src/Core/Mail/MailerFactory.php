<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

use ClyoNutri\Core\Security\Env;

/**
 * Cria o Mailer a partir do ambiente.
 *
 * MAIL_DRIVER (smtp|mail|log|null) — nome já usado nos .env.example/.env.hostgator.example.
 * MAIL_MAILER é aceito como sinônimo (MAIL_DRIVER tem precedência).
 * MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION (ssl|tls|none), MAIL_USERNAME, MAIL_PASSWORD,
 * MAIL_FROM_ADDRESS (padrão: MAIL_USERNAME), MAIL_FROM_NAME, MAIL_TIMEOUT (s, padrão 15),
 * MAIL_VERIFY_PEER (true|false, padrão true), MAIL_LOG_PATH (driver log).
 *
 * Sem driver: "log" fora de produção; em produção o envio FALHA de forma explícita
 * (auditado como PASSWORD_RESET_EMAIL_FAILED) — nunca finge que enviou.
 */
final class MailerFactory
{
    public static function fromEnv(): Mailer
    {
        $c = self::config();
        $problems = self::problems($c);
        if ($problems) {
            throw new MailException('E-mail não configurado: ' . implode('; ', $problems) . '.');
        }

        return match ($c['driver']) {
            'smtp', 'mail' => new SmtpMailer(
                host: (string)$c['host'],
                port: $c['port'],
                username: (string)$c['username'],
                password: (string)$c['password'],
                encryption: $c['encryption'],
                fromAddress: (string)$c['from_address'],
                fromName: $c['from_name'],
                timeoutSeconds: $c['timeout'],
                verifyPeer: $c['verify_peer'],
                transport: $c['driver'],
            ),
            'log' => new LogMailer($c['log_path']),
            'null', 'none' => new NullMailer(),
        };
    }

    /**
     * Resumo da configuração, sem segredos (healthcheck e bin/test_mail.php).
     * @return array{driver:?string,host:?string,port:int,encryption:string,from:?string,delivers:bool,problems:string[]}
     */
    public static function describe(): array
    {
        $c = self::config();
        return [
            'driver' => $c['driver'],
            'host' => $c['host'],
            'port' => $c['port'],
            'encryption' => $c['encryption'],
            'from' => $c['from_address'],
            'delivers' => in_array($c['driver'], ['smtp', 'mail'], true),
            'problems' => self::problems($c),
        ];
    }

    private static function env(string $key, ?string $default = null): ?string
    {
        $v = Env::get($key);
        return ($v === null || trim((string)$v) === '') ? $default : trim((string)$v);
    }

    private static function config(): array
    {
        $production = strtolower((string)self::env('APP_ENV', 'production')) === 'production';
        $driver = self::env('MAIL_DRIVER') ?? self::env('MAIL_MAILER');
        $driver = $driver !== null ? strtolower($driver) : ($production ? null : 'log');
        $encryption = strtolower((string)self::env('MAIL_ENCRYPTION', 'ssl'));
        if (in_array($encryption, ['none', 'null', 'false', 'off'], true)) {
            $encryption = '';
        }
        $defaultPort = $encryption === 'tls' ? '587' : ($encryption === 'ssl' ? '465' : '25');

        return [
            'driver' => $driver,
            'host' => self::env('MAIL_HOST'),
            'port' => (int)self::env('MAIL_PORT', $defaultPort),
            'encryption' => $encryption,
            'username' => self::env('MAIL_USERNAME'),
            'password' => self::env('MAIL_PASSWORD'),
            'from_address' => self::env('MAIL_FROM_ADDRESS', self::env('MAIL_USERNAME')),
            'from_name' => (string)self::env('MAIL_FROM_NAME', self::env('APP_NAME', 'Clyo Nutri')),
            'timeout' => max(1, (int)self::env('MAIL_TIMEOUT', '15')),
            'verify_peer' => !in_array(strtolower((string)self::env('MAIL_VERIFY_PEER', 'true')), ['0', 'false', 'no', 'off'], true),
            'log_path' => self::env('MAIL_LOG_PATH'),
        ];
    }

    /** @return string[] */
    private static function problems(array $c): array
    {
        $p = [];
        if ($c['driver'] === null) {
            return ['MAIL_DRIVER não definido (use smtp em produção)'];
        }
        if (!in_array($c['driver'], ['smtp', 'mail', 'log', 'null', 'none'], true)) {
            return ["MAIL_DRIVER inválido: {$c['driver']} (use smtp, mail, log ou null)"];
        }
        if (in_array($c['driver'], ['smtp', 'mail'], true) && !class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $p[] = 'PHPMailer não instalado (rode composer install e envie vendor/)';
        }
        if ($c['driver'] === 'smtp') {
            foreach (['host' => 'MAIL_HOST', 'username' => 'MAIL_USERNAME', 'password' => 'MAIL_PASSWORD'] as $k => $name) {
                if (empty($c[$k])) {
                    $p[] = "{$name} não definido";
                }
            }
            if (!in_array($c['encryption'], ['ssl', 'tls', ''], true)) {
                $p[] = 'MAIL_ENCRYPTION deve ser ssl, tls ou none';
            }
        }
        if (in_array($c['driver'], ['smtp', 'mail'], true) && !filter_var((string)$c['from_address'], FILTER_VALIDATE_EMAIL)) {
            $p[] = 'MAIL_FROM_ADDRESS inválido ou ausente';
        }
        return $p;
    }
}
