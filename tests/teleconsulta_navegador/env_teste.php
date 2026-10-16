<?php
// Prepend dos testes de navegador: variáveis de teste antes do .env (Env::load não sobrescreve).
foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'teste-navegador-0123456789abcdef0123456789',
    'APP_URL' => 'http://localhost',
    'APP_TIMEZONE' => 'America/Sao_Paulo',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => '/work/storage/teleconsulta_navegador.sqlite',
    'TELECONSULT_PROVIDER' => 'webrtc_p2p',
    'TELECONSULT_STUN_URLS' => 'none', // ambiente de teste sem rede externa: só candidatos locais
] as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
}
