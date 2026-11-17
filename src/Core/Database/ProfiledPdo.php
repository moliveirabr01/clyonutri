<?php

declare(strict_types=1);
namespace ClyoNutri\Core\Database;

/** Counts execution, not prepare(); PDO::query() executes without calling execute() in userland. */
class ProfiledPdo extends \PDO
{
    private QueryMetrics $metrics;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->metrics = new QueryMetrics();
        if ($this->metrics->snapshot()['enabled']) {
            $this->setAttribute(self::ATTR_STATEMENT_CLASS, [ProfiledStatement::class, [$this->metrics]]);
        }
    }

    public function metrics(): QueryMetrics { return $this->metrics; }

    public function exec(string $statement): int|false
    {
        $started = hrtime(true) / 1e6;
        try { return parent::exec($statement); }
        finally { $this->metrics->record($statement, $started); }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $started = hrtime(true) / 1e6;
        try {
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally { $this->metrics->record($query, $started); }
    }
}
