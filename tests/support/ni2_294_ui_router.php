<?php
// Local verification host: exported Expo assets and the actual PHP front controller
// share an origin. No replacement API routes or simulated responses.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($uri, '/api/') || $uri === '/up') {
    require __DIR__ . '/../../public/index.php';
    return true;
}
if ($uri !== '/' && is_file(__DIR__ . '/../../mobile/dist' . $uri)) return false;
readfile(__DIR__ . '/../../mobile/dist/index.html');
