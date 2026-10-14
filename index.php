<?php
// Reserva: se a raiz do domínio for a pasta do projeto e o mod_rewrite não agir,
// o Apache abre este arquivo e a aplicação de public/ é carregada mesmo assim.
require __DIR__ . '/public/index.php';
