<?php
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'teste-plano-0123456789abcdef', 'APP_TIMEZONE' => 'America/Sao_Paulo',
          'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/work/storage/plano_impressao.sqlite'] as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
}
