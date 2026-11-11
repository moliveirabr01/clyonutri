<?php
/**
 * v54.41: formulário "Adicionar alimento". Variáveis: $form, $error, $categories, $units, $csrfToken
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$v = static fn(string $k, string $d = '') => $form[$k] ?? $d;
$input = 'form-control u-w-full u-py-2 u-px-3 u-border u-bc-border-input u-radius-input u-minh-touch';
$num = static function (string $name, string $label, string $help, bool $required) use ($e, $v, $input): string {
    return '<div><label for="food-' . $name . '" class="form-label u-block u-text-compact u-mb-1">' . $label . ($required ? ' *' : '') . '</label>'
        . '<input id="food-' . $name . '" name="' . $name . '" inputmode="decimal" ' . ($required ? 'required ' : '') . 'value="' . $e($v($name)) . '" class="' . $input . '" placeholder="0" aria-describedby="food-' . $name . '-help">'
        . '<span id="food-' . $name . '-help" class="u-block u-text-caption u-color-neutral-gray-500 u-mt-1">' . $help . '</span></div>';
};
?>
<div class="u-mw-3xl">
  <p class="u-mb-2"><a href="/alimentos" class="u-color-botanical-forest-700">← Base de alimentos</a></p>
  <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-mb-1">Adicionar alimento</h1>
  <p class="u-color-neutral-gray-600 u-mb-4">O alimento fica só na sua clínica, aparece na busca da Base de Alimentos e pode ser usado nos planos alimentares. Informe os valores por <strong>100 g (ou 100 ml)</strong>, como na TACO; a porção serve para o cálculo por medida.</p>
  <?php if ($error): ?>
    <div class="op-message op-message--error u-mb-4" role="alert" data-testid="food-create-error"><?= $e($error) ?></div>
  <?php endif; ?>
  <form method="POST" action="/alimentos/novo" class="card u-p-6 u-flex u-flex-col u-gap-4" data-testid="food-create-form" data-busy-text="Salvando…">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <div>
      <label for="food-name" class="form-label u-block u-text-compact u-mb-1">Nome do alimento *</label>
      <input id="food-name" name="name" required minlength="2" maxlength="160" value="<?= $e($v('name')) ?>" class="<?= $input ?>" placeholder="Ex.: Pão de queijo caseiro da clínica">
    </div>
    <div class="u-grid u-grid-cols-2 u-gap-4">
      <div>
        <label for="food-category" class="form-label u-block u-text-compact u-mb-1">Grupo</label>
        <select id="food-category" name="category" class="<?= $input ?>">
          <option value="Personalizado">Personalizado</option>
          <?php foreach ($categories as $c): if ($c === 'Personalizado') continue; ?>
            <option value="<?= $e($c) ?>"<?= $v('category') === $c ? ' selected' : '' ?>><?= $e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="u-flex u-gap-2">
        <div class="u-flex-1">
          <label for="food-serving_size_g" class="form-label u-block u-text-compact u-mb-1">Porção (g ou ml)</label>
          <input id="food-serving_size_g" name="serving_size_g" inputmode="decimal" value="<?= $e($v('serving_size_g', '100')) ?>" class="<?= $input ?>">
        </div>
        <div class="u-flex-1">
          <label for="food-serving_unit" class="form-label u-block u-text-compact u-mb-1">Medida</label>
          <select id="food-serving_unit" name="serving_unit" class="<?= $input ?>">
            <?php foreach ($units as $u): ?>
              <option value="<?= $e($u) ?>"<?= $v('serving_unit', 'g') === $u ? ' selected' : '' ?>><?= $e($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <fieldset class="u-border-0 u-p-0 u-m-0">
      <legend class="u-font-semibold u-color-botanical-forest-900 u-mb-2">Composição em 100 g</legend>
      <div class="u-grid u-grid-cols-2 u-gap-4">
        <?= $num('energy_kcal', 'Energia (kcal)', 'Até 900 kcal.', true) ?>
        <?= $num('protein_g', 'Proteína (g)', 'Use vírgula ou ponto.', true) ?>
        <?= $num('carbohydrate_g', 'Carboidrato (g)', '', true) ?>
        <?= $num('lipid_g', 'Lipídio (g)', '', true) ?>
        <?= $num('fiber_g', 'Fibra (g)', 'Opcional.', false) ?>
      </div>
    </fieldset>
    <div>
      <label for="food-allergens" class="form-label u-block u-text-compact u-mb-1">Alérgenos (opcional)</label>
      <input id="food-allergens" name="allergens" maxlength="255" value="<?= $e($v('allergens')) ?>" class="<?= $input ?>" placeholder="Ex.: leite, glúten">
    </div>
    <div class="u-flex u-gap-2 u-wrap">
      <button type="submit" class="btn btn-primary u-minh-touch" data-testid="food-create-save">Salvar alimento</button>
      <a href="/alimentos" class="btn btn-secondary u-minh-touch u-inline-flex u-items-center">Cancelar</a>
    </div>
  </form>
</div>
