<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

/**
 * Implementação TOTP RFC 6238 (HMAC-SHA1, 6 dígitos, passo 30s) sem dependências externas.
 * NI2-859 (SEG360-SEC-004).
 */
final class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(16, $bytes)));
    }

    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }
        $bits = '';
        $len = strlen($binary);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        $bitLen = strlen($bits);
        for ($i = 0; $i < $bitLen; $i += 5) {
            $chunk = str_pad(substr($bits, $i, 5), 5, '0', STR_PAD_RIGHT);
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($clean === '') {
            return '';
        }
        $bits = '';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::BASE32_ALPHABET, $clean[$i]);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        $bitLen = strlen($bits);
        for ($i = 0; $i + 8 <= $bitLen; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }

    public static function timeStep(?int $timestamp = null, int $period = 30): int
    {
        $ts = $timestamp ?? time();
        return intdiv(max(0, $ts), max(1, $period));
    }

    public static function codeForStep(string $secret, int $step, int $digits = 6): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            throw new \InvalidArgumentException('Segredo TOTP inválido.');
        }
        $high = ($step >> 32) & 0xFFFFFFFF;
        $low = $step & 0xFFFFFFFF;
        $counter = pack('NN', $high, $low);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $mod = 10 ** $digits;
        return str_pad((string)($truncated % $mod), $digits, '0', STR_PAD_LEFT);
    }

    public static function codeAt(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
    {
        return self::codeForStep($secret, self::timeStep($timestamp, $period), $digits);
    }

    public static function verifyCode(
        string $secret,
        string $code,
        int $window = 1,
        ?int $lastUsedStep = null,
        ?int &$matchedStep = null,
        ?int $timestamp = null,
        int $period = 30
    ): bool {
        $cleanCode = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (!preg_match('/^\d{6}$/', $cleanCode)) {
            return false;
        }
        $currentStep = self::timeStep($timestamp, $period);
        for ($delta = -$window; $delta <= $window; $delta++) {
            $step = $currentStep + $delta;
            if ($step < 0) {
                continue;
            }
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            $expected = self::codeForStep($secret, $step);
            if (hash_equals($expected, $cleanCode)) {
                $matchedStep = $step;
                return true;
            }
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $accountName, string $issuer = 'ClyoNutri'): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer)
        );
    }

    /**
     * @return array{plain:list<string>,hashes:list<string>}
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $plain[] = $code;
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return ['plain' => $plain, 'hashes' => $hashes];
    }

    public static function verifyRecoveryCode(string $input, array $hashes, ?int &$matchedIndex = null): bool
    {
        $clean = strtoupper(trim($input));
        if ($clean === '') {
            return false;
        }
        foreach ($hashes as $idx => $hash) {
            if (is_string($hash) && $hash !== '' && password_verify($clean, $hash)) {
                $matchedIndex = (int)$idx;
                return true;
            }
        }
        return false;
    }
}
