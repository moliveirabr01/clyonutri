<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

/**
 * NI2-604: caminho de retorno depois do login. Só caminho interno da aplicação: começa com "/", sem "//" nem "\",
 * sem esquema, sem quebras de linha; nunca /login, /logout, /api, /sessao ou rotas de cadastro e senha.
 */
final class SafeReturnPath
{
    public static function clean(?string $path): ?string
    {
        $path = trim((string)$path);
        if ($path === '' || strlen($path) > 500 || $path[0] !== '/' || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F\'"<>]/', $path) || preg_match('#^/[^/?]*:#', $path)) {
            return null;
        }
        $only = (string)(parse_url($path, PHP_URL_PATH) ?? '');
        if ($only === '' || preg_match('#^/(login|logout|sair|api/|sessao|cadastro|recuperar-senha|redefinir-senha|__)#', $only)) {
            return null;
        }
        return $path;
    }
}
