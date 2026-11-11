<?php
declare(strict_types=1);

/** v54.24: classes do DS + academy.css, sem estilo inline. */
use ClyoNutri\Application\View\Icon;

require __DIR__ . '/_header.php';
?>

<div class="academy-content academy-state">
  <div class="card academy-empty">
    <?= Icon::svg('search', 40) ?>
    <h2>Não encontramos este item</h2>
    <p>O artigo, a lâmina ou a publicação que você procurou não existe ou saiu do ar.</p>
    <div class="academy-actions"><a href="/academy" class="btn btn-primary">Voltar para a Biblioteca</a></div>
  </div>
</div>
