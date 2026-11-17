<?php
declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Security\ClinicalAuthorization;

/**
 * v54.57 (UX-104, UX-111, R55-01): página de erro única para navegação no navegador.
 *
 *  - Pedido de página (Accept text/html, sem JSON nem XHR, fora de /api/) recebe HTML com layout, motivo e caminho de
 *    volta. Com sessão do consultório, usa o layout do consultório (menu filtrado pelo perfil); sem sessão, uma página
 *    simples do DS com links de saída.
 *  - API, fetch e XHR continuam recebendo JSON (o chamador decide quando usar).
 *  - Nunca mostra stack trace, caminho de arquivo ou dado de outra clínica.
 */
final class ErrorPage
{
    public static function wantsHtml(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;
        $path = parse_url((string)($s['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/api/')) {
            return false;
        }
        $accept = (string)($s['HTTP_ACCEPT'] ?? '');
        $contentType = (string)($s['CONTENT_TYPE'] ?? $s['HTTP_CONTENT_TYPE'] ?? '');
        return str_contains($accept, 'text/html')
            && !str_contains($contentType, 'json')
            && !isset($s['HTTP_X_CSRF_TOKEN'])
            && strcasecmp((string)($s['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') !== 0;
    }

    /** Textos padrão por código. */
    public static function defaults(int $status): array
    {
        return match ($status) {
            401 => ['Entre para continuar', 'Sua sessão terminou ou você ainda não entrou. Entre de novo para abrir esta página.'],
            403 => ['Acesso não permitido', 'Seu perfil nesta clínica não tem acesso a esta página. Se precisar dela, peça a quem administra a clínica.'],
            404 => ['Página não encontrada', 'O endereço não existe ou foi digitado errado. Confira o link ou volte para uma das páginas abaixo.'],
            default => ['Não foi possível concluir a solicitação', 'Algo falhou do nosso lado. Nada foi alterado. Tente novamente em instantes.'],
        };
    }

    /**
     * @param list<array{0:string,1:string}> $actions [rótulo, url] (urls internas)
     */
    public static function render(int $status, ?string $heading = null, ?string $message = null, array $actions = [], ?string $ref = null, bool $allowShell = true): void
    {
        [$h, $m] = self::defaults($status);
        $heading ??= $h;
        $message ??= $m;
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $isPatient = $path === '/app' || str_starts_with($path, '/app/') || str_starts_with($path, '/paciente/') || str_starts_with($path, '/pre-consulta');
        $actor = null;
        if ($allowShell && !$isPatient && TenantContext::hasClinic() && TenantContext::getUserId()) {
            try {
                $actor = (AuthContext::get() ?? AuthContext::resolveFromSession())?->toArray();
            } catch (\Throwable $e) {
                $actor = null;
            }
        }
        if ($actions === []) {
            $actions = self::defaultActions($actor, $isPatient);
        }
        if ($actor !== null) {
            $actions = array_values(array_filter($actions, static fn(array $a): bool => self::allowed($actor, $a[1])));
        }
        $back = self::sameOriginReferer();
        $body = self::card($status, $heading, $message, $actions, $back, $ref);
        if ($actor !== null) {
            $level = ob_get_level();
            try {
                $title = $heading;
                $activeNav = '';
                $content = $body;
                ob_start();
                require dirname(__DIR__, 3) . '/views/layouts/professional-shell.php';
                $html = (string)ob_get_clean();
                echo $html;
                return;
            } catch (\Throwable $e) {
                while (ob_get_level() > $level) {
                    @ob_end_clean();
                }
                error_log('[ErrorPage] layout indisponível: ' . get_class($e));
            }
        }
        echo self::standalone($heading, $body);
    }

    /** @return list<array{0:string,1:string}> */
    private static function defaultActions(?array $actor, bool $isPatient): array
    {
        if ($isPatient) {
            return [['Ir para o portal do paciente', '/paciente/app']];
        }
        if ($actor !== null) {
            // v54.68: perfis de plataforma também recebem um caminho (o filtro allowed() tira o que o perfil não abre).
            return [['Ir para o painel', '/dashboard'], ['Ir para a agenda', '/agenda'], ['Ver pacientes', '/pacientes'], ['Ir para o console administrativo', '/admin/dashboard'], ['Ir para a fila de suporte', '/suporte/fila']];
        }
        return [['Entrar', '/login'], ['Página inicial', '/']];
    }

    /** O perfil pode abrir este destino? (mesma regra do servidor; evita oferecer um caminho que dá 403). */
    public static function allowed(array $actor, string $url): bool
    {
        $p = parse_url($url, PHP_URL_PATH) ?: '/';
        return ClinicalAuthorization::allowsWeb($actor, $p, 'GET');
    }

    private static function sameOriginReferer(): ?string
    {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref === '') {
            return null;
        }
        $r = parse_url($ref);
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!is_array($r) || !isset($r['host']) || $host === '' || strcasecmp($r['host'] . (isset($r['port']) ? ':' . $r['port'] : ''), $host) !== 0) {
            return null;
        }
        $path = ($r['path'] ?? '/') . (isset($r['query']) ? '?' . $r['query'] : '');
        $current = (string)($_SERVER['REQUEST_URI'] ?? '');
        return $path === $current ? null : $path;
    }

    private static function card(int $status, string $heading, string $message, array $actions, ?string $back, ?string $ref): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '<section class="op-message op-message--error card error-page" role="alert" data-testid="error-page" data-status="' . $status . '">'
            . '<p class="cr-caption">Erro ' . $status . '</p>'
            . '<h1>' . $e($heading) . '</h1><p>' . $e($message) . '</p>';
        if ($ref !== null && $ref !== '') {
            $html .= '<p class="cr-caption">Código para o suporte: <strong>' . $e($ref) . '</strong></p>';
        }
        $html .= '<div class="error-page__actions">';
        foreach ($actions as $i => [$label, $url]) {
            $html .= '<a class="btn ' . ($i === 0 ? 'btn-primary' : 'btn-secondary') . '" href="' . $e($url) . '">' . $e($label) . '</a> ';
        }
        if ($back !== null) {
            $html .= '<a class="btn btn-secondary" href="' . $e($back) . '" data-testid="error-page-back">Voltar à página anterior</a>';
        }
        return $html . '</div></section>';
    }

    private static function standalone(string $heading, string $body): string
    {
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . ' · Clyo Nutri</title>'
            . '<link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">'
            . '<link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=botanical-v54-9">'
            . '<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">'
            . '<link rel="stylesheet" href="/assets/css/ds-components.css?v=v54-47">'
            . '<link rel="stylesheet" href="/assets/css/ux-v54-57.css?v=v54-66">'
            . '</head><body class="error-standalone"><header class="error-standalone__header"><a href="/"><img src="/assets/clyonutri-logo-dark.svg?v=botanical-v54-9" alt="Clyo Nutri" width="160" height="40"></a></header>'
            . '<main id="conteudo" class="error-standalone__main">' . $body . '</main></body></html>';
    }
}
