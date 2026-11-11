<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $favorites
 * @var array<int, array<string, mixed>> $handoutFavorites  v54.23
 * @var array<string> $collections
 * @var string|null $selectedCollection
 * @var string $csrfToken
 * @var string $subTab
 * v54.24: classes do DS + academy.css, sem estilo inline.
 */

use ClyoNutri\Application\View\Icon;

require __DIR__ . '/_header.php';
$handoutFavorites = $handoutFavorites ?? [];
?>

<div class="academy-content">
  <div class="academy-page-head">
    <div>
      <h2>Minha coleção</h2>
      <p>Estudos, diretrizes e lâminas para pacientes que você salvou para consultar depois.</p>
    </div>

    <?php if (count($collections) > 1): ?>
      <div class="academy-folder">
        <label for="academy-folder" class="form-label">Pasta</label>
        <select id="academy-folder" class="form-control" data-collection-nav>
          <option value="" <?= empty($selectedCollection) ? 'selected' : '' ?>>Todas as pastas</option>
          <?php foreach ($collections as $col): ?>
            <option value="<?= htmlspecialchars($col) ?>" <?= ($selectedCollection ?? '') === $col ? 'selected' : '' ?>><?= htmlspecialchars($col) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($handoutFavorites)): ?>
    <section class="academy-section" aria-labelledby="col-handouts" data-testid="academy-collection-handouts">
      <h3 id="col-handouts" class="academy-subhead">Lâminas para pacientes</h3>
      <div class="academy-grid">
        <?php foreach ($handoutFavorites as $h): $hFav = true; $hFolder = (string)$h['collection_name']; ?>
          <?php require __DIR__ . '/_handout_card.php'; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (empty($favorites) && empty($handoutFavorites)): ?>
    <div class="card academy-empty">
      <?= Icon::svg('bookmark', 40) ?>
      <h3>Sua coleção está vazia</h3>
      <p>Na Biblioteca científica ou no Evidence Desk, toque no marcador de um artigo ou lâmina para guardar aqui.</p>
      <div class="academy-actions"><a href="/academy" class="btn btn-primary">Explorar a Biblioteca científica</a></div>
    </div>
  <?php elseif (!empty($favorites)): ?>
    <?php if (!empty($handoutFavorites)): ?><h3 class="academy-subhead">Artigos</h3><?php endif; ?>
    <div class="academy-list">
      <?php foreach ($favorites as $fav): ?>
        <?php $designLabel = \ClyoNutri\Domain\Academy\AcademyArticleService::studyDesignLabel($fav['study_design'] ?? ''); ?>
        <article class="card academy-card academy-card--stack">
          <div class="academy-card__top">
            <div class="academy-badges">
              <span class="badge academy-badge--neutral">Pasta: <?= htmlspecialchars($fav['collection_name']) ?></span>
              <span class="badge badge-info"><?= htmlspecialchars($designLabel) ?></span>
              <span class="academy-meta">Salvo em <?= htmlspecialchars(date('d/m/Y', strtotime($fav['saved_at']))) ?></span>
            </div>

            <form method="POST" action="/academy/artigo/<?= (int)$fav['id'] ?>/favoritar" class="academy-fav-form">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="back" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/academy'), ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" class="academy-fav" title="Remover da Minha coleção" aria-label="Remover da Minha coleção" aria-pressed="true"><?= Icon::svg('bookmark', 18) ?></button>
            </form>
          </div>

          <h3><a href="/academy/artigo/<?= (int)$fav['id'] ?>"><?= htmlspecialchars($fav['title']) ?></a></h3>

          <p class="academy-meta"><span><?= htmlspecialchars($fav['authors']) ?> — <em><?= htmlspecialchars($fav['journal']) ?></em> (<?= (int)$fav['publication_year'] ?>)</span></p>

          <div class="academy-actions"><a href="/academy/artigo/<?= (int)$fav['id'] ?>" class="btn btn-secondary academy-btn--compact">Ver estudo e citações</a></div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
