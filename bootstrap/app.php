<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Shim de bootstrap no estilo Laravel (usado só pelo artisan local).
 * AVISO (v54.46 / CR-NI2-007): o ClyoNutri NÃO roda Laravel nem Sanctum. O roteador real de produção é
 * public/index.php (roteador PHP próprio). Este arquivo é um shim declarativo carregado só pelo `artisan`
 * local; não aplica autenticação, CSRF nem tenant em requisições HTTP.
 * Configura o kernel de roteamento, middleware multitenant e exceções da aplicação.
 */

namespace App\Bootstrap;

class Application
{
    private string $basePath;
    private array $config = [];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public static function configure(string $basePath): self
    {
        return new self($basePath);
    }

    public function withRouting(
        string $web,
        string $api,
        ?string $commands = null,
        ?string $health = null
    ): self {
        $this->config['routes'] = [
            'web' => $web,
            'api' => $api,
            'commands' => $commands,
            'health' => $health,
        ];
        return $this;
    }

    public function withMiddleware(callable $callback): self
    {
        $this->config['middleware'] = $callback;
        return $this;
    }

    public function withExceptions(callable $callback): self
    {
        $this->config['exceptions'] = $callback;
        return $this;
    }

    public function create(): self
    {
        return $this;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}

return Application::configure(dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware) {
        // Ilustrativo: o guard multi-tenant e a validação de token reais ficam em public/index.php
    })
    ->withExceptions(function ($exceptions) {
        // Clinical safety & Fail-Closed exception handlers
    })->create();
