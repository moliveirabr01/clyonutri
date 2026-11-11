<?php
/** v54.23 · cartão de lâmina para pacientes. Entradas: $h (linha de academy_handouts), $hFav (bool), $csrfToken; opcional $hFolder.
 *  v54.24: classes do DS (.card, .badge, .btn) + academy.css, sem estilo inline. */
use ClyoNutri\Application\View\Icon;

$hid = (int)$h['id'];
$hTags = \ClyoNutri\Domain\Academy\AcademyHandoutService::splitTags((string)($h['tags'] ?? ''));
$hFavLabel = $hFav ? 'Remover da Minha coleção' : 'Salvar na Minha coleção';
?>
<article class="card academy-card" data-testid="academy-handout-card" data-slug="<?= htmlspecialchars((string)$h['slug'], ENT_QUOTES, 'UTF-8') ?>">
  <a href="/academy/laminas/<?= $hid ?>" class="academy-preview" aria-hidden="true" tabindex="-1">
    <?php if (!empty($h['preview_storage_name'])): ?>
      <img src="/academy/laminas/<?= $hid ?>/previa" alt="" loading="lazy">
    <?php else: ?>
      <span class="academy-preview__placeholder"><?= Icon::svg('file-text', 24) ?>PDF</span>
    <?php endif; ?>
  </a>
  <div class="academy-card__body">
    <div class="academy-card__top">
      <div class="academy-badges">
        <span class="badge badge-info">Lâmina para paciente</span>
        <?php if (!empty($hFolder)): ?><span class="badge academy-badge--neutral">Pasta: <?= htmlspecialchars((string)$hFolder) ?></span><?php endif; ?>
      </div>
      <form method="POST" action="/academy/laminas/<?= $hid ?>/favoritar" class="academy-fav-form">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="back" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/academy'), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="academy-fav" data-testid="academy-handout-fav" title="<?= $hFavLabel ?>" aria-label="<?= $hFavLabel ?>" aria-pressed="<?= $hFav ? 'true' : 'false' ?>"><?= Icon::svg('bookmark', 18) ?></button>
      </form>
    </div>
    <h3><a href="/academy/laminas/<?= $hid ?>"><?= htmlspecialchars((string)$h['title']) ?></a></h3>
    <?php if ($hTags): ?>
      <ul class="academy-tags" aria-label="Temas">
        <?php foreach (array_slice($hTags, 0, 4) as $t): ?><li>#<?= htmlspecialchars($t) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($h['summary'])): ?>
      <p class="academy-card__summary"><?= htmlspecialchars((string)$h['summary']) ?></p>
    <?php endif; ?>
    <div class="academy-actions">
      <a href="/academy/laminas/<?= $hid ?>" class="btn btn-secondary academy-btn--compact">Ver lâmina</a>
      <a href="/academy/laminas/<?= $hid ?>/arquivo?baixar=1" class="btn btn-secondary academy-btn--compact"><?= Icon::svg('download', 16) ?>Baixar PDF</a>
    </div>
  </div>
</article>
