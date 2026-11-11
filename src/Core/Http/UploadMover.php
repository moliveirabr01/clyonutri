<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use ClyoNutri\Core\Security\ClinicalEncryptionService;

/**
 * v54.46 / CR-NI2-011 (NI2-725): única porta para mover arquivo enviado para o storage.
 *
 * - Requisição web: só move_uploaded_file() de arquivo que o PHP recebeu via POST (is_uploaded_file).
 * - copy() de caminho arbitrário só é aceito quando copyFallbackAllowed(): processo CLI (testes/
 *   scripts internos, sem requisição HTTP) E fora de produção. Servidor web (apache/fpm/cli-server)
 *   nunca usa copy(), mesmo que o tmp_name venha forjado.
 */
final class UploadMover
{
    public static function copyFallbackAllowed(): bool
    {
        return PHP_SAPI === 'cli' && !ClinicalEncryptionService::isProductionEnv();
    }

    public static function move(string $tmp, string $dest): bool
    {
        if ($tmp === '') {
            return false;
        }
        if (is_uploaded_file($tmp)) {
            return @move_uploaded_file($tmp, $dest);
        }
        if (!self::copyFallbackAllowed() || !is_file($tmp)) {
            return false;
        }
        return @copy($tmp, $dest);
    }
}
