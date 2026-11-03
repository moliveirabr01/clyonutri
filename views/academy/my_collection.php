<?php
declare(strict_types=1);

/**
 * @var array<int, array<string, mixed>> $favorites
 * @var array<string> $collections
 * @var string|null $selectedCollection
 * @var string $csrfToken
 * @var string $subTab
 */

require __DIR__ . '/_header.php';
?>

<div class="academy-content">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
      <h2 style="margin: 0; font-size: 1.35rem; font-weight: 700; color: var(--neutral-gray-900, #101828);">
        Minha Coleção Pessoal
      </h2>
      <p style="margin: 4px 0 0 0; font-size: 0.9rem; color: var(--neutral-gray-600, #475467);">
        Estudos, evidências e diretrizes científicas salvos para consulta clínica e embasamento de condutas.
      </p>
    </div>

    <!-- Filtro por Coleção / Pasta -->
    <?php if (count($collections) > 1): ?>
      <div style="display: flex; gap: 8px; align-items: center;">
        <span style="font-size: 0.85rem; color: #475467;">Pasta:</span>
        <select onchange="window.location.href='/academy/colecao?collection=' + encodeURIComponent(this.value);" style="padding: 6px 12px; border-radius: 6px; border: 1px solid #D0D5DD; background: #fff; font-size: 0.88rem;">
          <option value="" <?= empty($selectedCollection) ? 'selected' : '' ?>>Todas as pastas</option>
          <?php foreach ($collections as $col): ?>
            <option value="<?= htmlspecialchars($col) ?>" <?= ($selectedCollection ?? '') === $col ? 'selected' : '' ?>>
              <?= htmlspecialchars($col) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
  </div>

  <?php if (empty($favorites)): ?>
    <div class="card" style="padding: 48px 24px; text-align: center; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0);">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin: 0 auto 12px; color: var(--neutral-gray-400, #98A2B3);"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
      <h3 style="margin: 0 0 6px 0; font-size: 1.1rem; color: var(--neutral-gray-900, #101828);">Sua coleção está vazia</h3>
      <p style="margin: 0 0 16px 0; color: var(--neutral-gray-500, #667085); font-size: 0.9rem;">
        Ao explorar a Biblioteca Científica ou o Evidence Desk, clique no ícone de marcador para salvar artigos na sua coleção.
      </p>
      <a href="/academy" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; padding: 8px 16px; font-weight: 600;">
        Explorar a Biblioteca Científica →
      </a>
    </div>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 16px;">
      <?php foreach ($favorites as $fav): ?>
        <?php $designLabel = \ClyoNutri\Domain\Academy\AcademyArticleService::studyDesignLabel($fav['study_design'] ?? ''); ?>
        <article class="card" style="padding: 20px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 8px;">
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
              <span style="display: inline-block; padding: 2px 8px; background: #F2F4F7; color: #344054; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                Pasta: <?= htmlspecialchars($fav['collection_name']) ?>
              </span>
              <span style="display: inline-block; padding: 2px 8px; background: #F4EBFF; color: #6941C6; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                <?= htmlspecialchars($designLabel) ?>
              </span>
              <span style="font-size: 0.78rem; color: #98A2B3;">
                Salvo em <?= htmlspecialchars(date('d/m/Y', strtotime($fav['saved_at']))) ?>
              </span>
            </div>

            <form method="POST" action="/academy/artigo/<?= (int)$fav['id'] ?>/favoritar" style="margin: 0;">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <button
                type="submit"
                class="btn-icon"
                title="Remover da Minha Coleção"
                style="background: transparent; border: 1px solid #EAECF0; border-radius: 6px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: #E04F16;"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
              </button>
            </form>
          </div>

          <h3 style="margin: 0 0 6px 0; font-size: 1.1rem; font-weight: 600;">
            <a href="/academy/artigo/<?= (int)$fav['id'] ?>" style="color: var(--neutral-gray-900, #101828); text-decoration: none;">
              <?= htmlspecialchars($fav['title']) ?>
            </a>
          </h3>

          <div style="font-size: 0.85rem; color: #475467; margin-bottom: 12px;">
            <?= htmlspecialchars($fav['authors']) ?> — <em><?= htmlspecialchars($fav['journal']) ?></em> (<?= (int)$fav['publication_year'] ?>)
          </div>

          <?php if (!empty($fav['personal_notes'])): ?>
            <div style="padding: 10px 14px; background: #FFFAEB; border-radius: 6px; font-size: 0.85rem; color: #B54708; margin-bottom: 12px; border-left: 3px solid #F79009;">
              <strong>Minhas Anotações Clínicas:</strong> <?= htmlspecialchars($fav['personal_notes']) ?>
            </div>
          <?php endif; ?>

          <div style="display: flex; justify-content: flex-end; gap: 8px;">
            <a href="/academy/artigo/<?= (int)$fav['id'] ?>" class="btn btn-secondary btn-sm" style="font-size: 0.82rem; text-decoration: none;">
              Ver Estudo Completo & Citações →
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
