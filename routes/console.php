<?php

declare(strict_types=1);

/**
 * ClyoNutri II — Comandos de console (mapa declarativo, estilo Laravel).
 * AVISO (v54.46 / CR-NI2-007): o ClyoNutri NÃO roda Laravel nem Sanctum. O roteador real de produção é
 * public/index.php (roteador PHP próprio). Este arquivo é um shim declarativo carregado só pelo `artisan`
 * local; não aplica autenticação, CSRF nem tenant em requisições HTTP.
 */

// Comandos programados para execução CLI / Cron
// php artisan inspire
// php artisan migrate
// php artisan clyonutri:audit-chain
