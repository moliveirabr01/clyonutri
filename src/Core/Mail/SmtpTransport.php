<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Mail;

use RuntimeException;

/**
 * Cliente SMTP mínimo (RFC 5321): SSL implícito (465) ou STARTTLS (587), AUTH LOGIN/PLAIN,
 * dot-stuffing e verificação de cada resposta do servidor. Sem dependências externas.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private int $timeout = 15,
        private bool $verifyPeer = true,
    ) {
    }

    /** @param array<string,string> $headers */
    public function send(string $from, string $to, array $headers, string $body): void
    {
        try {
            $this->connect();
            $this->expect([220]);
            $ehlo = $this->ehlo();
            if ($this->encryption === 'tls') {
                if (!str_contains($ehlo, 'STARTTLS')) {
                    throw new RuntimeException('O servidor SMTP não oferece STARTTLS. Use MAIL_ENCRYPTION=ssl e a porta 465.');
                }
                $this->command('STARTTLS', [220]);
                $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!@stream_socket_enable_crypto($this->socket, true, $crypto)) {
                    throw new RuntimeException('Falha ao iniciar TLS com o servidor SMTP (verifique MAIL_HOST: o certificado precisa corresponder ao nome).');
                }
                $ehlo = $this->ehlo();
            }
            if ($this->username !== '') {
                if (preg_match('/AUTH[ =][^\r\n]*\bLOGIN\b/i', $ehlo)) {
                    $this->command('AUTH LOGIN', [334]);
                    $this->command(base64_encode($this->username), [334], 'AUTH usuário');
                    $this->command(base64_encode($this->password), [235], 'AUTH senha');
                } elseif (preg_match('/AUTH[ =][^\r\n]*\bPLAIN\b/i', $ehlo)) {
                    $this->command('AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$this->password}"), [235], 'AUTH PLAIN');
                } else {
                    throw new RuntimeException('O servidor SMTP não oferece AUTH LOGIN/PLAIN nesta conexão.');
                }
            }
            $this->command("MAIL FROM:<{$from}>", [250]);
            $this->command("RCPT TO:<{$to}>", [250, 251]);
            $this->command('DATA', [354]);

            $data = '';
            foreach ($headers as $name => $value) {
                $data .= "{$name}: {$value}\r\n";
            }
            $data .= "\r\n" . preg_replace('/(^|\r\n)\./', '$1..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $body));
            if (!str_ends_with($data, "\r\n")) {
                $data .= "\r\n";
            }
            $this->write($data . ".\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            $reason = trim((string)$errstr) !== '' ? $errstr
                : ($scheme === 'ssl' ? 'falha no TLS: o certificado do servidor não corresponde a MAIL_HOST ou não é confiável' : 'sem resposta');
            throw new RuntimeException("Não foi possível conectar a {$this->host}:{$this->port} ({$reason}).");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function ehlo(): string
    {
        $name = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)(gethostname() ?: 'localhost')) ?: 'localhost';
        $this->write("EHLO {$name}\r\n");
        return $this->expect([250]);
    }

    private function command(string $line, array $okCodes, ?string $label = null): string
    {
        $this->write($line . "\r\n");
        return $this->expect($okCodes, $label ?? strtok($line, ' '));
    }

    private function write(string $data): void
    {
        $len = strlen($data);
        for ($written = 0; $written < $len; $written += $n) {
            $n = fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Conexão SMTP interrompida durante o envio.');
            }
        }
    }

    private function expect(array $okCodes, string $step = 'conexão'): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $meta = stream_get_meta_data($this->socket);
        if ($response === '' || !empty($meta['timed_out'])) {
            throw new RuntimeException("O servidor SMTP não respondeu ({$step}). Confira MAIL_PORT e MAIL_ENCRYPTION: 465 usa ssl, 587 usa tls.");
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            $reason = trim(preg_replace('/\s+/', ' ', $response));
            if ($step === 'AUTH senha' || $step === 'AUTH PLAIN') {
                $reason = "{$code} autenticação recusada (confira MAIL_USERNAME e MAIL_PASSWORD)";
            }
            throw new RuntimeException("SMTP recusou {$step}: {$reason}");
        }
        return $response;
    }
}
