<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $article
 * @var array<string, mixed>|null $evidenceSheet
 * @var array{abnt: string, vancouver: string, bibtex: string} $citations
 * @var bool $isFavorite
 * @var array<string> $collections
 * @var string $csrfToken
 * @var string $subTab
 */

require __DIR__ . '/_header.php';
$designLabel = \ClyoNutri\Domain\Academy\AcademyArticleService::studyDesignLabel($article['study_design'] ?? '');
?>

<div class="academy-content" style="max-width: 960px;">
  <!-- Voltar -->
  <div style="margin-bottom: 16px;">
    <a href="/academy" style="color: var(--brand-primary, #1B4D3E); text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; font-weight: 500;">
      ← Voltar para a Biblioteca
    </a>
  </div>

  <!-- Cartão Principal do Artigo -->
  <div class="card" style="padding: 28px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); margin-bottom: 24px; box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px;">
      <span style="display: inline-block; padding: 4px 10px; background: #F4EBFF; color: #6941C6; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
        <?= htmlspecialchars($designLabel) ?>
      </span>
      <?php if (($article['access_type'] ?? '') === 'open_access'): ?>
        <span style="display: inline-block; padding: 4px 10px; background: #ECFDF3; color: #027A48; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
          Open Access (Acesso Aberto)
        </span>
      <?php endif; ?>
      <?php if (!empty($article['retraction_status']) && $article['retraction_status'] !== 'none'): ?>
        <span style="display: inline-block; padding: 4px 10px; background: #FEF3F2; color: #B42318; border-radius: 4px; font-size: 0.8rem; font-weight: 700;">
          Sinalização: <?= htmlspecialchars(strtoupper($article['retraction_status'])) ?>
        </span>
      <?php endif; ?>
    </div>

    <h1 style="margin: 0 0 12px 0; font-size: 1.6rem; font-weight: 700; color: var(--neutral-gray-900, #101828); line-height: 1.3;">
      <?= htmlspecialchars($article['title']) ?>
    </h1>

    <div style="font-size: 0.95rem; color: var(--neutral-gray-700, #344054); margin-bottom: 16px; line-height: 1.5;">
      <strong>Autores:</strong> <?= htmlspecialchars($article['authors']) ?><br />
      <strong>Periódico:</strong> <em><?= htmlspecialchars($article['journal']) ?></em> (<?= (int)$article['publication_year'] ?>)
    </div>

    <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; padding: 12px 16px; background: var(--neutral-gray-50, #F9FAFB); border-radius: 8px; font-size: 0.85rem; color: var(--neutral-gray-600, #475467);">
      <?php if (!empty($article['doi'])): ?>
        <div>DOI: <strong><?= htmlspecialchars($article['doi']) ?></strong></div>
      <?php endif; ?>
      <?php if (!empty($article['pmid'])): ?>
        <div>PMID: <strong><?= htmlspecialchars($article['pmid']) ?></strong></div>
      <?php endif; ?>
      <div>Fonte de Metadados: <strong><?= htmlspecialchars(strtoupper($article['source'] ?? 'PubMed')) ?></strong></div>
      <div>Indexado em: <strong><?= htmlspecialchars(date('d/m/Y', strtotime($article['created_at']))) ?></strong></div>
    </div>

    <!-- Ações Rápidas -->
    <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 24px;">
      <a href="<?= htmlspecialchars($article['url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; padding: 10px 18px; font-weight: 600;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        <span>Abrir no Periódico Oficial</span>
      </a>

      <form method="POST" action="/academy/artigo/<?= (int)$article['id'] ?>/favoritar" style="margin: 0;">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; font-weight: 600;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="<?= $isFavorite ? '#E04F16' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: <?= $isFavorite ? '#E04F16' : 'inherit' ?>;"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
          <span><?= $isFavorite ? 'Salvo na Minha Coleção' : 'Salvar na Minha Coleção' ?></span>
        </button>
      </form>
    </div>

    <!-- Resumo Oficial -->
    <?php if (!empty($article['abstract'])): ?>
      <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--neutral-gray-200, #EAECF0);">
        <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--neutral-gray-900, #101828); margin-bottom: 10px;">
          Resumo do Estudo (Abstract)
        </h2>
        <div style="font-size: 0.95rem; line-height: 1.6; color: var(--neutral-gray-800, #1D2939); white-space: pre-line;">
          <?= htmlspecialchars($article['abstract']) ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Ficha Crítica PICO (Evidence Desk) se existir -->
  <?php if ($evidenceSheet): ?>
    <?php $certainty = \ClyoNutri\Domain\Academy\AcademyEvidenceDeskService::certaintyBadge($evidenceSheet['evidence_certainty'] ?? ''); ?>
    <div class="card" style="padding: 24px; background: #fff; border-radius: 12px; border: 1px solid #D0D5DD; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(16,24,40,0.05);">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
        <div>
          <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--brand-copper-600, #E04F16); letter-spacing: 0.5px;">Evidence Desk</span>
          <h2 style="margin: 2px 0 0 0; font-size: 1.25rem; font-weight: 700; color: var(--neutral-gray-900, #101828);">
            Ficha de Leitura Crítica & Avaliação Metodológica
          </h2>
        </div>
        <div style="display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 6px; background: <?= $certainty['bg'] ?>; color: <?= $certainty['color'] ?>; font-weight: 700; font-size: 0.82rem;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span><?= htmlspecialchars($certainty['label']) ?> (GRADE)</span>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div style="padding: 12px; background: #F9FAFB; border-radius: 8px;">
          <strong style="color: var(--brand-primary, #1B4D3E); font-size: 0.85rem; display: block; margin-bottom: 4px;">P — População & Amostra</strong>
          <span style="font-size: 0.9rem; color: #344054; line-height: 1.4;"><?= htmlspecialchars($evidenceSheet['population']) ?></span>
        </div>
        <div style="padding: 12px; background: #F9FAFB; border-radius: 8px;">
          <strong style="color: var(--brand-primary, #1B4D3E); font-size: 0.85rem; display: block; margin-bottom: 4px;">I — Intervenção / Exposição</strong>
          <span style="font-size: 0.9rem; color: #344054; line-height: 1.4;"><?= htmlspecialchars($evidenceSheet['intervention']) ?></span>
        </div>
        <div style="padding: 12px; background: #F9FAFB; border-radius: 8px;">
          <strong style="color: var(--brand-primary, #1B4D3E); font-size: 0.85rem; display: block; margin-bottom: 4px;">C — Comparador / Controle</strong>
          <span style="font-size: 0.9rem; color: #344054; line-height: 1.4;"><?= htmlspecialchars($evidenceSheet['comparator']) ?></span>
        </div>
        <div style="padding: 12px; background: #F9FAFB; border-radius: 8px;">
          <strong style="color: var(--brand-primary, #1B4D3E); font-size: 0.85rem; display: block; margin-bottom: 4px;">O — Desfechos Clínicos Principais</strong>
          <span style="font-size: 0.9rem; color: #344054; line-height: 1.4;"><?= htmlspecialchars($evidenceSheet['outcomes']) ?></span>
        </div>
      </div>

      <div style="margin-bottom: 16px;">
        <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--neutral-gray-800, #1D2939); margin-bottom: 6px;">
          Limitações Metodológicas & Risco de Viés
        </h3>
        <p style="margin: 0; font-size: 0.9rem; color: #475467; line-height: 1.5;">
          <?= htmlspecialchars($evidenceSheet['methodological_limitations']) ?>
        </p>
      </div>

      <div style="margin-bottom: 16px; padding: 14px; background: #F4F8F6; border-left: 4px solid var(--brand-primary, #1B4D3E); border-radius: 0 8px 8px 0;">
        <h3 style="font-size: 0.95rem; font-weight: 700; color: var(--brand-primary, #1B4D3E); margin: 0 0 6px 0;">
          Aplicabilidade na Prática Clínica do Nutricionista
        </h3>
        <p style="margin: 0; font-size: 0.9rem; color: #1D2939; line-height: 1.5;">
          <?= htmlspecialchars($evidenceSheet['clinical_applicability']) ?>
        </p>
      </div>

      <?php if (!empty($evidenceSheet['conflict_of_interest'])): ?>
        <div style="font-size: 0.82rem; color: #667085; padding-top: 12px; border-top: 1px solid #EAECF0;">
          <strong>Declaração de Financiamento e Conflitos do Estudo:</strong>
          <?= htmlspecialchars($evidenceSheet['conflict_of_interest']) ?>
        </div>
      <?php endif; ?>

      <div style="font-size: 0.8rem; color: #98A2B3; margin-top: 10px;">
        Curadoria técnica realizada por: <strong><?= htmlspecialchars($evidenceSheet['curator_name']) ?></strong> (<?= htmlspecialchars($evidenceSheet['curator_crn'] ?? 'CRN verificado') ?>).
      </div>
    </div>
  <?php endif; ?>

  <!-- Citações Acadêmicas Prontas -->
  <div class="card" style="padding: 24px; background: #fff; border-radius: 12px; border: 1px solid var(--neutral-gray-200, #EAECF0); margin-bottom: 24px;">
    <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--neutral-gray-900, #101828); margin: 0 0 16px 0;">
      Citação Pronta nos Padrões Científicos
    </h2>

    <div style="display: flex; flex-direction: column; gap: 14px;">
      <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
          <span style="font-size: 0.8rem; font-weight: 700; color: #475467; text-transform: uppercase;">Padrão ABNT</span>
          <button type="button" onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText); this.innerText='Copiado!';" style="font-size: 0.75rem; padding: 2px 8px; border: 1px solid #D0D5DD; background: #fff; border-radius: 4px; cursor: pointer;">Copiar</button>
          <span style="display:none;"><?= htmlspecialchars($citations['abnt']) ?></span>
        </div>
        <div style="padding: 10px 14px; background: #F9FAFB; border-radius: 6px; font-size: 0.88rem; color: #1D2939; font-family: monospace; border: 1px solid #EAECF0;">
          <?= htmlspecialchars($citations['abnt']) ?>
        </div>
      </div>

      <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
          <span style="font-size: 0.8rem; font-weight: 700; color: #475467; text-transform: uppercase;">Padrão Vancouver</span>
          <button type="button" onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText); this.innerText='Copiado!';" style="font-size: 0.75rem; padding: 2px 8px; border: 1px solid #D0D5DD; background: #fff; border-radius: 4px; cursor: pointer;">Copiar</button>
          <span style="display:none;"><?= htmlspecialchars($citations['vancouver']) ?></span>
        </div>
        <div style="padding: 10px 14px; background: #F9FAFB; border-radius: 6px; font-size: 0.88rem; color: #1D2939; font-family: monospace; border: 1px solid #EAECF0;">
          <?= htmlspecialchars($citations['vancouver']) ?>
        </div>
      </div>

      <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
          <span style="font-size: 0.8rem; font-weight: 700; color: #475467; text-transform: uppercase;">BibTeX</span>
          <button type="button" onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText); this.innerText='Copiado!';" style="font-size: 0.75rem; padding: 2px 8px; border: 1px solid #D0D5DD; background: #fff; border-radius: 4px; cursor: pointer;">Copiar</button>
          <span style="display:none;"><?= htmlspecialchars($citations['bibtex']) ?></span>
        </div>
        <pre style="margin: 0; padding: 10px 14px; background: #F9FAFB; border-radius: 6px; font-size: 0.82rem; color: #1D2939; border: 1px solid #EAECF0; overflow-x: auto;"><?= htmlspecialchars($citations['bibtex']) ?></pre>
      </div>
    </div>
  </div>
</div>
