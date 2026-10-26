<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use RuntimeException;

/**
 * Serviço de Criptografia Simétrica Autenticada para Dados Clínicos em Repouso (NI2-480 / SEC-02).
 *
 * - Implementa AES-256-GCM (NIST SP 800-38D) com vetor de inicialização (IV) de 12 bytes
 *   aleatório por texto cifrado e tag de integridade de 16 bytes (128 bits).
 * - Chave de 256 bits derivada via HKDF-SHA256 a partir de CLINICAL_ENCRYPTION_KEY / APP_KEY.
 * - Formato serializado: `enc:v1:<base64(iv . tag . ciphertext)>`.
 * - Descriptografia transparente e retrocompatível: dados legados em texto claro são
 *   retornados diretamente, garantindo migração sem interrupção de serviço (zero downtime).
 */
final class ClinicalEncryptionService
{
    public const CIPHER = 'aes-256-gcm';
    public const PREFIX = 'enc:v1:';
    public const IV_LENGTH = 12; // 96 bits recomendado para GCM
    public const TAG_LENGTH = 16; // 128 bits de integridade

    public const ANAMNESIS_ENCRYPTED_FIELDS = [
        'objectives',
        'routine',
        'food_preferences',
        'restrictions',
        'allergies',
        'symptoms',
        'habits',
        'clinical_history',
        'notes',
    ];

    private static ?string $overrideKey = null;

    /**
     * Permite injetar uma chave customizada em runtime (ex: testes unitários e migração).
     */
    public static function setKeyForTesting(?string $key): void
    {
        self::$overrideKey = $key;
    }

    /**
     * Resolve uma chave de 32 bytes (256 bits) para o AES-256-GCM.
     */
    public static function resolveKey(): string
    {
        if (self::$overrideKey !== null) {
            return hash_hkdf('sha256', self::$overrideKey, 32, 'clyonutri_clinical_encryption_v1');
        }

        $rawKey = (string)(Env::get('CLINICAL_ENCRYPTION_KEY') ?? Env::get('APP_KEY') ?? Env::get('JWT_SECRET') ?? '');
        if ($rawKey === '') {
            $rawKey = 'clyonutri_dev_clinical_default_key_2026';
        }

        return hash_hkdf('sha256', $rawKey, 32, 'clyonutri_clinical_encryption_v1');
    }

    /**
     * Verifica se uma string já está no formato criptografado.
     */
    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    /**
     * Criptografa um texto claro usando AES-256-GCM.
     * Se o valor for null ou vazio, retorna o valor original.
     * Se o valor já estiver criptografado, retorna sem dupla encriptação.
     */
    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        if (self::isEncrypted($plaintext)) {
            return $plaintext;
        }

        $key = self::resolveKey();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Falha na cifragem AES-256-GCM: erro interno do OpenSSL.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Descriptografa um texto cifrado AES-256-GCM.
     * Se o valor não possuir o prefixo `enc:v1:`, assume texto legado e retorna como está (retrocompatibilidade).
     */
    public static function decrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null || $ciphertext === '') {
            return $ciphertext;
        }

        if (!self::isEncrypted($ciphertext)) {
            // Texto claro legado (sem migração ainda)
            return $ciphertext;
        }

        $raw = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);
        $minLen = self::IV_LENGTH + self::TAG_LENGTH;
        if ($raw === false || strlen($raw) < $minLen) {
            throw new RuntimeException('Payload criptografado corrompido ou truncado.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $data = substr($raw, $minLen);

        $key = self::resolveKey();
        $plaintext = openssl_decrypt(
            $data,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Falha de autenticação na descriptografia: dados corrompidos ou chave incorreta.');
        }

        return $plaintext;
    }

    /**
     * Descriptografa transparentemente os campos clínicos de uma linha de anamnese recuperada do banco.
     */
    public static function decryptAnamnesisRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach (self::ANAMNESIS_ENCRYPTED_FIELDS as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field])) {
                $row[$field] = self::decrypt($row[$field]);
            }
        }

        return $row;
    }

    /**
     * Descriptografa uma lista de linhas de anamnese (ex: histórico clínico).
     */
    public static function decryptAnamnesisRows(array $rows): array
    {
        return array_map([self::class, 'decryptAnamnesisRow'], $rows);
    }
}
