<?php

declare(strict_types=1);
namespace ClyoNutri\Core\Database;

final class ProfiledStatement extends \PDOStatement
{
    protected function __construct(private QueryMetrics $metrics) {}

    public function execute(?array $params = null): bool
    {
        $started = hrtime(true) / 1e6;
        try {
            return parent::execute($params);
        } finally {
            $this->metrics->record($this->queryString, $started);
        }
    }
}
