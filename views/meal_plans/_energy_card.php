<?php
/**
 * Cartão "Energia e metas" do editor de plano (NI2-514, fase 1).
 * Variáveis: $planId, $plan, $isDraft, $canEditItems, $csrfToken, $energyTarget (array|null), $energySuggest, $energyCompare (array|null), $energyOld (array)
 */
use ClyoNutri\Domain\Nutrition\EnergyCalculator as EC;

$t = $energyTarget ?? null;
$old = $energyOld ?? [];
$sug = $energySuggest ?? ['sex' => null, 'age_years' => null, 'weight_kg' => null, 'height_cm' => null, 'ffm_kg' => null, 'source_text' => ''];
$val = static function (string $key, $fallback = '') use ($old, $t) {
    if (array_key_exists($key, $old)) {
        return (string)$old[$key];
    }
    return $fallback === null ? '' : (string)$fallback;
};
$num = static fn($v, int $d = 1) => number_format((float)$v, $d, ',', '.');
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtInput = static fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');

$formulaSel = $val('formula', $t['formula'] ?? 'mifflin_st_jeor');
$sexSel = $val('sex', $t['sex'] ?? ($sug['sex'] ?? ''));
$ageV = $val('age_years', $fmtInput($t['age_years'] ?? $sug['age_years']));
$weightV = $val('weight_kg', $fmtInput($t['weight_kg'] ?? $sug['weight_kg']));
$heightV = $val('height_cm', $fmtInput($t['height_cm'] ?? $sug['height_cm']));
$ffmV = $val('ffm_kg', $fmtInput($t['ffm_kg'] ?? $sug['ffm_kg']));
$factorV = $val('activity_factor', $t ? $fmtInput($t['activity_factor']) : '1.55');
$goalSel = $val('goal_type', $t['goal_type'] ?? 'maintenance');
$goalNoteV = $val('goal_note', $t['goal_note'] ?? '');
$adjModeSel = $val('adjust_mode', $t['adjust_mode'] ?? 'kcal');
$adjV = $val('adjust_value', $t ? $fmtInput($t['adjust_value']) : '0');
$macroModeSel = $val('macro_mode', $t['macro_mode'] ?? 'percent');
$pV = $val('protein', $t ? ($macroModeSel === 'g_per_kg' ? $fmtInput($t['protein_g_kg']) : $fmtInput($t['protein_pct'])) : '20');
$cV = $val('carbohydrate', $t ? ($macroModeSel === 'g_per_kg' ? $fmtInput($t['carbohydrate_g_kg']) : $fmtInput($t['carbohydrate_pct'])) : '50');
$lV = $val('lipid', $t ? ($macroModeSel === 'g_per_kg' ? $fmtInput($t['lipid_g_kg']) : $fmtInput($t['lipid_pct'])) : '30');
$statusLabel = ['ok' => 'dentro da tolerância', 'acima' => 'acima da meta', 'abaixo' => 'abaixo da meta'];
$statusColor = ['ok' => 'ds-tone-ok', 'acima' => 'ds-tone-copper', 'abaixo' => 'ds-tone-copper'];
$field = 'form-control';
$label = 'form-label';
?>
<section id="energia" class="card u-py-4 u-px-5 u-mb-6 u-accent u-accent-botanical-copper-600" data-testid="energy-card" aria-labelledby="energy-title">
  <div class="u-flex u-justify-between u-items-baseline u-wrap u-gap-2 u-mb-2">
    <h2 id="energy-title" class="u-text-card-title u-font-bold u-color-botanical-forest-900 u-m-0">Energia e metas do plano</h2>
    <span class="u-text-compact u-color-neutral-gray-500">TMB, GET e VET calculados pela fórmula que você escolher</span>
  </div>

  <?php if ($t): ?>
    <div data-testid="energy-summary" class="u-grid u-cols-auto-sm u-gap-3 u-mb-3">
      <div><span class="u-text-caption u-color-neutral-gray-500 u-font-semibold u-block">TMB</span><strong class="u-text-h3"><?= $num($t['tmb_kcal'], 0) ?> kcal</strong></div>
      <div><span class="u-text-caption u-color-neutral-gray-500 u-font-semibold u-block">GET (TMB x <?= $num($t['activity_factor'], 2) ?>)</span><strong class="u-text-h3"><?= $num($t['get_kcal'], 0) ?> kcal</strong></div>
      <div><span class="u-text-caption u-color-neutral-gray-500 u-font-semibold u-block">VET (<?= $e($t['goal_label']) ?>)</span><strong class="u-text-h3" data-testid="energy-vet"><?= $num($t['vet_kcal'], 0) ?> kcal</strong></div>
    </div>

    <table id="energy-compare-table" data-live-swap data-testid="energy-compare" class="u-w-full u-collapse u-text-compact u-mb-3">
      <caption class="u-text-left u-color-neutral-gray-600 u-text-compact u-pb-1">Meta calculada e o que o plano tem hoje (tolerância de <?= $num(EC::TOLERANCE_PCT, 0) ?>%, definida pelo sistema)</caption>
      <thead>
        <tr class="u-text-left u-color-neutral-gray-500 u-text-caption u-border-b">
          <th scope="col" class="u-p-1">Item</th><th scope="col" class="u-p-1">Meta</th><th scope="col" class="u-p-1">g/kg</th><th scope="col" class="u-p-1">Plano</th><th scope="col" class="u-p-1">Situação</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $rows = [
            'energy' => ['Energia (kcal)', $t['vet_kcal'], null, 0],
            'protein' => ['Proteína (g)', $t['protein_g'], $t['protein_g_kg'], 1],
            'carbohydrate' => ['Carboidrato (g)', $t['carbohydrate_g'], $t['carbohydrate_g_kg'], 1],
            'lipid' => ['Lipídio (g)', $t['lipid_g'], $t['lipid_g_kg'], 1],
        ];
        foreach ($rows as $key => [$name, $target, $perKg, $dec]):
            $cmp = $energyCompare[$key] ?? null;
        ?>
        <tr class="u-border-b u-bc-neutral-gray-100">
          <th scope="row" class="u-p-1 u-text-left u-font-semibold"><?= $e($name) ?></th>
          <td class="tabular-nums u-p-1"><?= $num($target, $dec) ?><?= $key !== 'energy' ? ' <span class="u-color-neutral-gray-500">(' . $num($t[$key . '_pct'], 0) . '%)</span>' : '' ?></td>
          <td class="tabular-nums u-p-1"><?= $perKg !== null ? $num($perKg, 2) : '' ?></td>
          <td class="tabular-nums u-p-1"><?= $cmp ? $num($cmp['actual'], $dec) : '' ?></td>
          <td class="u-py-1 u-px-1 <?= $cmp ? $statusColor[$cmp['status']] : 'ds-tone-muted' ?>"><?= $cmp ? $e($statusLabel[$cmp['status']]) . ($cmp['diff_pct'] !== null && $cmp['status'] !== 'ok' ? ' (' . ($cmp['diff'] > 0 ? '+' : '') . $num($cmp['diff_pct'], 1) . '%)' : '') : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php foreach ($t['warnings'] as $w): ?>
      <div class="op-message op-message--info u-bg-botanical-copper-100 u-color-botanical-copper-700 u-py-2 u-px-3 u-radius-input u-mb-2 u-text-compact" role="status" data-testid="energy-warning"><?= $e($w) ?></div>
    <?php endforeach; ?>

    <details class="u-mb-2 u-text-compact u-color-neutral-gray-700" data-testid="energy-trace">
      <summary class="u-pointer u-font-semibold">Como este cálculo foi feito (rastro gravado no plano)</summary>
      <ul class="u-mt-2 u-mb-0 u-mx-0 u-pl-5 u-leading-body">
        <li>Fórmula da TMB: <?= $e($t['formula_label']) ?>. Referência: <?= $e($t['formula_reference']) ?>.</li>
        <?php if ($t['equation']): ?><li>Conta: <?= $e($t['equation']) ?> = <?= $num($t['tmb_kcal'], 1) ?> kcal.</li><?php endif; ?>
        <li>Fator de atividade: <?= $e($t['activity_label']) ?>. Faixas de referência: <?= $e($t['activity_source']) ?>.</li>
        <li>Dados de entrada: <?= $e($t['inputs_source']) ?>.</li>
        <li>Objetivo: <?= $e($t['goal_label']) ?><?= $t['goal_note'] ? ' (' . $e($t['goal_note']) . ')' : '' ?>. Ajuste aplicado ao GET: <?= $num($t['adjust_value'], 1) ?><?= $t['adjust_mode'] === 'percent' ? '%' : ' kcal' ?>.</li>
        <li>Versão do cálculo: <?= $e($t['formula_version']) ?>. Última gravação: <?= $e($t['calculated_at']) ?>.</li>
      </ul>
    </details>
  <?php else: ?>
    <p class="u-mt-0 u-mb-3 u-mx-0 u-color-neutral-gray-600 u-text-compact" data-testid="energy-empty">Este plano ainda não tem TMB, GET e VET. Escolha a fórmula, o fator de atividade e o objetivo para calcular e gravar a meta junto do plano.</p>
  <?php endif; ?>

  <?php if ($isDraft && $canEditItems): ?>
  <details <?= $t ? '' : 'open' ?> data-testid="energy-form-wrap" <?= !empty($old) ? 'open' : '' ?>>
    <summary class="u-pointer u-font-semibold u-color-botanical-copper-600 u-mb-2"><?= $t ? 'Recalcular ou ajustar a meta' : 'Calcular TMB, GET e VET' ?></summary>
    <form method="POST" action="/planos/<?= (int)$planId ?>/energia" data-energy-form class="u-mt-2">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <?php if ($sug['source_text'] !== ''): ?>
        <p class="u-text-compact u-color-neutral-gray-600 u-mt-0 u-mb-2 u-mx-0">Campos pré-preenchidos de: <?= $e($sug['source_text']) ?>. Você pode alterar qualquer valor; o rastro registra se foi alterado.</p>
      <?php endif; ?>
      <div class="u-grid u-cols-auto-md u-gap-3 u-mb-3">
        <div>
          <label for="en-formula" class="form-label">Fórmula da TMB *</label>
          <select id="en-formula" name="formula" required data-energy-formula class="form-control">
            <?php foreach (EC::FORMULAS as $code => $f): ?>
              <option value="<?= $e($code) ?>" data-ffm="<?= $f['needs_ffm'] ? '1' : '0' ?>" <?= $formulaSel === $code ? 'selected' : '' ?>><?= $e($f['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="en-weight" class="form-label">Peso (kg) *</label>
          <input id="en-weight" name="weight_kg" inputmode="decimal" required value="<?= $e($weightV) ?>" class="form-control">
        </div>
        <div data-energy-eq>
          <label for="en-height" class="form-label">Altura (cm) *</label>
          <input id="en-height" name="height_cm" inputmode="decimal" value="<?= $e($heightV) ?>" class="form-control">
        </div>
        <div data-energy-eq>
          <label for="en-age" class="form-label">Idade (anos) *</label>
          <input id="en-age" name="age_years" inputmode="numeric" value="<?= $e($ageV) ?>" class="form-control">
        </div>
        <div data-energy-eq>
          <label for="en-sex" class="form-label">Sexo usado na equação *</label>
          <select id="en-sex" name="sex" class="form-control">
            <option value="">Selecione</option>
            <option value="male" <?= $sexSel === 'male' ? 'selected' : '' ?>>Masculino</option>
            <option value="female" <?= $sexSel === 'female' ? 'selected' : '' ?>>Feminino</option>
          </select>
        </div>
        <div data-energy-ffm hidden>
          <label for="en-ffm" class="form-label">Massa livre de gordura (kg) *</label>
          <input id="en-ffm" name="ffm_kg" inputmode="decimal" value="<?= $e($ffmV) ?>" class="form-control">
        </div>
      </div>

      <div class="u-grid u-cols-auto-md u-gap-3 u-mb-3">
        <div>
          <label for="en-preset" class="form-label">Nível de atividade</label>
          <select id="en-preset" data-energy-preset class="form-control">
            <option value="">Outro valor</option>
            <?php foreach (EC::ACTIVITY_PRESETS as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= number_format((float)$factorV, 2, '.', '') === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="en-factor" class="form-label">Fator de atividade *</label>
          <input id="en-factor" name="activity_factor" inputmode="decimal" required value="<?= $e($factorV) ?>" data-energy-factor class="form-control">
        </div>
        <div>
          <label for="en-goal" class="form-label">Objetivo *</label>
          <select id="en-goal" name="goal_type" required class="form-control">
            <?php foreach (EC::GOALS as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= $goalSel === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="en-adjust" class="form-label">Ajuste do GET (negativo reduz)</label>
          <div class="u-flex u-gap-1">
            <input id="en-adjust" name="adjust_value" inputmode="decimal" value="<?= $e($adjV) ?>" class="form-control">
            <select name="adjust_mode" aria-label="Unidade do ajuste" class="form-control u-w-auto">
              <option value="kcal" <?= $adjModeSel === 'kcal' ? 'selected' : '' ?>>kcal</option>
              <option value="percent" <?= $adjModeSel === 'percent' ? 'selected' : '' ?>>% do GET</option>
            </select>
          </div>
        </div>
      </div>
      <div class="u-mb-3">
        <label for="en-goalnote" class="form-label">Observação do objetivo (opcional)</label>
        <input id="en-goalnote" name="goal_note" maxlength="255" value="<?= $e($goalNoteV) ?>" class="form-control">
      </div>

      <fieldset class="u-border u-radius-input u-py-2 u-px-3 u-mt-0 u-mb-3 u-mx-0">
        <legend class="u-font-semibold u-text-compact u-py-0 u-px-1">Metas de macronutrientes</legend>
        <div class="u-grid u-cols-auto-sm u-gap-3">
          <div>
            <label for="en-mmode" class="form-label">Informar em</label>
            <select id="en-mmode" name="macro_mode" data-energy-macro-mode class="form-control">
              <?php foreach (EC::MACRO_MODES as $k => $lab): ?>
                <option value="<?= $e($k) ?>" <?= $macroModeSel === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label for="en-p" class="form-label">Proteína <span data-energy-unit>(%)</span></label><input id="en-p" name="protein" inputmode="decimal" required value="<?= $e($pV) ?>" class="form-control"></div>
          <div><label for="en-c" class="form-label">Carboidrato <span data-energy-unit>(%)</span></label><input id="en-c" name="carbohydrate" inputmode="decimal" required value="<?= $e($cV) ?>" class="form-control"></div>
          <div><label for="en-l" class="form-label">Lipídio <span data-energy-unit>(%)</span></label><input id="en-l" name="lipid" inputmode="decimal" required value="<?= $e($lV) ?>" class="form-control"></div>
        </div>
        <p class="u-text-compact u-color-neutral-gray-500 u-mt-2 u-mb-0 u-mx-0">Em percentual, a soma precisa ser 100. Em g/kg, o sistema compara a soma de energia dos macros com o VET e avisa se a diferença passar de <?= $num(EC::TOLERANCE_PCT, 0) ?>%.</p>
      </fieldset>

      <p class="u-text-compact u-color-neutral-gray-600 u-mt-0 u-mb-3 u-mx-0">As fórmulas, faixas de atividade e tolerâncias são referências de literatura implementadas pelo sistema. Elas não substituem a conferência do nutricionista responsável pelo plano.</p>
      <button type="submit" class="btn btn-primary" data-testid="energy-submit">Calcular e gravar a meta</button>
    </form>
  </details>
  <?php endif; ?>
</section>
<script src="/assets/js/plan-energy.js" defer></script>
