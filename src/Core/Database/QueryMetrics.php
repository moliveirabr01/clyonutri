<?php

declare(strict_types=1);
namespace ClyoNutri\Core\Database;

/** Request-local development metrics: never records SQL, values, bindings or patient identifiers. */
final class QueryMetrics
{
    private int $count = 0;
    private float $milliseconds = 0;
    private array $fingerprints = [];
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = getenv('DB_QUERY_PROFILE') === '1'
            && in_array(strtolower((string)getenv('APP_ENV')), ['local', 'dev', 'development', 'testing'], true);
    }

    public function record(string $sql, float $started): void
    {
        if (!$this->enabled) return;
        ++$this->count;
        $this->milliseconds += (hrtime(true) / 1e6) - $started;
        // Only a digest is retained, including when callers interpolate literals into SQL.
        $key = hash('sha256', (string)preg_replace('/\s+/', ' ', trim($sql)));
        $this->fingerprints[$key] = ($this->fingerprints[$key] ?? 0) + 1;
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->milliseconds = 0;
        $this->fingerprints = [];
    }

    public function snapshot(): array
    {
        return ['enabled' => $this->enabled, 'queries' => $this->count,
            'database_ms' => round($this->milliseconds, 3), 'fingerprints' => $this->fingerprints];
    }
}
