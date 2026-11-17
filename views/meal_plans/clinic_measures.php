<?php
/**
 * Medidas caseiras da clínica (NI2-514, fase 1).
 * Variáveis: $svc, $q, $foodId, $selected, $globalMeasures, $clinicOfFood, $foundFoods, $listQ, $allMeasures, $coverage,
 * $importReport, $old, $feedback, $error, $csrfToken
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
$pct = $coverage['foods'] > 0 ? round(100 * $coverage['with_any'] / $coverage['foods'], 1) : 0;
?>
<div data-testid="clinic-measures-page" class="u-maxw-page">
  <div class="u-mb-4">
    <a href="/alimentos" class="u-text-compact u-link">← Tabela de alimentos</a>
    <h1 class="u-text-h2 u-font-bold u-color-botanical-forest-900 u-my-1 u-mx-0">Medidas caseiras da clínica</h1>
    <?php $measureBase = $measureBase ?? ['pending' => 0, 'approved' => 0, 'last_at' => null]; ?>
    <p class="u-color-neutral-gray-600 u-m-0 u-text-body u-leading-body" data-testid="measures-base-status">
      <?php if ((int)$measureBase['approved'] > 0 && (int)$measureBase['pending'] === 0 && !empty($measureBase['last_at'])): ?>
        Base Clyo Nutri revisada em <?= $e(date('d/m/Y', strtotime((string)$measureBase['last_at']))) ?><?= !empty($measureBase['rt']) ? ' pelo nutricionista responsável técnico ' . $e($measureBase['rt']) : '' ?>. Você pode cadastrar abaixo as medidas próprias da sua clínica; elas valem só para ela e têm prioridade no plano.
      <?php elseif ((int)$measureBase['approved'] > 0): ?>
        A base Clyo Nutri está em revisão<?= !empty($measureBase['rt']) ? ' pelo nutricionista responsável técnico ' . $e($measureBase['rt']) : '' ?>: <?= $e(\ClyoNutri\Application\View\UserText::count((int)$measureBase['approved'], '1 medida já revisada', '{n} medidas já revisadas')) ?> e <?= $e(\ClyoNutri\Application\View\UserText::count((int)$measureBase['pending'], '1 estimada aguarda revisão', '{n} estimadas aguardam revisão')) ?>.
        Enquanto isso, <a href="#cm-add">cadastre as medidas que você usa</a> com a fonte (pesagem na clínica, rótulo, tabela publicada); elas valem só para a sua clínica.
      <?php else: ?>
        A base Clyo Nutri de medidas caseiras ainda está em revisão. Você já pode <a href="#cm-add">cadastrar as medidas que você usa</a> com a fonte (pesagem na clínica, rótulo, tabela publicada); elas valem só para a sua clínica.
      <?php endif; ?>
      Sem medida cadastrada, o plano usa a tabela padrão estimada e pede a sua confirmação.
    </p>
  </div>

  <?php if ($error): ?>
    <div class="op-message op-message--error u-mb-4" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>
  <?php if ($feedback): ?>
    <div class="op-message op-message--info u-mb-4" role="status"><?= $e($feedback) ?></div>
  <?php endif; ?>

  <section aria-label="Cobertura" data-testid="measures-coverage" class="u-bg-botanical-cream-50 u-border u-bc-botanical-sage-200 u-radius-card u-py-3 u-px-4 u-mb-5">
    <strong><?= (int)$coverage['with_any'] ?> de <?= (int)$coverage['foods'] ?> alimentos</strong> têm ao menos uma medida caseira (<?= $e(number_format($pct, 1, ',', '.')) ?>%).
    Da clínica: <?= (int)$coverage['clinic_measures'] ?> <?= (int)$coverage['clinic_measures'] === 1 ? 'medida' : 'medidas' ?> em <?= (int)$coverage['with_clinic'] ?> <?= (int)$coverage['with_clinic'] === 1 ? 'alimento' : 'alimentos' ?>.
    Da base global: <?= (int)$coverage['with_global'] ?> alimentos, com origem não documentada.
  </section>

  <section aria-labelledby="cm-add" class="u-mb-6">
    <h2 id="cm-add" class="u-text-card-title u-mt-0 u-mb-2 u-mx-0">Cadastrar uma medida</h2>
    <form method="GET" action="/medidas-caseiras" class="u-flex u-gap-2 u-wrap u-items-end u-mb-3">
      <div class="u-flex-1 u-basis-md">
        <label for="cm-q" class="u-block u-text-compact u-mb-1">Buscar alimento</label>
        <input id="cm-q" name="q" type="search" value="<?= $e($q) ?>" placeholder="ex.: aveia" data-testid="measure-food-search" class="u-w-full u-minh-touch u-py-0 u-px-3">
      </div>
      <button type="submit" class="btn btn-secondary u-minh-touch">Buscar</button>
    </form>

    <?php if ($q !== '' && $foundFoods === []): ?>
      <p class="u-color-neutral-gray-500">Nenhum alimento encontrado para «<?= $e($q) ?>».</p>
    <?php endif; ?>
    <?php if ($foundFoods !== []): ?>
      <ul data-testid="measure-food-results" class="u-list-none u-p-0 u-mt-0 u-mb-3 u-mx-0 u-grid u-gap-1">
        <?php foreach ($foundFoods as $f): ?>
          <li><a class="btn btn-secondary u-minh-touch u-inline-flex u-items-center u-py-0 u-px-3" href="/medidas-caseiras?food=<?= (int)$f['id'] ?>&amp;q=<?= $e(urlencode($q)) ?>"><?= $e($f['name']) ?><?= $f['taco_code'] ? ' (TACO ' . (int)$f['taco_code'] . ')' : '' ?><?= !empty($f['synonym_match']) ? ' · por sinônimo: ' . $e($f['synonym_match']) : '' ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($selected): ?>
      <div data-testid="measure-form-card" class="u-border u-bc-botanical-sage-200 u-radius-card u-p-4">
        <h3 class="u-mt-0 u-mb-2 u-mx-0 u-text-body"><?= $e($selected['name']) ?></h3>
        <?php if ($globalMeasures !== []): ?>
          <p class="u-mt-0 u-mb-1 u-mx-0 u-text-compact u-color-neutral-gray-600">Base global (origem não documentada):
            <?= $e(implode('; ', array_map(static fn($m) => $m['measure_name'] . ' = ' . rtrim(rtrim(number_format((float)$m['grams_equivalent'], 2, ',', '.'), '0'), ',') . ' g', $globalMeasures))) ?>.</p>
        <?php endif; ?>
        <?php if ($clinicOfFood !== []): ?>
          <p class="u-mt-0 u-mb-2 u-mx-0 u-text-compact u-color-neutral-gray-600">Da clínica:
            <?= $e(implode('; ', array_map(static fn($m) => $m['measure_name'] . ' = ' . rtrim(rtrim(number_format((float)$m['grams_equivalent'], 2, ',', '.'), '0'), ',') . ' g', $clinicOfFood))) ?>.</p>
        <?php endif; ?>
        <form method="POST" action="/medidas-caseiras" data-testid="measure-form" class="u-grid u-gap-2 u-cols-auto-md">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
          <input type="hidden" name="food_id" value="<?= (int)$selected['id'] ?>">
          <div>
            <label for="cm-name" class="u-block u-text-compact u-mb-1">Medida</label>
            <input id="cm-name" name="measure_name" required maxlength="100" value="<?= $e($old['measure_name'] ?? '') ?>" placeholder="colher de sopa cheia" class="u-w-full u-minh-touch u-py-0 u-px-3">
          </div>
          <div>
            <label for="cm-grams" class="u-block u-text-compact u-mb-1">Peso em gramas</label>
            <input id="cm-grams" name="grams" required inputmode="decimal" value="<?= $e($old['grams'] ?? '') ?>" placeholder="12,5" class="u-w-full u-minh-touch u-py-0 u-px-3">
          </div>
          <div>
            <label for="cm-source" class="u-block u-text-compact u-mb-1">Fonte do valor (obrigatória)</label>
            <input id="cm-source" name="source" required maxlength="255" value="<?= $e($old['source'] ?? '') ?>" placeholder="pesagem na clínica, balança de cozinha" class="u-w-full u-minh-touch u-py-0 u-px-3">
          </div>
          <div>
            <label for="cm-ref" class="u-block u-text-compact u-mb-1">Referência (opcional)</label>
            <input id="cm-ref" name="source_ref" maxlength="255" value="<?= $e($old['source_ref'] ?? '') ?>" placeholder="edição, página, lote" class="u-w-full u-minh-touch u-py-0 u-px-3">
          </div>
          <div class="u-self-end">
            <button type="submit" class="btn btn-primary u-minh-touch">Salvar medida</button>
          </div>
        </form>
        <p class="u-mt-2 u-mb-0 u-mx-0 u-text-compact u-color-neutral-gray-500">Se a medida já existir para este alimento, o valor e a fonte são atualizados e a alteração fica no registro de auditoria.</p>
      </div>
    <?php endif; ?>
  </section>

  <section id="importar" aria-labelledby="cm-import" class="u-mb-6">
    <h2 id="cm-import" class="u-text-card-title u-mt-0 u-mb-1 u-mx-0">Importar por CSV</h2>
    <p class="u-mt-0 u-mb-2 u-mx-0 u-color-neutral-gray-600 u-text-compact">Colunas: alimento_id ou taco_numero, medida, gramas, fonte (opcional) e referencia (opcional).
      Ou tudo é importado, ou nada: se uma linha tiver problema, o relatório mostra qual. <a href="/medidas-caseiras/modelo.csv">Baixar o modelo</a>.</p>
    <?php if (!empty($importReport)): ?>
      <ul data-testid="measures-import-errors" class="u-bg-semantic-warning-light u-border u-bc-semantic-warning-border u-radius-input u-pt-3 u-pr-4 u-pb-3 u-pl-8 u-mt-0 u-mb-3 u-mx-0 u-text-compact">
        <?php foreach ($importReport as $line): ?><li><?= $e($line) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <form method="POST" action="/medidas-caseiras/importar" enctype="multipart/form-data" data-testid="measures-import-form" class="u-grid u-gap-2">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <div>
        <label for="cm-dsource" class="u-block u-text-compact u-mb-1">Fonte dos valores do arquivo (obrigatória)</label>
        <input id="cm-dsource" name="default_source" required maxlength="255" placeholder="ex.: planilha de pesagens da clínica, mar/2026" class="u-w-full u-minh-touch u-py-0 u-px-3">
      </div>
      <div>
        <label for="cm-file" class="u-block u-text-compact u-mb-1">Arquivo CSV (até 1 MB, ponto e vírgula ou vírgula)</label>
        <input id="cm-file" type="file" name="csv_file" accept=".csv,text/csv">
      </div>
      <div>
        <label for="cm-text" class="u-block u-text-compact u-mb-1">Ou cole o conteúdo</label>
        <textarea id="cm-text" name="csv_text" rows="4" class="u-w-full u-py-2 u-px-3" placeholder="taco_numero;medida;gramas&#10;1;colher de sopa;12,5"></textarea>
      </div>
      <div><button type="submit" class="btn btn-primary u-minh-touch">Importar</button></div>
    </form>
  </section>

  <section aria-labelledby="cm-list" data-testid="measures-list">
    <h2 id="cm-list" class="u-text-card-title u-mt-0 u-mb-2 u-mx-0">Medidas cadastradas na clínica</h2>
    <?php if ($allMeasures === []): ?>
      <p class="u-color-neutral-gray-500">Nenhuma medida cadastrada ainda.</p>
    <?php else: ?>
      <div class="u-overflow-x-auto">
        <table class="u-w-full u-collapse u-text-compact">
          <thead><tr class="u-text-left u-border-b u-bc-botanical-sage-200"><th class="u-p-2">Alimento</th><th class="u-p-2">Medida</th><th class="u-p-2">Gramas</th><th class="u-p-2">Fonte</th><th class="u-p-2"></th></tr></thead>
          <tbody>
          <?php foreach ($allMeasures as $m): ?>
            <tr class="u-border-b u-bc-botanical-sage-100">
              <td class="u-p-2"><?= $e($m['food_name']) ?></td>
              <td class="u-p-2"><?= $e($m['measure_name']) ?></td>
              <td class="u-p-2"><?= $e($num($m['grams_equivalent'])) ?> g</td>
              <td class="u-p-2"><?= $e($m['source']) ?><?= $m['source_ref'] ? ' · ' . $e($m['source_ref']) : '' ?></td>
              <td class="u-p-2">
                <form method="POST" action="/medidas-caseiras/<?= (int)$m['id'] ?>/remover">
                  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
                  <button type="submit" class="btn btn-secondary u-minh-touch" aria-label="Remover <?= $e($m['measure_name']) ?> de <?= $e($m['food_name']) ?>">Remover</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
