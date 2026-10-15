<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Database;

use ClyoNutri\Domain\Food\FoodCatalogService;
use ClyoNutri\Domain\Food\TacoCatalogImporter;
use PDO;

class CatalogSeeder
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Realiza o seed de catálogo de referência nutricional (TACO).
     * Seguro para execução em qualquer ambiente, incluindo produção.
     */
    public function run(): int
    {
        $catalog = new FoodCatalogService($this->db);
        $count = $catalog->seedTacoReference();
        // TACO completa (597 alimentos, 4ª ed.): vincula os de referência e insere os demais; idempotente.
        $taco = (new TacoCatalogImporter($this->db))->run();
        return $count + $taco['inserted'];
    }
}
