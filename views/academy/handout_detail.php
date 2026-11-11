<?php
declare(strict_types=1);

/**
 * v54.23 · Página da lâmina para pacientes (só publicada chega aqui).
 * v54.24: classes do DS + academy.css, sem estilo inline.
 * @var array<string, mixed> $handout
 * @var bool $isFavorite
 * @var string $csrfToken
 * @var string $subTab
 */

use ClyoNutri\Application\View\Icon;

require __DIR__ . '/_header.php';
$hid = (int)$handout['id'];
$tags = \ClyoNutri\Domain\Academy\AcademyHandoutService::splitTags((string)($handout['tags'] ?? ''));
?>

<div class="academy-content">
  <p class="academy-back"><a href="/academy/laminas">← Voltar para as lâminas</a></p>

  <div class="card academy-detail" data-testid="academy-handout-detail" data-slug="<?= htmlspecialchars((string)$handout['slug'], ENT_QUOTES, 'UTF-8') ?>">
    <div class="academy-detail__layout">
      <?php if (!empty($handout['preview_storage_name'])): ?>
        <a href="/academy/laminas/<?= $hid ?>/arquivo" target="_blank" rel="noopener" class="academy-preview academy-preview--large">
          <img src="/academy/laminas/<?= $hid ?>/previa" alt="Prévia da lâmina: <?= htmlspecialchars((string)$handout['title'], ENT_QUOTES, 'UTF-8') ?>" data-testid="academy-handout-preview">
        </a>
      <?php else: ?>
        <div class="academy-preview academy-preview--large"><span class="academy-preview__placeholder"><?= Icon::svg('file-text', 32) ?>Prévia indisponível. Abra o PDF para ver a lâmina.</span></div>
      <?php endif; ?>

      <div class="academy-detail__main">
        <span class="badge badge-info">Lâmina para paciente · 1 página A4</span>
        <h1><?= htmlspecialchars((string)$handout['title']) ?></h1>
        <?php if (!empty($handout['summary'])): ?>
          <p class="academy-detail__summary"><?= nl2br(htmlspecialchars((string)$handout['summary'])) ?></p>
        <?php endif; ?>
        <?php if ($tags): ?>
          <p class="academy-badges">
            <?php foreach ($tags as $t): ?>
              <a href="/academy?tipo=laminas&amp;tag=<?= rawurlencode($t) ?>" class="badge academy-badge--neutral">#<?= htmlspecialchars($t) ?></a>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>

        <div class="academy-actions">
          <a href="/academy/laminas/<?= $hid ?>/arquivo" target="_blank" rel="noopener" class="btn btn-primary" data-testid="academy-handout-open"><?= Icon::svg('external-link', 18) ?>Abrir PDF</a>
          <a href="/academy/laminas/<?= $hid ?>/arquivo?baixar=1" class="btn btn-secondary" data-testid="academy-handout-download"><?= Icon::svg('download', 18) ?>Baixar PDF (<?= htmlspecialchars(\ClyoNutri\Domain\Academy\AcademyHandoutService::humanSize((int)$handout['size_bytes'])) ?>)</a>
          <form method="POST" action="/academy/laminas/<?= $hid ?>/favoritar" class="academy-fav-form">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="back" value="/academy/laminas/<?= $hid ?>">
            <button type="submit" class="btn btn-secondary" data-testid="academy-handout-fav" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"><?= Icon::svg('bookmark', 18) ?><span><?= $isFavorite ? 'Salva na Minha coleção' : 'Salvar na Minha coleção' ?></span></button>
          </form>
        </div>

        <div class="academy-detail__foot">
          <p><strong>Fonte:</strong> <?= nl2br(htmlspecialchars((string)$handout['source_citation'])) ?></p>
          <?php if (!empty($handout['published_at'])): ?><p>Publicada pela equipe Clyo Nutri em <?= htmlspecialchars(date('d/m/Y', strtotime((string)$handout['published_at']))) ?>.</p><?php endif; ?>
          <p>Material de orientação geral. Antes de entregar ao paciente, confira se o conteúdo combina com a conduta que você definiu para ele.</p>
        </div>
      </div>
    </div>
  </div>
</div>
