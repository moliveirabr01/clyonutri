<?php

declare(strict_types=1);

/**
 * Roteador do servidor embutido do PHP para o gate de homologação (NI2-409).
 * Arquivos estáticos reais de public/ são servidos direto; todo o resto passa
 * pelo entrypoint real da aplicação (public/index.php) — sem atalhos nem mocks.
 */

$public = realpath(__DIR__ . '/../../../public');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($public . $path);

if ($path !== '/' && $file !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file)
    && !str_ends_with($file, '.php') && basename($file)[0] !== '.') {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public . '/index.php';
require $public . '/index.php';
