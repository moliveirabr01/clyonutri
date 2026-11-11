<?php
declare(strict_types=1);

/** v54.24: classes do DS + academy.css, sem estilo inline. */
use ClyoNutri\Application\View\Icon;

require __DIR__ . '/_header.php';
?>

<div class="academy-content academy-state">
  <div class="card academy-empty" data-testid="academy-read-only">
    <?= Icon::svg('book-open', 40) ?>
    <h2>A Clyo Academy é só para leitura</h2>
    <p>Os artigos, as fichas e as publicações são escritos e revisados pela equipe da Clyo Nutri. Por aqui você pode pesquisar, ler e salvar na sua coleção o que quiser consultar depois.</p>
    <div class="academy-actions"><a href="/academy/publicacoes" class="btn btn-primary">Ver publicações</a></div>
  </div>
</div>
