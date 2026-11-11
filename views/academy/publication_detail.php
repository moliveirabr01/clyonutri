<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $pub
 * @var string $subTab
 * v54.24: classes do DS + academy.css, sem estilo inline.
 */

require __DIR__ . '/_header.php';
$catLabel = \ClyoNutri\Domain\Academy\AcademyPublishingService::categoryLabel($pub['category'] ?? '');
?>

<div class="academy-content academy-narrow">
  <p class="academy-back"><a href="/academy/publicacoes">← Voltar para as publicações</a></p>

  <article class="card academy-article">
    <span class="badge badge-info"><?= htmlspecialchars($catLabel) ?></span>

    <h1><?= htmlspecialchars($pub['title']) ?></h1>

    <p class="academy-article__byline">
      Por <strong><?= htmlspecialchars($pub['author_name']) ?></strong>
      <?= !empty($pub['author_crn']) ? '(' . htmlspecialchars($pub['author_crn']) . ')' : '' ?> ·
      publicado em <?= htmlspecialchars(date('d/m/Y', strtotime($pub['published_at'] ?? $pub['created_at']))) ?>
    </p>

    <?php if (!empty($pub['summary'])): ?>
      <div class="academy-callout">
        <strong class="academy-callout__title">Resumo</strong>
        <p><?= htmlspecialchars($pub['summary']) ?></p>
      </div>
    <?php endif; ?>

    <div class="academy-article__body"><?= htmlspecialchars($pub['body_markdown']) ?></div>

    <?php if (!empty($pub['references_text'])): ?>
      <section class="academy-article__refs" aria-labelledby="pub-refs">
        <h2 id="pub-refs">Referências citadas</h2>
        <div><?= htmlspecialchars($pub['references_text']) ?></div>
      </section>
    <?php endif; ?>

    <?php if (!empty($pub['conflict_of_interest_disclosure'])): ?>
      <p class="academy-note"><strong>Conflito de interesses:</strong> <?= htmlspecialchars($pub['conflict_of_interest_disclosure']) ?></p>
    <?php endif; ?>

    <p class="academy-note academy-note--muted"><strong>Sobre este texto:</strong> escrito por nutricionista com CRN e revisado pela equipe da Clyo Nutri, para atualização profissional. Não é revisão por pares acadêmica, não tem DOI nem indexação e não substitui o julgamento clínico de cada caso.</p>
  </article>
</div>
