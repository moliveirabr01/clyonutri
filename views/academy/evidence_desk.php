<?php
declare(strict_types=1);

/**
 * @var array{total: int, items: array<int, array<string, mixed>>} $results
 * @var array<int> $favoriteIds
 * @var string $csrfToken
 * @var string $subTab
 */

require __DIR__ . '/_header.php';
?>

<div class="academy-content">
  <div class="card" style="margin-bottom: 24px; padding: 24px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
      <div>
        <h2 style="margin: 0 0 6px 0; font-size: 1.35rem; font-weight: 700; color: var(--neutral-gray-900, #101828);">
          Evidence Desk — Leitura Crítica & Avaliação Metodológica
        </h2>
        <p style="margin: 0; color: var(--neutral-gray-600, #475467); font-size: 0.95rem; line-height: 1.5; max-width: 820px;">
          Fichas estruturadas de evidência clínica elaboradas por nutricionistas curadores. Cada estudo passa por decomposição metodológica (PICO), avaliação de vieses, conflitos de interesse e atribuição de nível de certeza (GRADE), sem scores automatizados ou conclusões simplistas.
        </p>
      </div>
      <div style="padding: 10px 16px; background: #F4F8F6; border-radius: 8px; border-left: 3px solid var(--brand-primary, #1B4D3E); font-size: 0.85rem; color: #1B4D3E;">
        <strong>Padrão PICO:</strong> População, Intervenção, Comparador e Desfecho clínico.
      </div>
    </div>
  </div>

  <?php if (empty($results['items'])): ?>
    <div class="card" style="padding: 48px 24px; text-align: center; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0);">
      <p style="margin: 0; color: var(--neutral-gray-500, #667085); font-size: 0.95rem;">Nenhuma ficha crítica disponível no momento.</p>
    </div>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 16px;">
      <?php foreach ($results['items'] as $art): ?>
        <?php
          $isFav = in_array((int)$art['id'], $favoriteIds, true);
          $designLabel = \ClyoNutri\Domain\Academy\AcademyArticleService::studyDesignLabel($art['study_design'] ?? '');
        ?>
        <article class="card" style="padding: 22px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 8px;">
            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
              <span style="display: inline-block; padding: 3px 8px; background: #FEF0C7; color: #B54708; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">
                Ficha Crítica PICO
              </span>
              <span style="display: inline-block; padding: 3px 8px; background: #F4EBFF; color: #6941C6; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                <?= htmlspecialchars($designLabel) ?>
              </span>
              <?php if (($art['access_type'] ?? '') === 'open_access'): ?>
                <span style="display: inline-block; padding: 3px 8px; background: #ECFDF3; color: #027A48; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                  Open Access
                </span>
              <?php endif; ?>
            </div>

            <form method="POST" action="/academy/artigo/<?= (int)$art['id'] ?>/favoritar" style="margin: 0;">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="back" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/academy'), ENT_QUOTES, 'UTF-8') ?>">
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

          <h3 style="margin: 0 0 8px 0; font-size: 1.15rem; font-weight: 600; line-height: 1.4;">
            <a href="/academy/artigo/<?= (int)$art['id'] ?>" style="color: var(--neutral-gray-900, #101828); text-decoration: none;">
              <?= htmlspecialchars($art['title']) ?>
            </a>
          </h3>

          <div style="font-size: 0.85rem; color: var(--neutral-gray-600, #475467); margin-bottom: 12px;">
            <?= htmlspecialchars($art['authors']) ?> — <em><?= htmlspecialchars($art['journal']) ?></em> (<?= (int)$art['publication_year'] ?>)
          </div>

          <?php if (\ClyoNutri\Domain\Academy\AcademyArticleService::abstractVisible($art)): ?>
            <p style="margin: 0 0 16px 0; font-size: 0.9rem; color: var(--neutral-gray-700, #344054); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
              <?= htmlspecialchars($art['abstract']) ?>
            </p>
          <?php endif; ?>

          <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #F2F4F7; flex-wrap: wrap; gap: 10px;">
            <span style="font-size: 0.82rem; color: #475467;">
              Condições relacionadas: <strong><?= htmlspecialchars($art['condition_tags'] ?? 'Geral') ?></strong>
            </span>
            <a href="/academy/artigo/<?= (int)$art['id'] ?>" class="btn btn-primary btn-sm" style="font-size: 0.85rem; padding: 6px 14px; text-decoration: none; font-weight: 600;">
              Abrir Ficha de Leitura Crítica PICO →
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
