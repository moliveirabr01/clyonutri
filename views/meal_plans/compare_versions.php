<?php
/** @var array $patient */
/** @var list<array<string, mixed>> $versions */
/** @var array<string, mixed>|null $diff */
/** @var string|null $diffError */
?>
<div class="u-flex u-justify-between u-items-start u-mb-6 u-wrap u-gap-4">
  <div>
    <div class="u-flex u-items-center u-gap-2 u-mb-1">
      <a href="/pacientes/<?= (int)$patient['id'] ?>" class="u-no-underline u-color-neutral-gray-600 u-text-compact">← Prontuário de <?= htmlspecialchars((string)$patient['name']) ?></a>
      <span class="badge u-bg-semantic-success-light u-color-semantic-success-text u-py-1 u-px-2 u-radius-input u-font-semibold u-text-caption">
        COMPARATIVO DE RETORNO (NI2-526)
      </span>
    </div>
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-m-0">Comparar Versões Publicadas do Plano Alimentar</h1>
    <p class="u-color-neutral-gray-500 u-mt-1">
      Análise lado a lado dos snapshots imutáveis publicados para <strong><?= htmlspecialchars((string)$patient['name']) ?></strong> (não sofre alteração caso o catálogo de alimentos mude depois).
    </p>
  </div>
</div>

<?php if ($diffError !== null): ?>
  <div class="op-message op-message--error card u-mb-5" role="alert" data-testid="version-diff-error">
    <strong>Não foi possível comparar:</strong> <?= htmlspecialchars($diffError) ?>
  </div>
<?php endif; ?>

<?php if (count($versions) >= 2): ?>
  <form method="GET" action="/pacientes/<?= (int)$patient['id'] ?>/planos/comparar" class="card u-py-4 u-px-5 u-mb-6 u-flex u-wrap u-gap-4 u-items-end" data-testid="version-diff-selector">
    <div class="u-flex-1 u-minw-lg">
      <label class="form-label" for="ver-a">Versão Base (Anterior)</label>
      <select class="form-control" id="ver-a" name="v_a">
        <?php foreach ($versions as $v): ?>
          <option value="<?= (int)$v['version_number'] ?>" <?= (int)($diff['version_a']['version_number'] ?? 0) === (int)$v['version_number'] ? 'selected' : '' ?>>
            v<?= (int)$v['version_number'] ?> — <?= htmlspecialchars((string)$v['title']) ?> (<?= number_format((float)$v['daily_energy_kcal'], 0, ',', '.') ?> kcal · <?= date('d/m/Y', strtotime((string)$v['published_at'])) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="u-flex-1 u-minw-lg">
      <label class="form-label" for="ver-b">Versão Comparada (Atual / Retorno)</label>
      <select class="form-control" id="ver-b" name="v_b">
        <?php foreach ($versions as $v): ?>
          <option value="<?= (int)$v['version_number'] ?>" <?= (int)($diff['version_b']['version_number'] ?? 0) === (int)$v['version_number'] ? 'selected' : '' ?>>
            v<?= (int)$v['version_number'] ?> — <?= htmlspecialchars((string)$v['title']) ?> (<?= number_format((float)$v['daily_energy_kcal'], 0, ',', '.') ?> kcal · <?= date('d/m/Y', strtotime((string)$v['published_at'])) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Comparar versões</button>
  </form>
<?php endif; ?>

<?php if ($diff !== null): ?>
  <!-- Cards de Delta Total (Kcal e Macronutrientes) -->
  <div data-testid="version-diff-totals" class="u-grid u-cols-auto-md u-gap-3 u-mb-6">
    <?php
      $nutrientLabels = [
          'energy_kcal' => ['Energia Diária', 'kcal', 0],
          'protein_g' => ['Proteínas', 'g', 1],
          'carbohydrate_g' => ['Carboidratos', 'g', 1],
          'lipid_g' => ['Lipídeos', 'g', 1],
          'fiber_g' => ['Fibras', 'g', 1],
      ];
      foreach ($nutrientLabels as $nKey => [$nLabel, $nUnit, $nDec]):
        $tRow = $diff['totals_diff'][$nKey];
        $dVal = (float)$tRow['delta'];
        $dSign = $dVal > 0 ? '+' : '';
        $dColor = abs($dVal) < 0.01 ? 'ds-tone-muted' : ($dVal > 0 ? 'ds-tone-info' : 'ds-delta--down');
    ?>
      <div class="card u-p-4" data-testid="diff-card-<?= htmlspecialchars($nKey) ?>">
        <div class="u-text-caption u-font-semibold u-color-neutral-gray-500 u-uppercase"><?= htmlspecialchars($nLabel) ?></div>
        <div class="tabular-nums u-text-h3 u-font-bold u-color-botanical-forest-900 u-mt-1">
          <?= number_format((float)$tRow['from'], $nDec, ',', '.') ?> → <?= number_format((float)$tRow['to'], $nDec, ',', '.') ?> <?= htmlspecialchars($nUnit) ?>
        </div>
        <div class="tabular-nums u-text-compact u-font-semibold u-mt-1 <?= $dColor ?>">
          Δ <?= $dSign . number_format($dVal, $nDec, ',', '.') ?> <?= htmlspecialchars($nUnit) ?>
          <?php if ($tRow['pct'] !== null): ?>
            (<?= ($tRow['pct'] > 0 ? '+' : '') . number_format((float)$tRow['pct'], 1, ',', '.') ?>%)
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Comparação por Refeição -->
  <div class="card u-p-5 u-mb-6" data-testid="version-diff-meals">
    <h2 class="u-text-card-title u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-4 u-mx-0">Diferenças por Refeição (v<?= (int)$diff['version_a']['version_number'] ?> → v<?= (int)$diff['version_b']['version_number'] ?>)</h2>
    <?php foreach ($diff['meals_diff'] as $mDiff): ?>
      <div data-testid="meal-diff-row" class="u-border u-radius-input u-py-3 u-px-4 u-mb-3">
        <div class="u-flex u-justify-between u-items-center u-wrap u-gap-2 u-mb-2">
          <div>
            <strong class="u-color-botanical-forest-900 u-text-body"><?= htmlspecialchars((string)$mDiff['meal_name']) ?></strong>
            <span class="badge ds-pill u-ml-2 <?= $mDiff['status'] === 'unchanged' ? 'ds-pill--neutral' : 'ds-pill--warning' ?>">
              <?= ['added' => 'Nova refeição', 'removed' => 'Refeição removida', 'modified' => 'Alterada', 'unchanged' => 'Sem alterações'][$mDiff['status']] ?? $mDiff['status'] ?>
            </span>
          </div>
          <div class="tabular-nums u-text-compact u-color-neutral-gray-700 u-font-semibold">
            <?= number_format((float)$mDiff['totals_a']['energy_kcal'], 0, ',', '.') ?> → <?= number_format((float)$mDiff['totals_b']['energy_kcal'], 0, ',', '.') ?> kcal
            (Δ <?= ($mDiff['delta_kcal'] > 0 ? '+' : '') . number_format((float)$mDiff['delta_kcal'], 0, ',', '.') ?> kcal)
          </div>
        </div>
        <ul class="u-m-0 u-pl-5 u-text-compact u-color-neutral-gray-700">
          <?php foreach ($mDiff['items_added'] as $ad): ?>
            <li data-testid="item-diff-added" class="u-color-semantic-success-text"><strong>+ Incluído:</strong> <?= htmlspecialchars((string)$ad['food_name']) ?> — <?= htmlspecialchars((string)$ad['amount']) ?> <?= htmlspecialchars((string)$ad['measure_unit']) ?> (<?= number_format((float)$ad['energy_kcal'], 0, ',', '.') ?> kcal)</li>
          <?php endforeach; ?>
          <?php foreach ($mDiff['items_removed'] as $rm): ?>
            <li data-testid="item-diff-removed" class="u-color-semantic-error-strong"><strong>− Retirado:</strong> <?= htmlspecialchars((string)$rm['food_name']) ?> — <?= htmlspecialchars((string)$rm['amount']) ?> <?= htmlspecialchars((string)$rm['measure_unit']) ?> (<?= number_format((float)$rm['energy_kcal'], 0, ',', '.') ?> kcal)</li>
          <?php endforeach; ?>
          <?php foreach ($mDiff['items_modified'] as $md): ?>
            <li data-testid="item-diff-modified" class="u-color-semantic-warning-strong"><strong>⇄ Porção ajustada:</strong> <?= htmlspecialchars((string)$md['food_name']) ?> de <?= htmlspecialchars((string)$md['amount_from']) ?> <?= htmlspecialchars((string)$md['unit_from']) ?> para <?= htmlspecialchars((string)$md['amount_to']) ?> <?= htmlspecialchars((string)$md['unit_to']) ?> (Δ <?= ($md['delta_kcal'] > 0 ? '+' : '') . number_format((float)$md['delta_kcal'], 0, ',', '.') ?> kcal)</li>
          <?php endforeach; ?>
          <?php foreach ($mDiff['items_unchanged'] as $un): ?>
            <li class="u-color-neutral-gray-500">Mantido: <?= htmlspecialchars((string)$un['food_name']) ?> (<?= htmlspecialchars((string)$un['amount']) ?> <?= htmlspecialchars((string)$un['measure_unit']) ?>)</li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Resumo Seguro para Compartilhar com o Paciente -->
  <div class="card u-p-5 u-bg-neutral-gray-50 u-border u-bc-neutral-gray-300" data-testid="patient-safe-diff-summary">
    <h2 class="u-text-body u-font-bold u-color-botanical-forest-900 u-mt-0 u-mb-1 u-mx-0">Resumo de Evolução para o Paciente (Sem anotações clínicas internas)</h2>
    <p class="u-text-compact u-color-neutral-gray-500 u-mt-0 u-mb-2 u-mx-0">Texto pronto para entrega no retorno ou colagem na orientação do paciente:</p>
    <pre class="u-m-0 u-p-3 u-bg-neutral-white u-border u-radius-input u-text-compact u-pre-wrap u-color-neutral-ink-900"><?= htmlspecialchars((string)$diff['patient_safe_summary']['text']) ?></pre>
  </div>
<?php endif; ?>
