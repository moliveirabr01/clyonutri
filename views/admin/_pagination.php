<?php
/**
 * Paginação das listas do backoffice (sem JavaScript).
 *
 * Variáveis esperadas:
 *   $pagination      array de ClyoNutri\Core\Http\Pagination::build()
 *   $paginationBase  caminho da lista, por exemplo "/admin/clinicas"
 *   $paginationQuery filtros ativos (nome => valor), repetidos nos links e no seletor
 *   $paginationNoun  [singular, plural] para o resumo, por exemplo ['clínica', 'clínicas']
 *   $paginationFragment (opcional) âncora para voltar à mesma seção, por exemplo "#assinantes"
 */

use ClyoNutri\Core\Http\Pagination;

$pg = $pagination ?? [];
$pgBase = (string)($paginationBase ?? '');
$pgFilters = is_array($paginationQuery ?? null) ? $paginationQuery : [];
$pgNoun = is_array($paginationNoun ?? null) ? $paginationNoun : ['registro', 'registros'];
$pgFrag = (isset($paginationFragment) && is_string($paginationFragment) && preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $paginationFragment) === 1) ? $paginationFragment : '';

$pgPage = (int)($pg['page'] ?? 1);
$pgPerPage = (int)($pg['per_page'] ?? Pagination::DEFAULT_PER_PAGE);
$pgDefault = (int)($pg['default_per_page'] ?? Pagination::DEFAULT_PER_PAGE);
$pgTotal = (int)($pg['total_records'] ?? 0);
$pgPages = (int)($pg['total_pages'] ?? 1);
$pgFrom = (int)($pg['from'] ?? 0);
$pgTo = (int)($pg['to'] ?? 0);

$pgLink = static fn(int $p): string => htmlspecialchars(
    Pagination::url($pgBase, $pgFilters, $p, $pgPerPage, $pgDefault) . $pgFrag,
    ENT_QUOTES,
    'UTF-8'
);
$pgId = 'pager-' . substr(md5($pgBase), 0, 6);

if (empty($GLOBALS['__clyo_pager_css'])):
    $GLOBALS['__clyo_pager_css'] = true;
?>
<style>
  .clyo-pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1.25rem; padding: 0.85rem 1.25rem; border-top: 1px solid var(--admin-gray-200, #e2e8f0); background: #fafbfc; font-size: 0.82rem; color: var(--admin-gray-600, #475569); }
  .clyo-pager__summary { margin: 0; }
  .clyo-pager__size { display: flex; align-items: center; gap: 0.5rem; margin: 0; }
  .clyo-pager__size select { padding: 0.3rem 0.5rem; border: 1px solid var(--border-input, #828B9B); border-radius: 6px; font-size: 0.8rem; background: #fff; color: inherit; }
  .clyo-pager__list { display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem; margin: 0; padding: 0; list-style: none; }
  .clyo-pager__item, .clyo-pager__btn { display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; min-height: 2rem; padding: 0 0.6rem; border: 1px solid var(--admin-gray-300, #cbd5e1); border-radius: 6px; background: #fff; color: var(--admin-navy-900, #0F3E17); font-size: 0.8rem; font-weight: 500; text-decoration: none; cursor: pointer; font-family: inherit; }
  a.clyo-pager__item:hover, .clyo-pager__btn:hover { background: var(--botanical-sage-100, #E5F0E5); border-color: var(--admin-navy-900, #0F3E17); }
  .clyo-pager__item:focus-visible, .clyo-pager__btn:focus-visible, .clyo-pager__size select:focus-visible { outline: 3px solid var(--admin-navy-900, #0F3E17); outline-offset: 2px; }
  .clyo-pager__item[aria-current="page"] { background: var(--admin-navy-900, #0F3E17); border-color: var(--admin-navy-900, #0F3E17); color: #fff; font-weight: 700; cursor: default; }
  .clyo-pager__item[aria-disabled="true"] { color: var(--admin-gray-600, #475569); background: var(--admin-gray-100, #f1f5f9); cursor: not-allowed; }
  .clyo-pager__gap { min-width: 1.5rem; text-align: center; color: var(--admin-gray-500, #64748B); }
</style>
<?php endif; ?>

<div class="clyo-pager">
  <p class="op-message op-message--info clyo-pager__summary" role="status">
    <?php if ($pgTotal === 0): ?>
      Nenhum resultado para mostrar
    <?php else: ?>
      Mostrando <strong><?= $pgFrom ?></strong> a <strong><?= $pgTo ?></strong> de <strong><?= $pgTotal ?></strong> <?= htmlspecialchars($pgTotal === 1 ? $pgNoun[0] : $pgNoun[1]) ?>
    <?php endif; ?>
  </p>

  <?php if ($pgTotal > 0): ?>
  <form class="clyo-pager__size" method="GET" action="<?= htmlspecialchars($pgBase . $pgFrag, ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach ($pgFilters as $fKey => $fVal): ?>
      <?php if ($fVal !== null && $fVal !== ''): ?>
        <input type="hidden" name="<?= htmlspecialchars((string)$fKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$fVal, ENT_QUOTES, 'UTF-8') ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <label for="<?= $pgId ?>-size">Por página</label>
    <select id="<?= $pgId ?>-size" name="per_page">
      <?php foreach (Pagination::PER_PAGE_OPTIONS as $opt): ?>
        <option value="<?= $opt ?>"<?= $opt === $pgPerPage ? ' selected' : '' ?>><?= $opt ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="clyo-pager__btn">Aplicar</button>
  </form>
  <?php endif; ?>

  <?php if ($pgPages > 1): ?>
  <nav aria-label="Paginação">
    <ul class="clyo-pager__list">
      <li>
        <?php if ($pgPage > 1): ?>
          <a class="clyo-pager__item" href="<?= $pgLink($pgPage - 1) ?>" rel="prev">Anterior</a>
        <?php else: ?>
          <span class="clyo-pager__item" aria-disabled="true">Anterior</span>
        <?php endif; ?>
      </li>
      <?php foreach (Pagination::items($pgPage, $pgPages) as $pgItem): ?>
        <li>
          <?php if ($pgItem === '…'): ?>
            <span class="clyo-pager__gap" aria-hidden="true">…</span>
          <?php elseif ($pgItem === $pgPage): ?>
            <a class="clyo-pager__item" href="<?= $pgLink($pgItem) ?>" aria-current="page" aria-label="Página <?= $pgItem ?>, página atual"><?= $pgItem ?></a>
          <?php else: ?>
            <a class="clyo-pager__item" href="<?= $pgLink($pgItem) ?>" aria-label="Ir para a página <?= $pgItem ?>"><?= $pgItem ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <li>
        <?php if ($pgPage < $pgPages): ?>
          <a class="clyo-pager__item" href="<?= $pgLink($pgPage + 1) ?>" rel="next">Próxima</a>
        <?php else: ?>
          <span class="clyo-pager__item" aria-disabled="true">Próxima</span>
        <?php endif; ?>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
</div>
