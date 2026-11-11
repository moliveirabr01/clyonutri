<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $published
 * @var string $csrfToken
 * @var string $subTab
 * v54.24: classes do DS + academy.css, sem estilo inline.
 */

use ClyoNutri\Application\View\Icon;

require __DIR__ . '/_header.php';
?>

<div class="academy-content">
  <div class="academy-page-head">
    <div>
      <h2>Publicações e sínteses clínicas</h2>
      <p>Textos escritos e revisados pela equipe da Clyo Nutri, sempre com as referências citadas.</p>
    </div>
  </div>

  <?php if (empty($published)): ?>
    <div class="card academy-empty">
      <?= Icon::svg('pen-line', 40) ?>
      <p data-testid="publications-empty">Ainda não há publicações. Quando a equipe da Clyo Nutri publicar um texto, ele aparece aqui.</p>
    </div>
  <?php else: ?>
    <div class="academy-list">
      <?php foreach ($published as $pub): ?>
        <?php $catLabel = \ClyoNutri\Domain\Academy\AcademyPublishingService::categoryLabel($pub['category'] ?? ''); ?>
        <article class="card academy-card academy-card--stack">
          <div class="academy-card__top">
            <div class="academy-badges">
              <span class="badge badge-info"><?= htmlspecialchars($catLabel) ?></span>
              <span class="academy-meta">Publicado em <?= htmlspecialchars(date('d/m/Y', strtotime($pub['published_at'] ?? $pub['created_at']))) ?></span>
            </div>
          </div>

          <h3><a href="/academy/publicacoes/<?= (int)$pub['id'] ?>"><?= htmlspecialchars($pub['title']) ?></a></h3>

          <p class="academy-meta"><span>Por <strong><?= htmlspecialchars($pub['author_name']) ?></strong> (<?= htmlspecialchars($pub['author_crn'] ?? 'CRN verificado') ?>)</span></p>

          <?php if (!empty($pub['summary'])): ?>
            <p class="academy-card__summary"><?= htmlspecialchars($pub['summary']) ?></p>
          <?php endif; ?>

          <div class="academy-card__foot">
            <span class="academy-meta">Revisado pela equipe Clyo Nutri · com referências</span>
            <a href="/academy/publicacoes/<?= (int)$pub['id'] ?>" class="btn btn-secondary academy-btn--compact">Ler o texto completo</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
