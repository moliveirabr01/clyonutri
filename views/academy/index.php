<?php
declare(strict_types=1);

/**
 * @var array{total: int, items: array<int, array<string, mixed>>} $results
 * @var array<string, mixed> $filters
 * @var array<int> $favoriteIds
 * @var string $csrfToken
 * @var string $subTab
 */

require __DIR__ . '/_header.php';
?>

<div class="academy-content">
  <!-- Barra de Filtros e Busca -->
  <div class="card" style="margin-bottom: 24px; padding: 20px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
    <form method="GET" action="/academy" style="margin: 0;">
      <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 280px; position: relative;">
          <input
            type="search"
            name="q"
            value="<?= htmlspecialchars((string)($filters['q'] ?? '')) ?>"
            placeholder="Buscar por título, autor, periódico, DOI, PMID ou tema..."
            style="width: 100%; height: 44px; padding: 0 14px 0 40px; border-radius: 8px; border: 1px solid var(--neutral-gray-300, #D0D5DD); font-size: 0.95rem;"
          />
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 14px; top: 13px; color: var(--neutral-gray-400, #98A2B3);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
        <button type="submit" class="btn btn-primary" style="height: 44px; padding: 0 24px; font-weight: 600; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px;">
          <span>Pesquisar</span>
        </button>
      </div>

      <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; font-size: 0.88rem;">
        <div>
          <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--neutral-gray-600, #475467); margin-bottom: 4px;">Desenho do Estudo</label>
          <select name="study_design" onchange="this.form.submit()" style="height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid var(--neutral-gray-300, #D0D5DD); background: #fff;">
            <option value="all" <?= ($filters['study_design'] ?? '') === 'all' ? 'selected' : '' ?>>Todos os desenhos</option>
            <option value="meta_analysis" <?= ($filters['study_design'] ?? '') === 'meta_analysis' ? 'selected' : '' ?>>Metanálises</option>
            <option value="systematic_review" <?= ($filters['study_design'] ?? '') === 'systematic_review' ? 'selected' : '' ?>>Revisões Sistemáticas</option>
            <option value="rct" <?= ($filters['study_design'] ?? '') === 'rct' ? 'selected' : '' ?>>Ensaios Clínicos (ECR)</option>
            <option value="clinical_guideline" <?= ($filters['study_design'] ?? '') === 'clinical_guideline' ? 'selected' : '' ?>>Diretrizes Clínicas</option>
            <option value="cohort" <?= ($filters['study_design'] ?? '') === 'cohort' ? 'selected' : '' ?>>Estudos de Coorte</option>
          </select>
        </div>

        <div>
          <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--neutral-gray-600, #475467); margin-bottom: 4px;">Condição / Protocolo</label>
          <select name="condition_tag" onchange="this.form.submit()" style="height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid var(--neutral-gray-300, #D0D5DD); background: #fff;">
            <option value="all" <?= ($filters['condition_tag'] ?? '') === 'all' ? 'selected' : '' ?>>Todas as condições</option>
            <option value="diabetes" <?= ($filters['condition_tag'] ?? '') === 'diabetes' ? 'selected' : '' ?>>Diabetes Mellitus</option>
            <option value="hipertensao" <?= ($filters['condition_tag'] ?? '') === 'hipertensao' ? 'selected' : '' ?>>Hipertensão / Cardiovascular</option>
            <option value="celiaca" <?= ($filters['condition_tag'] ?? '') === 'celiaca' ? 'selected' : '' ?>>Doença Celíaca / Glúten</option>
            <option value="renal" <?= ($filters['condition_tag'] ?? '') === 'renal' ? 'selected' : '' ?>>Doença Renal Crônica (DRC)</option>
            <option value="obesidade" <?= ($filters['condition_tag'] ?? '') === 'obesidade' ? 'selected' : '' ?>>Obesidade & Emagrecimento</option>
          </select>
        </div>

        <div>
          <label style="display: block; font-size: 0.75rem; font-weight: 600; color: var(--neutral-gray-600, #475467); margin-bottom: 4px;">Acesso ao Texto</label>
          <select name="access_type" onchange="this.form.submit()" style="height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid var(--neutral-gray-300, #D0D5DD); background: #fff;">
            <option value="all" <?= ($filters['access_type'] ?? '') === 'all' ? 'selected' : '' ?>>Todos</option>
            <option value="open_access" <?= ($filters['access_type'] ?? '') === 'open_access' ? 'selected' : '' ?>>Open Access (Acesso Aberto)</option>
            <option value="publisher_link" <?= ($filters['access_type'] ?? '') === 'publisher_link' ? 'selected' : '' ?>>Link do Periódico / DOI</option>
          </select>
        </div>

        <?php if (!empty($filters['q']) || ($filters['study_design'] ?? 'all') !== 'all' || ($filters['condition_tag'] ?? 'all') !== 'all' || ($filters['access_type'] ?? 'all') !== 'all'): ?>
          <div style="margin-top: 20px;">
            <a href="/academy" style="color: var(--neutral-gray-500, #667085); text-decoration: underline;">Limpar filtros</a>
          </div>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Contagem de Resultados -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <div style="font-size: 0.92rem; color: var(--neutral-gray-600, #475467);">
      Encontrados <strong><?= (int)$results['total'] ?></strong> estudos científicos indexados
    </div>
  </div>

  <!-- Lista de Artigos -->
  <?php if (empty($results['items'])): ?>
    <div class="card" style="padding: 48px 24px; text-align: center; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0);">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 12px; color: var(--neutral-gray-400, #98A2B3);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <h3 style="margin: 0 0 6px 0; font-size: 1.1rem; color: var(--neutral-gray-900, #101828);">Nenhum estudo encontrado</h3>
      <p style="margin: 0; color: var(--neutral-gray-500, #667085); font-size: 0.9rem;">Tente ajustar os termos de pesquisa ou remover os filtros selecionados.</p>
    </div>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 16px;">
      <?php foreach ($results['items'] as $art): ?>
        <?php
          $isFav = in_array((int)$art['id'], $favoriteIds, true);
          $designLabel = \ClyoNutri\Domain\Academy\AcademyArticleService::studyDesignLabel($art['study_design'] ?? '');
        ?>
        <article class="card academy-article-card" style="padding: 20px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); transition: border-color 0.15s, box-shadow 0.15s; position: relative;">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 8px;">
            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
              <span style="display: inline-block; padding: 3px 8px; background: #F4EBFF; color: #6941C6; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                <?= htmlspecialchars($designLabel) ?>
              </span>

              <?php if (($art['access_type'] ?? '') === 'open_access'): ?>
                <span style="display: inline-block; padding: 3px 8px; background: #ECFDF3; color: #027A48; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                  Open Access
                </span>
              <?php endif; ?>

              <?php if (!empty($art['has_evidence_sheet'])): ?>
                <span style="display: inline-block; padding: 3px 8px; background: #FEF0C7; color: #B54708; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                  Ficha PICO Disponível
                </span>
              <?php endif; ?>

              <?php if (!empty($art['retraction_status']) && $art['retraction_status'] !== 'none'): ?>
                <span style="display: inline-block; padding: 3px 8px; background: #FEF3F2; color: #B42318; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">
                  Aviso: <?= htmlspecialchars(strtoupper($art['retraction_status'])) ?>
                </span>
              <?php endif; ?>
            </div>

            <!-- Botão de Favoritar -->
            <form method="POST" action="/academy/artigo/<?= (int)$art['id'] ?>/favoritar" style="margin: 0;">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <button
                type="submit"
                class="btn-icon"
                title="<?= $isFav ? 'Remover da Minha Coleção' : 'Salvar na Minha Coleção' ?>"
                style="background: transparent; border: 1px solid var(--neutral-gray-200, #EAECF0); border-radius: 6px; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: <?= $isFav ? '#E04F16' : 'var(--neutral-gray-400, #98A2B3)' ?>;"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="<?= $isFav ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
              </button>
            </form>
          </div>

          <h2 style="margin: 0 0 8px 0; font-size: 1.15rem; font-weight: 600; line-height: 1.4;">
            <a href="/academy/artigo/<?= (int)$art['id'] ?>" style="color: var(--neutral-gray-900, #101828); text-decoration: none;">
              <?= htmlspecialchars($art['title']) ?>
            </a>
          </h2>

          <div style="font-size: 0.85rem; color: var(--neutral-gray-600, #475467); margin-bottom: 12px; line-height: 1.4;">
            <strong><?= htmlspecialchars($art['authors']) ?></strong> —
            <em><?= htmlspecialchars($art['journal']) ?></em> (<?= (int)$art['publication_year'] ?>)
          </div>

          <?php if (!empty($art['abstract'])): ?>
            <p style="margin: 0 0 14px 0; font-size: 0.9rem; color: var(--neutral-gray-700, #344054); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
              <?= htmlspecialchars($art['abstract']) ?>
            </p>
          <?php endif; ?>

          <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; padding-top: 12px; border-top: 1px solid var(--neutral-gray-100, #F2F4F7); font-size: 0.82rem;">
            <div style="display: flex; gap: 12px; align-items: center; color: var(--neutral-gray-500, #667085);">
              <?php if (!empty($art['doi'])): ?>
                <span>DOI: <a href="https://doi.org/<?= htmlspecialchars($art['doi']) ?>" target="_blank" rel="noopener noreferrer" style="color: var(--brand-primary, #1B4D3E); text-decoration: underline;"><?= htmlspecialchars($art['doi']) ?></a></span>
              <?php endif; ?>
              <?php if (!empty($art['pmid'])): ?>
                <span>PMID: <strong><?= htmlspecialchars($art['pmid']) ?></strong></span>
              <?php endif; ?>
            </div>

            <div style="display: flex; gap: 8px;">
              <a href="/academy/artigo/<?= (int)$art['id'] ?>" class="btn btn-secondary btn-sm" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none;">
                Ver Detalhes & Citações →
              </a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
