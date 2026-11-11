<?php

declare(strict_types=1);

/**
 * NI2-520 (v43): importa o USDA FoodData Central (Foundation Foods, CSV) como base externa, sem sobrescrever nada.
 *   php bin/importar_usda_fdc.php --dir=/caminho/FoodData_Central_foundation_food_csv_AAAA-MM-DD --dry-run
 *   php bin/importar_usda_fdc.php --dir=/caminho/FoodData_Central_foundation_food_csv_AAAA-MM-DD --apply
 * Sem --apply só mostra as contagens. Precisa da migração 63. Os nomes ficam em inglês, como no original.
 */
require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    \ClyoNutri\Core\Security\Env::load(__DIR__ . '/../.env');
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

$opts = getopt('', ['dir:', 'dry-run', 'apply']);
$dir = is_string($opts['dir'] ?? null) ? $opts['dir'] : '';
$apply = array_key_exists('apply', $opts) && !array_key_exists('dry-run', $opts);
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Informe --dir com a pasta do CSV do Foundation Foods.\n");
    exit(2);
}
try {
    $version = \ClyoNutri\Domain\Food\UsdaFdcImporter::versionFromDir(basename(rtrim($dir, '/')));
    $read = \ClyoNutri\Domain\Food\UsdaFdcImporter::read($dir);
    $report = (new \ClyoNutri\Domain\Food\FoodSourceImporter(\ClyoNutri\Core\Database\Connection::getInstance()))
        ->run(\ClyoNutri\Domain\Food\UsdaFdcImporter::SOURCE, $version, $read['rows'], $apply);
    // Fora da importação: alimentos sem nenhum valor de energia no arquivo (não calculamos o que a fonte não publicou).
    $report['sem_energia_fora'] = $read['sem_energia'];
    $report['sem_energia_exemplos'] = $read['sem_energia_exemplos'];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Falhou: ' . $e->getMessage() . "\n");
    exit(1);
}
