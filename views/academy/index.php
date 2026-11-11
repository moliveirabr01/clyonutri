<?php
declare(strict_types=1);

/**
 * @var array{total: int, items: array<int, array<string, mixed>>} $results
 * @var array<string, mixed> $filters
 * @var array<int> $favoriteIds
 * @var array{total: int, items: array<int, array<string, mixed>>} $handouts  v54.23: lâminas para pacientes
 * @var array<int> $favoriteHandoutIds
 * @var array<string> $handoutTags
 * @var bool $handoutsOn
 * @var string $csrfToken
 * @var string $subTab
 * v54.24: classes do DS (.card, .form-control, .badge, .btn) + academy.css, sem estilo inline.
 */

use ClyoNutri\Application\View\Icon;

require __DIR__ . '/_header.php';
$tipo = (string)($filters['tipo'] ?? 'all');
$handouts = $handouts ?? ['total' => 0, 'items' => []];
$favoriteHandoutIds = $favoriteHandoutIds ?? [];
$handoutTags = $handoutTags ?? [];
$handoutsOn = !empty($handoutsOn);
$anyFilter = !empty($filters['q']) || $tipo !== 'all' || ($filters['study_design'] ?? 'all') !== 'all' || ($filters['condition_tag'] ?? 'all') !== 'all'
    || ($filters['access_type'] ?? 'all') !== 'all' || ($filters['tag'] ?? 'all') !== 'all';
?>

<div class="academy-content">
  <div class="card academy-filters">
    <form method="GET" action="/academy" role="search">
      <div class="academy-search">
        <div class="academy-search__field">
          <?= Icon::svg('search', 18) ?>
          <input
            type="search"
            name="q"
            class="form-control"
            value="<?= htmlspecialchars((string)($filters['q'] ?? '')) ?>"
            aria-label="Buscar na Clyo Academy"
            data-testid="academy-search"
            placeholder="<?= $handoutsOn ? 'Buscar artigos e lâminas por título, tema, autor, DOI ou PMID' : 'Buscar por título, autor, periódico, DOI, PMID ou tema' ?>"
          />
        </div>
        <button type="submit" class="btn btn-primary">Pesquisar</button>
      </div>

      <div class="academy-filter-row">
        <?php if ($handoutsOn): ?>
        <div>
          <label for="academy-tipo" class="form-label">Tipo</label>
          <select id="academy-tipo" name="tipo" class="form-control" data-autosubmit data-testid="academy-type-filter">
            <?php foreach (\ClyoNutri\Application\Controllers\AcademyController::TYPES as $tk => $tl): ?>
              <option value="<?= $tk ?>" <?= $tipo === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if ($tipo === 'laminas'): ?>
        <div>
          <label for="academy-tag" class="form-label">Tema</label>
          <select id="academy-tag" name="tag" class="form-control" data-autosubmit>
            <option value="all">Todos os temas</option>
            <?php foreach ($handoutTags as $tg): ?>
              <option value="<?= htmlspecialchars($tg) ?>" <?= ($filters['tag'] ?? '') === $tg ? 'selected' : '' ?>><?= htmlspecialchars(mb_strtoupper(mb_substr($tg, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($tg, 1, null, 'UTF-8')) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <div>
          <label for="academy-design" class="form-label">Desenho do estudo</label>
          <select id="academy-design" name="study_design" class="form-control" data-autosubmit>
            <option value="all" <?= ($filters['study_design'] ?? '') === 'all' ? 'selected' : '' ?>>Todos os desenhos</option>
            <option value="meta_analysis" <?= ($filters['study_design'] ?? '') === 'meta_analysis' ? 'selected' : '' ?>>Metanálises</option>
            <option value="systematic_review" <?= ($filters['study_design'] ?? '') === 'systematic_review' ? 'selected' : '' ?>>Revisões sistemáticas</option>
            <option value="rct" <?= ($filters['study_design'] ?? '') === 'rct' ? 'selected' : '' ?>>Ensaios clínicos (ECR)</option>
            <option value="clinical_guideline" <?= ($filters['study_design'] ?? '') === 'clinical_guideline' ? 'selected' : '' ?>>Diretrizes clínicas</option>
            <option value="cohort" <?= ($filters['study_design'] ?? '') === 'cohort' ? 'selected' : '' ?>>Estudos de coorte</option>
          </select>
        </div>

        <div>
          <label for="academy-condition" class="form-label">Condição ou protocolo</label>
          <select id="academy-condition" name="condition_tag" class="form-control" data-autosubmit>
            <option value="all" <?= ($filters['condition_tag'] ?? '') === 'all' ? 'selected' : '' ?>>Todas as condições</option>
            <option value="diabetes" <?= ($filters['condition_tag'] ?? '') === 'diabetes' ? 'selected' : '' ?>>Diabetes mellitus</option>
            <option value="hipertensao" <?= ($filters['condition_tag'] ?? '') === 'hipertensao' ? 'selected' : '' ?>>Hipertensão e saúde cardiovascular</option>
            <option value="celiaca" <?= ($filters['condition_tag'] ?? '') === 'celiaca' ? 'selected' : '' ?>>Doença celíaca e glúten</option>
            <option value="renal" <?= ($filters['condition_tag'] ?? '') === 'renal' ? 'selected' : '' ?>>Doença renal crônica (DRC)</option>
            <option value="obesidade" <?= ($filters['condition_tag'] ?? '') === 'obesidade' ? 'selected' : '' ?>>Obesidade e emagrecimento</option>
          </select>
        </div>

        <div>
          <label for="academy-access" class="form-label">Acesso ao texto</label>
          <select id="academy-access" name="access_type" class="form-control" data-autosubmit>
            <option value="all" <?= ($filters['access_type'] ?? '') === 'all' ? 'selected' : '' ?>>Todos</option>
            <option value="open_access" <?= ($filters['access_type'] ?? '') === 'open_access' ? 'selected' : '' ?>>Acesso aberto</option>
            <option value="publisher_link" <?= ($filters['access_type'] ?? '') === 'publisher_link' ? 'selected' : '' ?>>Link do periódico ou DOI</option>
          </select>
        </div>

        <?php endif; ?>
        <?php if ($anyFilter): ?>
          <a href="/academy" class="academy-link academy-filter-clear">Limpar filtros</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <?php if ($handoutsOn && ($tipo === 'laminas' || !empty($handouts['items']))): ?>
  <!-- v54.23: lâminas para pacientes -->
  <section class="academy-section" aria-labelledby="academy-handouts-title" data-testid="academy-handout-results">
    <div class="academy-section__head">
      <h2 id="academy-handouts-title">Lâminas para pacientes</h2>
      <span class="academy-count">
        <?= (int)$handouts['total'] === 1 ? '1 lâmina' : (int)$handouts['total'] . ' lâminas' ?>
        <?php if ($tipo === 'all' && (int)$handouts['total'] > count($handouts['items'])): ?>
          · <a href="/academy?tipo=laminas<?= !empty($filters['q']) ? '&amp;q=' . rawurlencode((string)$filters['q']) : '' ?>">ver todas</a>
        <?php endif; ?>
      </span>
    </div>
    <?php if (empty($handouts['items'])): ?>
      <div class="card academy-empty" data-testid="academy-handouts-empty">
        <?= Icon::svg('file-text', 40) ?>
        <h3><?= $anyFilter && (!empty($filters['q']) || ($filters['tag'] ?? 'all') !== 'all') ? 'Nenhuma lâmina encontrada' : 'Ainda não há lâminas publicadas' ?></h3>
        <p>As lâminas são preparadas e revisadas pela equipe Clyo Nutri e aparecem aqui assim que publicadas.</p>
      </div>
    <?php else: ?>
      <div class="academy-grid">
        <?php foreach ($handouts['items'] as $h): $hFav = in_array((int)$h['id'], $favoriteHandoutIds, true); $hFolder = null; ?>
          <?php require __DIR__ . '/_handout_card.php'; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($tipo === 'laminas' && (int)$handouts['total'] > 20): $pages = (int)ceil($handouts['total'] / 20); ?>
        <nav class="academy-pagination" aria-label="Páginas de lâminas">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="/academy?tipo=laminas&amp;page=<?= $i ?><?= !empty($filters['q']) ? '&amp;q=' . rawurlencode((string)$filters['q']) : '' ?>"<?= $i === (int)$page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($tipo !== 'laminas'): ?>
  <p class="academy-count">Encontrados <strong><?= (int)$results['total'] ?></strong> estudos científicos indexados</p>

  <?php if (empty($results['items'])): ?>
    <div class="card academy-empty">
      <?= Icon::svg('search', 40) ?>
      <?php $noFilter = empty($filters['q']) && ($filters['study_design'] ?? 'all') === 'all' && ($filters['condition_tag'] ?? 'all') === 'all' && ($filters['access_type'] ?? 'all') === 'all'; ?>
      <h3 data-testid="academy-empty"><?= $noFilter ? 'O acervo ainda está sendo montado' : 'Nenhum estudo encontrado' ?></h3>
      <p><?= $noFilter ? 'Cada estudo entra pela equipe editorial com DOI ou PMID, link oficial e data de conferência na fonte.' : 'Tente ajustar os termos de pesquisa ou remover os filtros selecionados.' ?></p>
    </div>
  <?php else: ?>
    <div class="academy-list">
      <?php foreach ($results['items'] as $art): ?>
        <?php
          $isFav = in_array((int)$art['id'], $favoriteIds, true);
          $favLabel = $isFav ? 'Remover da Minha coleção' : 'Salvar na Minha coleção';
          $designLabel = \ClyoNutri\Domain\Academy\AcademyArticleService::studyDesignLabel($art['study_design'] ?? '');
        ?>
        <article class="card academy-card academy-card--stack academy-article-card">
          <div class="academy-card__top">
            <div class="academy-badges">
              <span class="badge academy-badge--neutral"><?= htmlspecialchars($designLabel) ?></span>
              <?php if (($art['access_type'] ?? '') === 'open_access'): ?>
                <span class="badge badge-success">Acesso aberto</span>
              <?php endif; ?>
              <?php if (!empty($art['has_evidence_sheet'])): ?>
                <span class="badge badge-info">Ficha PICO disponível</span>
              <?php endif; ?>
              <?php if (!empty($art['retraction_status']) && $art['retraction_status'] !== 'none'): ?>
                <span class="badge badge-error">Atenção: <?= htmlspecialchars(\ClyoNutri\Domain\Academy\AcademyArticleService::RETRACTION[$art['retraction_status']] ?? (string)$art['retraction_status']) ?></span>
              <?php endif; ?>
            </div>

            <form method="POST" action="/academy/artigo/<?= (int)$art['id'] ?>/favoritar" class="academy-fav-form">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="back" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/academy'), ENT_QUOTES, 'UTF-8') ?>">
              <button type="submit" class="academy-fav" title="<?= $favLabel ?>" aria-label="<?= $favLabel ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>"><?= Icon::svg('bookmark', 18) ?></button>
            </form>
          </div>

          <h2><a href="/academy/artigo/<?= (int)$art['id'] ?>"><?= htmlspecialchars($art['title']) ?></a></h2>

          <p class="academy-meta">
            <span><strong><?= htmlspecialchars($art['authors']) ?></strong> — <em><?= htmlspecialchars($art['journal']) ?></em> (<?= (int)$art['publication_year'] ?>)</span>
          </p>

          <?php if (\ClyoNutri\Domain\Academy\AcademyArticleService::abstractVisible($art)): ?>
            <p class="academy-card__summary academy-card__summary--clamp"><?= htmlspecialchars($art['abstract']) ?></p>
          <?php endif; ?>

          <div class="academy-card__foot">
            <p class="academy-meta">
              <?php if (!empty($art['doi'])): ?>
                <span>DOI: <a href="https://doi.org/<?= htmlspecialchars($art['doi']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($art['doi']) ?></a></span>
              <?php endif; ?>
              <?php if (!empty($art['pmid'])): ?>
                <span>PMID: <strong><?= htmlspecialchars($art['pmid']) ?></strong></span>
              <?php endif; ?>
            </p>
            <a href="/academy/artigo/<?= (int)$art['id'] ?>" class="btn btn-secondary academy-btn--compact">Ver detalhes e citações</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
