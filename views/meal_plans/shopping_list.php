<?php
/** @var array $list */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
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
  <title>Lista de Compras — <?= $e($list['patient_name']) ?> — ClyoNutri</title>
  <link rel="stylesheet" href="/assets/css/design-system.css">
  <style>
    :root {
      --color-primary: #047857;
      --color-primary-light: #ecfdf5;
      --color-secondary: #0f172a;
      --color-accent: #c2410c;
      --color-warning-bg: #fffbeb;
      --color-warning-border: #fcd34d;
      --color-warning-text: #92400e;
      --color-danger-bg: #fef2f2;
      --color-danger-border: #fecaca;
      --color-danger-text: #991b1b;
      --color-border: #e2e8f0;
      --color-bg-subtle: #f8fafc;
    }

    body {
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #f1f5f9;
      color: #1e293b;
      line-height: 1.5;
    }

    .sl-container {
      max-width: 860px;
      margin: 24px auto;
      padding: 0 16px 48px;
    }

    .sl-card {
      background: #ffffff;
      border: 1px solid var(--color-border);
      border-radius: 12px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.04);
      margin-bottom: 20px;
      overflow: hidden;
    }

    .sl-header {
      padding: 24px 28px;
      border-bottom: 1px solid var(--color-border);
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 16px;
    }

    .sl-badge {
      display: inline-block;
      font-size: 0.75rem;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .sl-badge--published { background: #d1fae5; color: #065f46; }
    .sl-badge--draft { background: #fef3c7; color: #92400e; }

    .sl-controls {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .sl-days-btn {
      padding: 6px 12px;
      border: 1px solid var(--color-border);
      background: #ffffff;
      color: #334155;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.15s;
    }
    .sl-days-btn.active {
      background: var(--color-primary);
      color: #ffffff;
      border-color: var(--color-primary);
    }

    .sl-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      border: 1px solid transparent;
      transition: background 0.15s;
    }
    .sl-btn-primary { background: var(--color-primary); color: #fff; }
    .sl-btn-outline { background: #fff; color: #334155; border-color: var(--color-border); }

    .sl-alert {
      padding: 14px 20px;
      margin: 16px 28px 0;
      border-radius: 8px;
      font-size: 0.9rem;
    }
    .sl-alert-danger {
      background: var(--color-danger-bg);
      border: 1px solid var(--color-danger-border);
      color: var(--color-danger-text);
    }
    .sl-alert-warning {
      background: var(--color-warning-bg);
      border: 1px solid var(--color-warning-border);
      color: var(--color-warning-text);
    }

    .sl-aisle {
      border-bottom: 1px solid var(--color-border);
    }
    .sl-aisle:last-child {
      border-bottom: none;
    }

    .sl-aisle-header {
      background: var(--color-bg-subtle);
      padding: 12px 28px;
      font-weight: 700;
      font-size: 0.95rem;
      color: var(--color-secondary);
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-top: 1px solid var(--color-border);
    }
    .sl-aisle:first-child .sl-aisle-header {
      border-top: none;
    }

    .sl-item {
      padding: 12px 28px;
      border-top: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      transition: background 0.1s;
    }
    .sl-item:hover {
      background: #fafafa;
    }
    .sl-item.checked {
      background: #f8fafc;
      opacity: 0.55;
    }
    .sl-item.checked .sl-item-name {
      text-decoration: line-through;
    }

    .sl-item-left {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
    }
    .sl-checkbox {
      width: 18px;
      height: 18px;
      cursor: pointer;
      accent-color: var(--color-primary);
    }

    .sl-item-name {
      font-weight: 600;
      color: #0f172a;
      font-size: 0.95rem;
    }
    .sl-item-meta {
      font-size: 0.8rem;
      color: #64748b;
      margin-top: 2px;
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .sl-tag {
      font-size: 0.7rem;
      font-weight: 600;
      padding: 1px 6px;
      border-radius: 4px;
    }
    .sl-tag-recipe { background: #ede9fe; color: #5b21b6; }
    .sl-tag-sub { background: #e0f2fe; color: #0369a1; }
    .sl-tag-alert { background: #fee2e2; color: #991b1b; }

    .sl-item-right {
      text-align: right;
      white-space: nowrap;
    }
    .sl-item-amount {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--color-primary);
    }
    .sl-item-daily {
      font-size: 0.75rem;
      color: #94a3b8;
    }

    .sl-manual-form {
      padding: 16px 28px;
      background: #fafafa;
      border-top: 1px solid var(--color-border);
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .sl-manual-input {
      flex: 1;
      padding: 8px 12px;
      border: 1px solid var(--color-border);
      border-radius: 6px;
      font-size: 0.9rem;
    }

    .sl-recipes-section {
      padding: 20px 28px;
    }
    .sl-recipes-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 14px;
      margin-top: 12px;
    }
    .sl-recipe-card {
      padding: 14px;
      border: 1px solid var(--color-border);
      border-radius: 8px;
      background: #ffffff;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }

    @media print {
      body { background: #ffffff; }
      .sl-container { max-width: 100%; margin: 0; padding: 0; }
      .sl-card { border: none; box-shadow: none; }
      .sl-controls, .sl-manual-form, .no-print { display: none !important; }
      .sl-header { padding: 12px 0; }
      .sl-aisle-header { padding: 8px 0; background: none; border-bottom: 1px solid #000; }
      .sl-item { padding: 8px 0; }
    }
  </style>
</head>
<body>
<div class="sl-container">

  <!-- Top Bar & Voltar -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;" class="no-print">
    <a href="/planos/<?= $planId ?>" style="color: #475569; text-decoration: none; font-size: 0.9rem; font-weight: 500;">
      ← Voltar ao Plano Alimentar
    </a>
    <div style="font-size: 0.85rem; color: #64748b;">
      Emitido em <?= date('d/m/Y H:i') ?>
    </div>
  </div>

  <div class="sl-card">
    <!-- Header -->
    <div class="sl-header">
      <div>
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
          <span class="sl-badge <?= $isDraft ? 'sl-badge--draft' : 'sl-badge--published' ?>">
            <?= $isDraft ? 'Rascunho (Prévia)' : 'Versão ' . (int)$list['version'] . ' Publicada' ?>
          </span>
          <span style="font-size: 0.85rem; color: #64748b;">
            <?= (int)$list['total_items_count'] ?> itens para <?= $days ?> dias
          </span>
        </div>
        <h1 style="margin: 0; font-size: 1.45rem; font-weight: 700; color: #0f172a;">
          🛒 Lista de Compras de <?= $e($list['patient_name']) ?>
        </h1>
        <p style="margin: 4px 0 0; color: #64748b; font-size: 0.9rem;">
          Plano: <strong><?= $e($list['plan_title']) ?></strong> • Nutricionista: <?= $e($list['professional_name']) ?>
        </p>
      </div>

      <div class="sl-controls no-print">
        <div style="display: flex; align-items: center; gap: 4px; background: #f8fafc; padding: 4px; border-radius: 8px; border: 1px solid var(--color-border);">
          <span style="font-size: 0.75rem; color: #64748b; padding: 0 4px; font-weight: 600;">PERÍODO:</span>
          <a href="?dias=7" class="sl-days-btn <?= $days === 7 ? 'active' : '' ?>">7 dias</a>
          <a href="?dias=14" class="sl-days-btn <?= $days === 14 ? 'active' : '' ?>">14 dias</a>
          <a href="?dias=30" class="sl-days-btn <?= $days === 30 ? 'active' : '' ?>">30 dias</a>
        </div>
        <button type="button" onclick="window.print()" class="sl-btn sl-btn-primary">
          🖨️ Imprimir
        </button>
        <button type="button" onclick="copyShoppingList()" class="sl-btn sl-btn-outline" id="btn-copy">
          📋 Copiar Texto
        </button>
      </div>
    </div>

    <!-- Alertas de Alergênicos & Restrições -->
    <?php if (!empty($allergensInfo['has_conflicts'])): ?>
      <div class="sl-alert sl-alert-danger">
        <strong>⚠️ Atenção a Alergias Registradas na Anamnese:</strong>
        <ul style="margin: 6px 0 0; padding-left: 20px; font-size: 0.85rem;">
          <?php foreach ($allergensInfo['conflicts'] as $conf): ?>
            <li><?= $e($conf['message']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($allergensInfo['restrictions_recorded'])): ?>
      <div class="sl-alert sl-alert-warning">
        <strong>📋 Restrições Alimentares Registradas:</strong> <?= $e($allergensInfo['restrictions_recorded']) ?>
      </div>
    <?php endif; ?>

    <!-- Seções de Gôndolas / Corredores -->
    <div style="margin-top: 12px;">
      <?php if (empty($list['aisles'])): ?>
        <div style="padding: 48px; text-align: center; color: #94a3b8;">
          Nenhum alimento cadastrado neste plano alimentar.
        </div>
      <?php else: ?>
        <?php foreach ($list['aisles'] as $aisleName => $items): ?>
          <section class="sl-aisle">
            <div class="sl-aisle-header">
              <span><?= $e($aisleName) ?></span>
              <span style="font-size: 0.8rem; font-weight: normal; color: #64748b;">
                <?= count($items) ?> <?= count($items) === 1 ? 'item' : 'itens' ?>
              </span>
            </div>
            <div>
              <?php foreach ($items as $item): ?>
                <div class="sl-item" data-key="<?= $e($item['key']) ?>">
                  <div class="sl-item-left">
                    <input type="checkbox" class="sl-checkbox no-print" onchange="toggleItem(this)">
                    <div>
                      <div class="sl-item-name"><?= $e($item['name']) ?></div>
                      <div class="sl-item-meta">
                        <?php if (!empty($item['recipes_source'])): ?>
                          <span class="sl-tag sl-tag-recipe">🍽️ Receita: <?= $e(implode(', ', $item['recipes_source'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['is_substituted'])): ?>
                          <span class="sl-tag sl-tag-sub">Substituição aplicada</span>
                        <?php endif; ?>
                        <?php if (!empty($item['has_allergen_conflict'])): ?>
                          <span class="sl-tag sl-tag-alert">⚠️ Alergia: <?= $e($item['allergen_group'] ?? 'conflito') ?></span>
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
      <button type="button" class="sl-btn sl-btn-primary" onclick="addManualItem()">
        Adicionar
      </button>
    </div>

    <!-- Receitas Vinculadas ao Plano -->
    <?php if (!empty($recipes)): ?>
      <div class="sl-recipes-section">
        <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: #0f172a;">
          📖 Receitas Vinculadas a este Plano
        </h3>
        <p style="margin: 4px 0 12px; color: #64748b; font-size: 0.85rem;">
          Os ingredientes destas receitas já foram incorporados e somados acima na lista de compras.
        </p>
        <div class="sl-recipes-grid">
          <?php foreach ($recipes as $r): ?>
            <div class="sl-recipe-card">
              <strong style="color: #0f172a; display: block; font-size: 0.95rem; margin-bottom: 4px;">
                <?= $e($r['title']) ?>
              </strong>
              <div style="font-size: 0.8rem; color: #64748b;">
                Porção prescrita: <strong><?= number_format((float)$r['prescribed_servings'], 1, ',', '.') ?> porção(ões)</strong>
              </div>
              <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 4px;">
                <?= $e($r['meal_name']) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
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
          <input type="checkbox" class="sl-checkbox no-print" onchange="toggleItem(this)">
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

    text += "Gerado pelo ClyoNutri.";

    navigator.clipboard.writeText(text).then(() => {
      const btn = document.getElementById('btn-copy');
      const orig = btn.innerText;
      btn.innerText = "✓ Copiado!";
      setTimeout(() => btn.innerText = orig, 2500);
    }).catch(() => {
      alert("Não foi possível copiar automaticamente. Selecione e copie o texto.");
    });
  }

  document.addEventListener('DOMContentLoaded', loadCheckedItems);
</script>
</body>
</html>
