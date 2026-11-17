<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Security;

use PDO;
use RuntimeException;

/**
 * Serviço de Criptografia Simétrica Autenticada para Dados Clínicos em Repouso (NI2-480 / SEC-02 / NI2-716).
 *
 * - Implementa AES-256-GCM (NIST SP 800-38D) com vetor de inicialização (IV) de 12 bytes
 *   aleatório por texto cifrado e tag de integridade de 16 bytes (128 bits).
 * - Chave de 256 bits derivada via HKDF-SHA256 a partir de CLINICAL_ENCRYPTION_KEY.
 * - Formato serializado: `enc:v1:<base64(iv . tag . ciphertext)>`.
 * - Descriptografia transparente e retrocompatível: dados legados em texto claro são
 *   retornados diretamente, garantindo migração sem interrupção de serviço (zero downtime).
 *
 * v54.45 / NI2-716 / CR-NI2-001:
 * - Em produção (APP_ENV=production|prod) CLINICAL_ENCRYPTION_KEY é obrigatória (fail-closed).
 * - Sem fallback para APP_KEY, JWT_SECRET ou chave hardcoded em produção.
 * - Fora de produção: CLINICAL_ENCRYPTION_KEY → APP_KEY → marcador DEV-ONLY explícito.
 */
final class ClinicalEncryptionService
{
    public const CIPHER = 'aes-256-gcm';
    public const PREFIX = 'enc:v1:';
    public const IV_LENGTH = 12; // 96 bits recomendado para GCM
    public const TAG_LENGTH = 16; // 128 bits de integridade

    /** Marcador óbvio de desenvolvimento — NUNCA usado quando APP_ENV=production|prod. */
    public const DEV_ONLY_FALLBACK_KEY = 'clyonutri-DEV-ONLY-clinical-key-not-for-production';

    public const ANAMNESIS_ENCRYPTED_FIELDS = [
        'chief_complaint',
        'objectives',
        'family_history',
        'pathologies',
        'surgeries_details',
        'medications_details',
        'supplements_details',
        'routine',
        'food_preferences',
        'restrictions',
        'allergies',
        'symptoms',
        'habits',
        'alcohol_details',
        'smoking_details',
        'sleep_notes',
        'referenced_exams',
        'clinical_history',
        'notes',
        // v54.46 / CR2-NI2-001: o snapshot JSON duplica todo o conteúdo clínico — cifrado junto.
        'structured_data_json',
    ];

    public const PATIENT_ENCRYPTED_FIELDS = [
        'notes',
        'allergies',
    ];

    public static function decryptPatientRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (self::PATIENT_ENCRYPTED_FIELDS as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = self::decrypt($row[$field]);
            }
        }
        return $row;
    }

    /**
     * v54.68: para listagens (lista de pacientes, painel), um registro que não decifra (chave trocada,
     * dado corrompido) não pode derrubar a página inteira. O campo vira null e o erro vai para o log.
     * Telas clínicas e a trava de alérgenos continuam usando decryptPatientRow (falha fechada).
     */
    public static function decryptPatientRowForListing(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (self::PATIENT_ENCRYPTED_FIELDS as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field]) && $row[$field] !== '') {
                try {
                    $row[$field] = self::decrypt($row[$field]);
                } catch (\Throwable $e) {
                    error_log('[clinical-encryption] campo ' . $field . ' ilegível na listagem (paciente ' . (int)($row['id'] ?? 0) . ')');
                    $row[$field] = null;
                }
            }
        }
        return $row;
    }

    private static ?string $overrideKey = null;

    /**
     * v54.46 / CR2-NI2-001: campos cifrados que existem de fato na tabela (bancos antigos sem
     * structured_data_json continuam funcionando no batch e no healthcheck).
     * @return list<string>
     */
    public static function anamnesisFieldsPresent(PDO $db): array
    {
        $out = [];
        foreach (self::ANAMNESIS_ENCRYPTED_FIELDS as $f) {
            try { $db->query("SELECT {$f} FROM anamneses LIMIT 0"); $out[] = $f; } catch (\Throwable $e) {}
        }
        return $out;
    }

    /**
     * Permite injetar uma chave customizada em runtime (ex: testes unitários e migração).
     */
    public static function setKeyForTesting(?string $key): void
    {
        self::$overrideKey = $key;
    }

    public static function isProductionEnv(): bool
    {
        // Fail-closed só com APP_ENV=production|prod explícito (AC NI2-716).
        // Ausência de APP_ENV ≠ produção — evita quebrar fixtures/seeds isolados.
        $env = strtolower(trim((string)(Env::get('APP_ENV') ?? '')));
        return in_array($env, ['production', 'prod'], true);
    }

    /**
     * Resolve uma chave de 32 bytes (256 bits) para o AES-256-GCM.
     */
    public static function resolveKey(): string
    {
        if (self::$overrideKey !== null) {
            return hash_hkdf('sha256', self::$overrideKey, 32, 'clyonutri_clinical_encryption_v1');
        }

        $rawKey = (string)(Env::get('CLINICAL_ENCRYPTION_KEY') ?? '');
        if ($rawKey === '') {
            if (self::isProductionEnv()) {
                throw new RuntimeException(
                    'CLINICAL_ENCRYPTION_KEY é obrigatória em produção. Sem fallback para APP_KEY/JWT_SECRET.'
                );
            }
            // Não-prod: APP_KEY se existir; senão marcador DEV-ONLY documentado (nunca em produção).
            $rawKey = (string)(Env::get('APP_KEY') ?? '');
            if ($rawKey === '') {
                $rawKey = self::DEV_ONLY_FALLBACK_KEY;
            }
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
     * v54.68 / N-01: textos gravados pela v54.67 podem conter um valor cifrado embutido (ex.: alerta de
     * alérgeno "não mapeada ('enc:v1:…')"). Decifra cada ocorrência para exibição; se não decifrar,
     * troca por um marcador neutro — nunca devolve o texto cifrado para a tela.
     */
    public static function decryptEmbedded(?string $text): ?string
    {
        if ($text === null || !str_contains($text, self::PREFIX)) {
            return $text;
        }
        return preg_replace_callback('#enc:v1:[A-Za-z0-9+/=]+#', static function (array $m): string {
            try {
                return (string)self::decrypt($m[0]);
            } catch (\Throwable) {
                return '[conteúdo protegido]';
            }
        }, $text);
    }

    /** Aplica decryptEmbedded em todas as strings de um array decodificado de JSON (snapshots, alertas). */
    public static function decryptEmbeddedDeep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::decryptEmbedded($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::decryptEmbeddedDeep($v);
            }
        }
        return $value;
    }

    /**
     * Criptografa os campos clínicos de uma linha de anamnese antes da persistência.
     */
    public static function encryptAnamnesisRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach (self::ANAMNESIS_ENCRYPTED_FIELDS as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field])) {
                $row[$field] = self::encrypt($row[$field]);
            }
        }

        return $row;
    }

    /**
     * Descriptografa transparentemente os campos clínicos de uma linha de anamnese recuperada do banco.
     */
    public static function decryptAnamnesisRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        // v54.57 (UX-103): um campo cifrado ilegível (payload corrompido ou chave trocada) não derruba mais a tela
        // inteira. O campo volta vazio, a linha leva a lista em "_unreadable_fields" e o fato vai para o log
        // (sem conteúdo clínico). Quem grava por cima de uma linha assim precisa tratar isso explicitamente
        // (AnamnesisService recusa sobrescrever um rascunho ilegível sem o descarte auditado).
        $unreadable = [];
        foreach (self::ANAMNESIS_ENCRYPTED_FIELDS as $field) {
            if (array_key_exists($field, $row) && is_string($row[$field])) {
                try {
                    $row[$field] = self::decrypt($row[$field]);
                } catch (RuntimeException $e) {
                    $row[$field] = null;
                    $unreadable[] = $field;
                }
            }
        }
        if ($unreadable !== []) {
            $row['_unreadable_fields'] = $unreadable;
            error_log((string)json_encode([
                'event' => 'ANAMNESIS_FIELD_UNREADABLE',
                'anamnesis_id' => isset($row['id']) ? (int)$row['id'] : null,
                'clinic_id' => isset($row['clinic_id']) ? (int)$row['clinic_id'] : null,
                'patient_id' => isset($row['patient_id']) ? (int)$row['patient_id'] : null,
                'is_draft' => isset($row['is_draft']) ? (int)$row['is_draft'] : null,
                'fields' => $unreadable,
            ]));
        }

        return $row;
    }

    /**
     * v54.57 (UX-103): campos cifrados de uma linha crua que não podem ser lidos com a chave atual.
     * @return list<string>
     */
    public static function unreadableAnamnesisFields(?array $row): array
    {
        if ($row === null) {
            return [];
        }
        $out = [];
        foreach (self::ANAMNESIS_ENCRYPTED_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && self::isEncrypted($row[$field])) {
                try {
                    self::decrypt($row[$field]);
                } catch (RuntimeException $e) {
                    $out[] = $field;
                }
            }
        }
        return $out;
    }

    /**
     * Descriptografa uma lista de linhas de anamnese (ex: histórico clínico).
     */
    public static function decryptAnamnesisRows(array $rows): array
    {
        return array_map([self::class, 'decryptAnamnesisRow'], $rows);
    }

    /**
     * Contagem barata de cobertura enc:v1 nos campos cifrados (para healthcheck CLI).
     * @return array{total:int,encrypted:int,plaintext:int,pct_encrypted:float}|null
     */
    public static function anamnesisCoverageStats(PDO $db): ?array
    {
        try {
            $fields = self::anamnesisFieldsPresent($db);
            if ($fields === []) {
                return null;
            }
            $parts = [];
            foreach ($fields as $f) {
                $parts[] = "CASE WHEN {$f} IS NOT NULL AND {$f} <> '' THEN 1 ELSE 0 END";
            }
            $nonEmptyExpr = '(' . implode(' + ', $parts) . ')';
            $encParts = [];
            foreach ($fields as $f) {
                $encParts[] = "CASE WHEN {$f} LIKE 'enc:v1:%' THEN 1 ELSE 0 END";
            }
            $encExpr = '(' . implode(' + ', $encParts) . ')';
            $st = $db->query("SELECT COUNT(*) AS rows_total,
                COALESCE(SUM({$nonEmptyExpr}),0) AS field_slots,
                COALESCE(SUM({$encExpr}),0) AS enc_slots
                FROM anamneses");
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row) {
                return null;
            }
            $slots = (int)$row['field_slots'];
            $enc = (int)$row['enc_slots'];
            return [
                'total' => (int)$row['rows_total'],
                'encrypted' => $enc,
                'plaintext' => max(0, $slots - $enc),
                'pct_encrypted' => $slots > 0 ? round(100.0 * $enc / $slots, 1) : 100.0,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
