<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Legal;

use PDO;

/**
 * NI2-449: verificação leve, a cada navegação do profissional, de que os Termos e a Política vigentes foram aceitos.
 *
 * - Só age quando o navegador abre uma página (GET de navegação: Sec-Fetch-Mode "navigate" ou, sem esse
 *   cabeçalho, Accept com text/html). Envio de formulário, fetch/polling e chamadas JSON passam: quem estava no
 *   meio de um prontuário ou de uma conversa não perde nada; a tela de aceite aparece na próxima página aberta.
 * - Nunca bloqueia: /aceite-termos, /logout, exportações (prontuário, pedidos LGPD) e a assinatura
 *   (cancelamento continua possível).
 * - Equipe da plataforma (suporte, console) não passa pela verificação.
 * - Depois de conferir, guarda na sessão a combinação usuário + versões; só consulta o banco de novo quando a
 *   versão de algum texto muda.
 * - Se a tabela ainda não existir (pacote no ar antes do SQL), deixa passar e registra no log.
 * - Desligada por padrão: só age com LEGAL_REACCEPTANCE_ENABLED=true no .env. Os Termos são rascunho para
 *   revisão de um advogado; exigir aceite de todos antes dessa revisão seria pedir aceite de um texto que vai
 *   mudar. O aceite no cadastro e no primeiro acesso continua sendo gravado com a flag desligada.
 */
final class LegalAcceptanceGate
{
    public const SESSION_OK = 'legal_acceptance_ok';
    public const SESSION_RETURN = 'legal_acceptance_return_to';

    /** Retorna '/aceite-termos' quando a navegação deve ser desviada; null para seguir. */
    public static function enabled(): bool
    {
        return filter_var(\ClyoNutri\Core\Security\Env::get('LEGAL_REACCEPTANCE_ENABLED') ?? false, FILTER_VALIDATE_BOOL);
    }

    public static function redirectFor(PDO $db, int $userId, bool $platformStaff, string $method, string $requestUri, bool $wantsJson, ?array $server = null): ?string
    {
        if (!self::enabled() || $platformStaff || $wantsJson || strtoupper($method) !== 'GET' || !self::isPageNavigation($server ?? $_SERVER)) {
            return null;
        }
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        if (self::isExempt($path)) {
            return null;
        }
        $marker = self::marker($userId);
        if (($_SESSION[self::SESSION_OK] ?? null) === $marker) {
            return null;
        }
        try {
            $pending = (new LegalAcceptanceService($db))->pendingUserDocuments($userId);
        } catch (\PDOException $e) {
            error_log('[aceite-termos] verificação ignorada (rodou o SQL da migração 56?): ' . $e->getMessage());
            return null;
        }
        if ($pending === []) {
            $_SESSION[self::SESSION_OK] = $marker;
            return null;
        }
        $_SESSION[self::SESSION_RETURN] = $requestUri;
        return '/aceite-termos';
    }

    /** Abertura de página pelo navegador (não fetch/XHR nem cliente de API). */
    public static function isPageNavigation(array $server): bool
    {
        $mode = strtolower((string)($server['HTTP_SEC_FETCH_MODE'] ?? ''));
        if ($mode !== '') {
            $dest = strtolower((string)($server['HTTP_SEC_FETCH_DEST'] ?? 'document'));
            return $mode === 'navigate' && in_array($dest, ['document', ''], true);
        }
        return str_contains(strtolower((string)($server['HTTP_ACCEPT'] ?? '')), 'text/html');
    }

    public static function isExempt(string $path): bool
    {
        $path = rtrim($path, '/') ?: '/';
        if (in_array($path, ['/aceite-termos', '/logout', '/termos', '/privacidade'], true)) {
            return true;
        }
        if (str_starts_with($path, '/configuracoes/assinatura')) {
            return true;
        }
        // Exportações de dados (prontuário, pedidos LGPD): nunca bloqueadas.
        return (bool)preg_match('#/(exportar|export)(/|$)#', $path);
    }

    public static function marker(int $userId): string
    {
        return $userId . '@' . implode('|', LegalDocuments::currentVersions());
    }

    /** Destino depois do aceite: só caminho interno, nunca outro domínio. */
    public static function safeReturn(?string $uri): string
    {
        $uri = (string)$uri;
        if ($uri === '' || $uri[0] !== '/' || str_starts_with($uri, '//') || str_contains($uri, '\\') || preg_match('/[\r\n]/', $uri)) {
            return '/dashboard';
        }
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return ($path === '/aceite-termos') ? '/dashboard' : $uri;
    }
}
