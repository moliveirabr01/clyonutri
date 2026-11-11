<?php
/**
 * Regras de equivalência da clínica (NI2-519 / NI2-520, v43).
 * Variáveis: $available, $rules, $editing, $groups, $canReview, $showArchived, $old, $feedback, $error, $csrfToken
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
$form = $old ?? ($editing ? [
    'name' => $editing['name'], 'food_groups' => $editing['food_groups'], 'energy_tolerance_pct' => $num($editing['energy_tolerance_pct']),
    'macro_tolerance_pct' => $num($editing['macro_tolerance_pct']), 'source_note' => $editing['source_note'],
] : ['name' => '', 'food_groups' => [], 'energy_tolerance_pct' => '10', 'macro_tolerance_pct' => '20', 'source_note' => '']);
$chosen = array_map('strval', (array)($form['food_groups'] ?? []));
$inputStyle = 'width:100%; min-height:44px; padding:0 12px; border:1px solid var(--border-input, #828B9B); border-radius:6px;';
?>
<div data-testid="equivalence-rules-page" style="max-width: 980px;">
  <div style="margin-bottom: var(--space-4);">
    <a href="/alimentos" style="font-size: 0.9rem;">← Tabela de alimentos</a>
    <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 6px 0 4px;">Regras de equivalência</h1>
    <p style="color: var(--neutral-gray-600); margin: 0; font-size: 0.95rem; line-height: 1.5;">
      O Clyo Nutri não traz lista de equivalentes pronta: as listas publicadas que encontramos não permitem uso comercial.
      Aqui a clínica define quais grupos da TACO podem ser trocados entre si e quanto a energia e os macronutrientes podem variar.
      Toda regra precisa de fonte e nasce pendente de revisão por nutricionista. Sem regra, o plano mostra só a diferença de nutrientes e nunca diz que a troca é equivalente.
    </p>
  </div>

  <?php if ($error): ?>
    <div class="op-message op-message--error u-mb-4" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>
  <?php if ($feedback): ?>
    <div class="op-message op-message--info u-mb-4" role="status"><?= $e($feedback) ?></div>
  <?php endif; ?>

  <?php if (!$available): ?>
    <div class="op-message op-message--error card" role="alert" data-testid="equivalence-unavailable" style="padding: 16px;">As regras de equivalência ainda não estão disponíveis nesta instalação. Peça para aplicar a atualização do banco da versão 43.</div>
  <?php else: ?>

  <section aria-labelledby="eq-list" style="margin-bottom: 28px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
      <h2 id="eq-list" style="font-size:1.1rem; margin:0;"><?= $showArchived ? 'Todas as regras' : 'Regras ativas' ?></h2>
      <a href="/alimentos/equivalencias<?= $showArchived ? '' : '?arquivadas=1' ?>" style="font-size:0.9rem;"><?= $showArchived ? 'Ver só as ativas' : 'Ver também as arquivadas' ?></a>
    </div>
    <?php if ($rules === []): ?>
      <div class="card" data-testid="equivalence-empty" style="padding: 20px;">
        <p style="margin: 0 0 6px; font-weight: 600;">Nenhuma regra cadastrada.</p>
        <p style="margin: 0; color: var(--neutral-gray-600, #475467);">Enquanto não houver regra, as substituições do plano mostram só a diferença de energia e macronutrientes.</p>
      </div>
    <?php else: ?>
      <div class="card" style="padding: 0; overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
          <caption class="sr-only">Regras de equivalência da clínica</caption>
          <thead>
            <tr style="text-align: left; border-bottom: 1px solid var(--border-subtle, #E2E8F0);">
              <th scope="col" style="padding: 10px 14px;">Regra</th>
              <th scope="col" style="padding: 10px 14px;">Grupos</th>
              <th scope="col" style="padding: 10px 14px;">Tolerância</th>
              <th scope="col" style="padding: 10px 14px;">Revisão</th>
              <th scope="col" style="padding: 10px 14px;"><span class="sr-only">Ações</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rules as $r): ?>
              <tr data-testid="equivalence-row" data-review="<?= $e($r['review_status']) ?>" style="border-bottom: 1px solid var(--border-subtle, #E2E8F0); vertical-align: top;<?= $r['status'] !== 'active' ? ' color:var(--neutral-gray-500, #667085);' : '' ?>">
                <td style="padding: 10px 14px;">
                  <strong><?= $e($r['name']) ?></strong><?= $r['status'] !== 'active' ? ' (arquivada)' : '' ?>
                  <span style="display:block; font-size:0.8rem; color:var(--neutral-gray-600, #475467); margin-top:2px;">Fonte: <?= $e($r['source_note']) ?></span>
                </td>
                <td style="padding: 10px 14px;"><?= $e(implode(', ', $r['food_groups'])) ?></td>
                <td style="padding: 10px 14px;" class="tabular-nums">Energia até <?= $e($num($r['energy_tolerance_pct'])) ?>%<br>Macros até <?= $e($num($r['macro_tolerance_pct'])) ?>% (mínimo 1 g)</td>
                <td style="padding: 10px 14px;">
                  <?php if ($r['review_status'] === 'revisada'): ?>
                    <span style="color:var(--semantic-success-text, #0A5F63); font-weight:600;">Revisada</span><?php if (!empty($r['reviewed_at'])): ?><span style="display:block; font-size:0.8rem; color:var(--neutral-gray-600, #475467);">em <?= $e(date('d/m/Y', strtotime((string)$r['reviewed_at']))) ?></span><?php endif; ?>
                  <?php else: ?>
                    <span style="color:var(--botanical-copper-700, #8F3D14); font-weight:600;">Pendente de revisão</span>
                  <?php endif; ?>
                </td>
                <td style="padding: 10px 14px; text-align:right; white-space:nowrap;">
                  <?php if ($r['status'] === 'active'): ?>
                    <a class="btn btn-sm btn-outline" href="/alimentos/equivalencias?editar=<?= (int)$r['id'] ?>#eq-form" style="min-height:36px;">Editar</a>
                    <?php if ($canReview && $r['review_status'] !== 'revisada'): ?>
                      <form method="POST" action="/alimentos/equivalencias/<?= (int)$r['id'] ?>/revisar" style="display:inline;">
                        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                        <button type="submit" class="btn btn-sm btn-secondary" style="min-height:36px;" data-testid="equivalence-review">Marcar como revisada</button>
                      </form>
                    <?php endif; ?>
                    <form method="POST" action="/alimentos/equivalencias/<?= (int)$r['id'] ?>/arquivar" style="display:inline;" data-confirm="Arquivar esta regra? As substituições já gravadas não mudam.">
                      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                      <button type="submit" class="btn btn-sm btn-outline" style="min-height:36px;">Arquivar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$canReview): ?>
        <p style="font-size:0.85rem; color:var(--neutral-gray-600, #475467); margin-top:8px;">Para marcar uma regra como revisada, entre com um usuário de nutricionista com CRN cadastrado.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section aria-labelledby="eq-form" style="border:1px solid var(--botanical-sage-200, #D9E6E3); border-radius:12px; padding:16px; background:#fff;">
    <h2 id="eq-form" style="font-size:1.1rem; margin:0 0 10px;"><?= $editing ? 'Editar regra' : 'Nova regra' ?></h2>
    <?php if ($editing): ?><p style="margin:0 0 10px; font-size:0.85rem; color:var(--neutral-gray-600, #475467);">Ao salvar, a regra volta para pendente de revisão.</p><?php endif; ?>
    <form method="POST" action="/alimentos/equivalencias<?= $editing ? '/' . (int)$editing['id'] : '' ?>" data-testid="equivalence-form" style="display:grid; gap:12px;">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <div>
        <label for="eq-name" style="display:block; font-weight:600; font-size:0.875rem; margin-bottom:4px;">Nome da regra *</label>
        <input id="eq-name" name="name" required maxlength="120" value="<?= $e($form['name']) ?>" placeholder="Ex.: Trocas de cereais e tubérculos" style="<?= $inputStyle ?>">
      </div>
      <fieldset style="border:1px solid var(--botanical-sage-200, #D9E6E3); border-radius:8px; padding:10px 12px; margin:0;">
        <legend style="font-weight:600; font-size:0.875rem; padding:0 4px;">Grupos da TACO que podem ser trocados entre si *</legend>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:4px 12px;">
          <?php foreach ($groups as $i => $g): ?>
            <label style="display:flex; gap:8px; align-items:center; min-height:32px; font-size:0.875rem;">
              <input type="checkbox" name="food_groups[]" value="<?= $e($g) ?>"<?= in_array($g, $chosen, true) ? ' checked' : '' ?>> <?= $e($g) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
        <div>
          <label for="eq-energy" style="display:block; font-weight:600; font-size:0.875rem; margin-bottom:4px;">Tolerância de energia (%) *</label>
          <input id="eq-energy" name="energy_tolerance_pct" inputmode="decimal" required value="<?= $e($form['energy_tolerance_pct']) ?>" style="<?= $inputStyle ?>" aria-describedby="eq-energy-help">
          <small id="eq-energy-help" style="color:var(--neutral-gray-600, #475467);">De 0 a 50%. Diferença de kcal em relação ao item principal.</small>
        </div>
        <div>
          <label for="eq-macro" style="display:block; font-weight:600; font-size:0.875rem; margin-bottom:4px;">Tolerância de macronutrientes (%) *</label>
          <input id="eq-macro" name="macro_tolerance_pct" inputmode="decimal" required value="<?= $e($form['macro_tolerance_pct']) ?>" style="<?= $inputStyle ?>" aria-describedby="eq-macro-help">
          <small id="eq-macro-help" style="color:var(--neutral-gray-600, #475467);">De 0 a 100%, com folga mínima de 1 g para carboidrato, proteína e gordura.</small>
        </div>
      </div>
      <div>
        <label for="eq-source" style="display:block; font-weight:600; font-size:0.875rem; margin-bottom:4px;">Fonte que embasa a regra *</label>
        <textarea id="eq-source" name="source_note" required minlength="10" maxlength="500" rows="2" placeholder="Referência bibliográfica, protocolo interno ou consenso que a clínica adota" style="width:100%; padding:8px 12px; border:1px solid var(--border-input, #828B9B); border-radius:6px;"><?= $e($form['source_note']) ?></textarea>
      </div>
      <div style="display:flex; gap:8px; justify-content:flex-end;">
        <?php if ($editing): ?><a href="/alimentos/equivalencias" class="btn btn-outline" style="min-height:44px; display:inline-flex; align-items:center;">Cancelar</a><?php endif; ?>
        <button type="submit" class="btn btn-primary" style="min-height:44px;"><?= $editing ? 'Salvar alterações' : 'Criar regra' ?></button>
      </div>
    </form>
  </section>
  <?php endif; ?>
</div>
