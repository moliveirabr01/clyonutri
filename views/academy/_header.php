<?php
declare(strict_types=1);

/** @var string $subTab */
$currentTab = $subTab ?? 'library';
?>

<div class="academy-header" style="margin-bottom: 24px;">
  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 16px;">
    <div>
      <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
        <span style="display: inline-block; padding: 2px 8px; background: #E8F5E9; color: #2E7D32; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Clyo Academy</span>
        <span style="font-size: 0.8rem; color: var(--neutral-gray-500, #667085);">Literatura Científica & Prática Clínica Baseada em Evidências</span>
      </div>
      <h1 style="margin: 0; font-size: 1.85rem; font-weight: 700; color: var(--neutral-gray-900, #101828); line-height: 1.2;">
        Clyo Academy
      </h1>
      <p style="margin: 6px 0 0 0; color: var(--neutral-gray-600, #475467); font-size: 0.95rem;">
        Pesquisa, fichas de leitura crítica, sínteses de conduta e publicações profissionais em nutrição clínica.
      </p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
      <a href="/academy/publicacoes/nova" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; font-weight: 600; text-decoration: none;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Nova Publicação</span>
      </a>
    </div>
  </div>

  <!-- Navegação de Sub-Abas -->
  <nav class="academy-nav-tabs" style="display: flex; gap: 4px; border-bottom: 2px solid var(--neutral-gray-200, #EAECF0); overflow-x: auto;" aria-label="Navegação da Academy">
    <a href="/academy" class="academy-tab <?= $currentTab === 'library' ? 'active' : '' ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 0.92rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $currentTab === 'library' ? 'var(--brand-primary, #1B4D3E)' : 'transparent' ?>; margin-bottom: -2px; color: <?= $currentTab === 'library' ? 'var(--brand-primary, #1B4D3E)' : 'var(--neutral-gray-600, #475467)' ?>;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg>
      <span>Biblioteca Científica</span>
    </a>

    <a href="/academy/evidencias" class="academy-tab <?= $currentTab === 'evidence' ? 'active' : '' ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 0.92rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $currentTab === 'evidence' ? 'var(--brand-primary, #1B4D3E)' : 'transparent' ?>; margin-bottom: -2px; color: <?= $currentTab === 'evidence' ? 'var(--brand-primary, #1B4D3E)' : 'var(--neutral-gray-600, #475467)' ?>;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      <span>Evidence Desk (Fichas PICO)</span>
    </a>

    <a href="/academy/colecao" class="academy-tab <?= $currentTab === 'collection' ? 'active' : '' ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 0.92rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $currentTab === 'collection' ? 'var(--brand-primary, #1B4D3E)' : 'transparent' ?>; margin-bottom: -2px; color: <?= $currentTab === 'collection' ? 'var(--brand-primary, #1B4D3E)' : 'var(--neutral-gray-600, #475467)' ?>;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
      <span>Minha Coleção</span>
    </a>

    <a href="/academy/publicacoes" class="academy-tab <?= $currentTab === 'publications' ? 'active' : '' ?>" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 18px; font-size: 0.92rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $currentTab === 'publications' ? 'var(--brand-primary, #1B4D3E)' : 'transparent' ?>; margin-bottom: -2px; color: <?= $currentTab === 'publications' ? 'var(--brand-primary, #1B4D3E)' : 'var(--neutral-gray-600, #475467)' ?>;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      <span>Publicações & Sínteses</span>
    </a>
  </nav>
</div>

<?php if (!empty($flash)): ?>
  <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>" style="margin-bottom: 20px; padding: 12px 16px; border-radius: 8px; display: flex; align-items: center; gap: 10px; background: <?= $flash['type'] === 'error' ? '#FEF3F2' : '#ECFDF3' ?>; color: <?= $flash['type'] === 'error' ? '#B42318' : '#027A48' ?>; border: 1px solid <?= $flash['type'] === 'error' ? '#FECDCA' : '#A6F4C5' ?>;">
    <span><?= htmlspecialchars($flash['message']) ?></span>
  </div>
<?php endif; ?>
