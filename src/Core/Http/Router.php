<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Http;

use ClyoNutri\Core\Security\Csrf;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path) . '$#',
            'handler' => $handler,
        ];
    }

    /** Formulário enviado por navegador (e não por fetch/API): vale mostrar uma página em vez de JSON. */
    private static function wantsHtmlPage(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        return str_contains($accept, 'text/html')
            && !str_contains($contentType, 'json')
            && strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') !== 0;
    }

    /** Token vencido ou ausente (sessão expirou, aba antiga, cookie bloqueado): explica e leva de volta à tela certa. */
    private static function renderExpiredFormPage(string $path): void
    {
        $isPublicPatientPath = $path === '/paciente/app' || str_starts_with($path, '/paciente/app/');
        $isPatient = $isPublicPatientPath || $path === '/app' || str_starts_with($path, '/app/');
        $backUrl = $isPublicPatientPath ? '/paciente/app' : ($isPatient ? '/app' : '/dashboard');
        $backLabel = $isPatient ? 'Voltar ao app do paciente' : 'Voltar ao painel';
        // UX-002: reopening is a GET; authorization and the consultation/patient relationship
        // are checked again there. An expired POST remains blocked and is never replayed.
        if (preg_match('~^/pacientes/([1-9][0-9]*)/(?:condicoes|protocolos)(?:/.*)?$~D', $path, $match)) {
            $origin=$_GET['atendimento']??$_POST['atendimento']??null;
            $unambiguous=!isset($_GET['atendimento'],$_POST['atendimento']) || $_GET['atendimento']===$_POST['atendimento'];
            if (is_string($origin) && preg_match('/^[1-9][0-9]{0,17}$/D',$origin) && $unambiguous) {
                $backUrl='/pacientes/'.$match[1].'/anamnese?atendimento='.$origin.'#anamnese-adaptativa';
                $backLabel='Reabrir a anamnese';
            }
        }
        // The professional pre-consultation list/detail is also a safe GET recovery target.
        if (preg_match('~^/pacientes/([1-9][0-9]*)/pre-consulta(?:/([1-9][0-9]*)(?:/[^?#]*)?)?$~D', $path, $match)) {
            $origin=$_GET['atendimento']??$_POST['atendimento']??null;
            $unambiguous=!isset($_GET['atendimento'],$_POST['atendimento']) || $_GET['atendimento']===$_POST['atendimento'];
            if (is_string($origin) && preg_match('/^[1-9][0-9]{0,17}$/D',$origin) && $unambiguous) {
                $backUrl='/pacientes/'.$match[1].'/pre-consulta'.(isset($match[2])?'/'.$match[2]:'').'?atendimento='.$origin;
                $backLabel='Reabrir a pré-consulta';
            }
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Sessão expirada · Clyo Nutri</title></head>'
            . '<body style="font-family: Inter, Arial, sans-serif; background: #f8fafc; color: #1f2937; margin: 0; padding: 24px;">'
            . '<main style="max-width: 460px; margin: 10vh auto 0; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 28px;">'
            . '<h1 style="font-size: 1.3rem; margin: 0 0 12px;">Sua sessão expirou</h1>'
            . '<p style="line-height: 1.5; margin: 0 0 20px;">Por segurança, não enviamos o formulário: a página ficou aberta por muito tempo ou a sessão foi encerrada. '
            . 'Nada foi salvo. Volte, confira os dados e tente de novo.</p>'
            . '<a href="' . htmlspecialchars($backUrl, ENT_QUOTES) . '" style="display: inline-block; background: #0f172a; color: #fff; text-decoration: none; padding: 12px 20px; border-radius: 8px; font-weight: 600;">'
            . htmlspecialchars($backLabel) . '</a>'
            . '</main></body></html>';
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Validação CSRF em requisições de mutação Web. Exceções: /api/ (bearer), /teleconsulta/sinal/
        // (credencial de mídia no cabeçalho) e /webhooks/asaas (NI2-727: endpoint público autenticado por
        // asaas-access-token em hash_equals; não usa sessão/cookie, portanto CSRF não se aplica).
        // F05: o paciente usa cookie + X-CSRF-Token, sem exceção.
        $csrfExempt = str_starts_with($path, '/api/')
            || str_starts_with($path, '/teleconsulta/sinal/')
            || $path === '/webhooks/asaas';
        if ($method === 'POST' && !$csrfExempt) {
            $token = $_POST['_csrf_token'] ?? $_POST['csrf_token'] ?? $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!Csrf::validate($token)) {
                http_response_code(403);
                if (self::wantsHtmlPage()) {
                    self::renderExpiredFormPage($path);
                    return;
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'CSRF token inválido ou ausente. Ação bloqueada por segurança.']);
                return;
            }
        }

        // HEAD usa a mesma rota do GET (o Apache/PHP descartam o corpo). Antes, HEAD caía em 404.
        $matchMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes as $route) {
            if ($route['method'] === $matchMethod && preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, fn($key) => !is_numeric($key), ARRAY_FILTER_USE_KEY);
                $handler = $route['handler'];

                if (is_callable($handler)) {
                    call_user_func($handler, $params);
                    return;
                }

                if (is_array($handler) && count($handler) === 2) {
                    [$class, $methodName] = $handler;
                    $controller = new $class();
                    $controller->$methodName($params);
                    return;
                }
            }
        }

        // v54.57 (UX-111): navegação no navegador recebe a página 404 com layout e caminho de volta; API/XHR, JSON.
        if (ErrorPage::wantsHtml()) {
            ErrorPage::render(404);
            return;
        }
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Rota não encontrada', 'path' => $path]);
    }
}
