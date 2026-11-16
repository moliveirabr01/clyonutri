<?php
/** @var array $list */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$nonce = \ClyoNutri\Core\Http\SecurityHeaders::nonce(); // NI2-478: CSP com nonce e script-src-attr 'none'
$planId = (int)$list['plan_id'];
$isDraft = ($list['status'] === 'draft');
$days = (int)$list['days'];
$allergensInfo = $list['allergens_and_restrictions'] ?? [];
$recipes = $list['linked_recipes'] ?? [];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lista de Compras — <?= $e($list['patient_name']) ?> — Clyo Nutri</title>
  <link rel="stylesheet" href="/assets/css/fonts.css?v=v54-66">
  <link rel="stylesheet" href="/assets/css/clinical-botanical.css?v=v54-17">
  <link rel="stylesheet" href="/assets/css/shopping-list.css?v=v54-44">
<link rel="stylesheet" href="/assets/css/operational-ui.css?v=v54-66">
<link rel="stylesheet" href="/assets/css/ds-components.css?v=v54-44">
<link rel="stylesheet" href="/assets/css/ds-utilities.css?v=v54-44">
</head>
<body>
<div class="sl-container">
<?php if (!empty($list['patient_id']) && (\ClyoNutri\Core\Auth\TenantContext::getUser()['role'] ?? '') !== 'patient'): ?><?= \ClyoNutri\Application\View\AdaptiveSummary::render(\ClyoNutri\Core\Database\Connection::getInstance(), (int)$list['patient_id']) ?><?php endif; ?>

  <!-- Top Bar & Voltar -->
  <div class="no-print u-flex u-justify-between u-items-center u-mb-4">
    <a href="/planos/<?= $planId ?>" class="u-color-neutral-gray-600 u-no-underline u-text-compact u-font-medium">
      ← Voltar ao Plano Alimentar
    </a>
    <div class="u-text-compact u-color-neutral-gray-500">
      Emitido em <?= date('d/m/Y H:i') ?>
    </div>
  </div>

  <div class="sl-card">
    <!-- Header -->
    <div class="sl-header">
      <div>
        <div class="u-flex u-items-center u-gap-2 u-mb-1">
          <span class="sl-badge <?= $isDraft ? 'sl-badge--draft' : 'sl-badge--published' ?>">
            <?= $isDraft ? 'Rascunho (Prévia)' : 'Versão ' . (int)$list['version'] . ' Publicada' ?>
          </span>
          <span class="u-text-compact u-color-neutral-gray-500">
            <?= (int)$list['total_items_count'] ?> itens para <?= $days ?> dias
          </span>
        </div>
        <h1 class="u-m-0 u-text-h2 u-font-bold u-color-neutral-ink-900">
          <?= \ClyoNutri\Application\View\Icon::svg('shopping-cart', 22) ?> Lista de Compras de <?= $e($list['patient_name']) ?>
        </h1>
        <p class="u-mt-1 u-mb-0 u-mx-0 u-color-neutral-gray-500 u-text-compact">
          Plano: <strong><?= $e($list['plan_title']) ?></strong> • Nutricionista: <?= $e($list['professional_name']) ?>
        </p>
      </div>

      <div class="sl-controls no-print">
        <div class="u-flex u-items-center u-gap-1 u-bg-neutral-gray-50 u-p-1 u-radius-input u-border">
          <span class="u-text-caption u-color-neutral-gray-500 u-py-0 u-px-1 u-font-semibold">PERÍODO:</span>
          <a href="?dias=7" class="sl-days-btn <?= $days === 7 ? 'active' : '' ?>">7 dias</a>
          <a href="?dias=14" class="sl-days-btn <?= $days === 14 ? 'active' : '' ?>">14 dias</a>
          <a href="?dias=30" class="sl-days-btn <?= $days === 30 ? 'active' : '' ?>">30 dias</a>
        </div>
        <button type="button" id="btn-print" class="sl-btn sl-btn-primary">
          <?= \ClyoNutri\Application\View\Icon::svg('printer', 16) ?> Imprimir
        </button>
        <button type="button" class="sl-btn sl-btn-outline" id="btn-copy">
          <?= \ClyoNutri\Application\View\Icon::svg('clipboard-copy', 16) ?> Copiar Texto
        </button>
      </div>
    </div>

    <!-- Alertas de Alergênicos & Restrições -->
    <?php if (!empty($allergensInfo['has_conflicts'])): ?>
      <div class="sl-alert sl-alert-danger">
        <strong><?= \ClyoNutri\Application\View\Icon::svg('triangle-alert', 16) ?> Atenção a Alergias Registradas na Anamnese:</strong>
        <ul class="u-mt-1 u-mb-0 u-mx-0 u-pl-5 u-text-compact">
          <?php foreach ($allergensInfo['conflicts'] as $conf): ?>
            <li><?= $e($conf['message']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($allergensInfo['restrictions_recorded'])): ?>
      <div class="sl-alert sl-alert-warning">
        <strong><?= \ClyoNutri\Application\View\Icon::svg('clipboard-list', 16) ?> Restrições Alimentares Registradas:</strong> <?= $e($allergensInfo['restrictions_recorded']) ?>
      </div>
    <?php endif; ?>

    <!-- Seções de Gôndolas / Corredores -->
    <div class="u-mt-3">
      <?php if (empty($list['aisles'])): ?>
        <div class="u-p-12 u-text-center u-color-neutral-gray-400">
          Nenhum alimento cadastrado neste plano alimentar.
        </div>
      <?php else: ?>
        <?php foreach ($list['aisles'] as $aisleName => $items): ?>
          <section class="sl-aisle">
            <div class="sl-aisle-header">
              <span><?= $e($aisleName) ?></span>
              <span class="u-text-compact u-font-regular u-color-neutral-gray-500">
                <?= count($items) ?> <?= count($items) === 1 ? 'item' : 'itens' ?>
              </span>
            </div>
            <div>
              <?php foreach ($items as $item): ?>
                <div class="sl-item" data-key="<?= $e($item['key']) ?>">
                  <div class="sl-item-left">
                    <input type="checkbox" class="sl-checkbox no-print">
                    <div>
                      <div class="sl-item-name"><?= $e($item['name']) ?></div>
                      <div class="sl-item-meta">
                        <?php if (!empty($item['recipes_source'])): ?>
                          <span class="sl-tag sl-tag-recipe"><?= \ClyoNutri\Application\View\Icon::svg('utensils', 12) ?> Receita: <?= $e(implode(', ', $item['recipes_source'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['is_substituted'])): ?>
                          <span class="sl-tag sl-tag-sub">Substituição aplicada</span>
                        <?php endif; ?>
                        <?php if (!empty($item['has_allergen_conflict'])): ?>
                          <span class="sl-tag sl-tag-alert"><?= \ClyoNutri\Application\View\Icon::svg('triangle-alert', 12) ?> Alergia: <?= $e($item['allergen_group'] ?? 'conflito') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['meals_source']) && empty($item['recipes_source'])): ?>
                          <span>Refeições: <?= $e(implode(', ', $item['meals_source'])) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="sl-item-right">
                    <div class="sl-item-amount">
                      <?= number_format((float)$item['amount'], 1, ',', '.') ?> <?= $e($item['unit']) ?>
                    </div>
                    <?php if (!empty($item['daily']) && (float)$item['daily'] > 0): ?>
                      <div class="sl-item-daily">
                        (<?= number_format((float)$item['daily'], 1, ',', '.') ?> <?= $e($item['unit']) ?>/dia)
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Adicionar Item Manual no Navegador -->
    <div class="sl-manual-form no-print">
      <input type="text" id="manual-item-input" class="sl-manual-input" placeholder="+ Adicionar item avulso à lista (ex: Azeite de oliva extra virgem)">
      <button type="button" class="sl-btn sl-btn-primary" id="btn-add-manual">
        Adicionar
      </button>
    </div>

    <!-- Receitas Vinculadas ao Plano -->
    <?php if (!empty($recipes)): ?>
      <div class="sl-recipes-section">
        <h3 class="u-m-0 u-text-card-title u-font-bold u-color-neutral-ink-900">
          <?= \ClyoNutri\Application\View\Icon::svg('book-open', 16) ?> Receitas Vinculadas a este Plano
        </h3>
        <p class="u-mt-1 u-mb-3 u-mx-0 u-color-neutral-gray-500 u-text-compact">
          Os ingredientes destas receitas já foram incorporados e somados acima na lista de compras.
        </p>
        <div class="sl-recipes-grid">
          <?php foreach ($recipes as $r): ?>
            <div class="sl-recipe-card">
              <strong class="u-color-neutral-ink-900 u-block u-text-body u-mb-1">
                <?= $e($r['title']) ?>
              </strong>
              <div class="u-text-compact u-color-neutral-gray-500">
                Porção prescrita: <strong><?= number_format((float)$r['prescribed_servings'], 1, ',', '.') ?> porção(ões)</strong>
              </div>
              <div class="u-text-caption u-color-neutral-gray-400 u-mt-1">
                <?= $e($r['meal_name']) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<script nonce="<?= $e($nonce) ?>">
  const STORAGE_KEY = 'clyo_sl_checked_<?= $planId ?>';

  // Carregar itens marcados do localStorage
  function loadCheckedItems() {
    try {
      const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      stored.forEach(key => {
        const row = document.querySelector(`.sl-item[data-key="${key}"]`);
        if (row) {
          const chk = row.querySelector('.sl-checkbox');
          if (chk) chk.checked = true;
          row.classList.add('checked');
        }
      });
    } catch(e) {}
  }

  function toggleItem(checkbox) {
    const row = checkbox.closest('.sl-item');
    if (!row) return;
    const key = row.dataset.key;
    if (checkbox.checked) {
      row.classList.add('checked');
    } else {
      row.classList.remove('checked');
    }

    try {
      let stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      if (checkbox.checked && !stored.includes(key)) {
        stored.push(key);
      } else if (!checkbox.checked) {
        stored = stored.filter(k => k !== key);
      }
      localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
    } catch(e) {}
  }

  function addManualItem() {
    const input = document.getElementById('manual-item-input');
    const val = input.value.trim();
    if (!val) return;

    const miscSection = document.querySelector('.sl-aisle:last-child > div:last-child');
    if (miscSection) {
      const div = document.createElement('div');
      div.className = 'sl-item';
      div.dataset.key = 'custom_' + Date.now();
      div.innerHTML = `
        <div class="sl-item-left">
          <input type="checkbox" class="sl-checkbox no-print">
          <div>
            <div class="sl-item-name">${val}</div>
            <div class="sl-item-meta"><span class="sl-tag sl-tag-sub">Adicionado manualmente</span></div>
          </div>
        </div>
        <div class="sl-item-right">
          <div class="sl-item-amount">1 un</div>
        </div>
      `;
      miscSection.appendChild(div);
      input.value = '';
    }
  }

  function copyShoppingList() {
    let text = "🛒 *LISTA DE COMPRAS — <?= addslashes($e($list['patient_name'])) ?>*\n";
    text += "Plano: <?= addslashes($e($list['plan_title'])) ?> (<?= $days ?> dias)\n\n";

    <?php foreach ($list['aisles'] as $aisle => $items): ?>
      text += "📍 *<?= addslashes($e($aisle)) ?>*\n";
      <?php foreach ($items as $it): ?>
        text += "  • <?= addslashes($e($it['name'])) ?>: <?= number_format((float)$it['amount'], 1, ',', '.') ?> <?= addslashes($e($it['unit'])) ?>\n";
      <?php endforeach; ?>
      text += "\n";
    <?php endforeach; ?>

    text += "Gerado pelo Clyo Nutri.";

    navigator.clipboard.writeText(text).then(() => {
      const btn = document.getElementById('btn-copy');
      const orig = btn.innerText;
      btn.innerText = "✓ Copiado!";
      setTimeout(() => btn.innerText = orig, 2500);
    }).catch(() => {
      alert("Não foi possível copiar automaticamente. Selecione e copie o texto.");
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    loadCheckedItems();
    document.getElementById('btn-print')?.addEventListener('click', () => window.print());
    document.getElementById('btn-copy')?.addEventListener('click', copyShoppingList);
    document.getElementById('btn-add-manual')?.addEventListener('click', addManualItem);
    document.getElementById('manual-item-input')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        addManualItem();
      }
    });
    // Itens criados depois (addManualItem) também entram: delegação no documento.
    document.addEventListener('change', (e) => {
      if (e.target && e.target.classList && e.target.classList.contains('sl-checkbox')) {
        toggleItem(e.target);
      }
    });
  });
</script>
</body>
</html>
