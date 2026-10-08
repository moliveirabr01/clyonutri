<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Api;

use InvalidArgumentException;
use RuntimeException;

class ApiContractValidator
{
    private array $spec;

    public function __construct(?string $specPath = null)
    {
        $path = $specPath ?? __DIR__ . '/../../../docs/api/openapi_v1.json';
        if (!file_exists($path)) {
            throw new RuntimeException("Arquivo de especificação OpenAPI não encontrado em: {$path}");
        }

        $content = file_get_contents($path);
        $this->spec = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function getSpec(): array
    {
        return $this->spec;
    }

    /**
     * Valida que uma rota e método HTTP estão documentados no OpenAPI.
     */
    public function assertEndpointExists(string $path, string $method = 'get'): array
    {
        $method = strtolower($method);
        if (!isset($this->spec['paths'][$path][$method])) {
            throw new InvalidArgumentException("Violação de Contrato OpenAPI: endpoint '{$method} {$path}' não está documentado na especificação.");
        }

        return $this->spec['paths'][$path][$method];
    }

    /**
     * Valida se um payload de resposta respeita o schema de componentes documentado.
     */
    public function validateSchema(string $schemaName, array $payload): bool
    {
        if (!isset($this->spec['components']['schemas'][$schemaName])) {
            throw new InvalidArgumentException("Schema '{$schemaName}' não encontrado no components/schemas do OpenAPI.");
        }

        $schema = $this->spec['components']['schemas'][$schemaName];
        $requiredProps = $schema['required'] ?? [];

        foreach ($requiredProps as $prop) {
            if (!array_key_exists($prop, $payload)) {
                throw new InvalidArgumentException("Violação de Contrato OpenAPI: campo obrigatório '{$prop}' ausente no schema '{$schemaName}'.");
            }
        }

        return true;
    }
}
