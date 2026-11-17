<?php /* NI2-886: revisão das medidas caseiras estimadas. Entradas: $ready, $filters, $page, $perPage, $stats, $rows, $groups, $total, $canManage, $flash, $importReport, $csrfToken, $rt (v54.77: RT nomeado). */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$g = static fn($v): string => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
$qs = static fn(array $over = []) => '?' . http_build_query(array_filter(['situacao' => $filters['status'], 'grupo' => $filters['group'], 'busca' => $filters['q'], 'ordem' => $filters['order']] + [], static fn($v) => $v !== '') + $over);
$back = $qs(['pagina' => $page]);
$statusLabel = ['pending' => 'A revisar', 'approved' => 'Aprovadas', 'removed' => 'Removidas', 'all' => 'Todas']; ?>
<div style="margin-bottom: 1.25rem;">
  <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--admin-navy-900);">Medidas caseiras</h2>
  <p style="font-size: 0.88rem; color: var(--admin-gray-500); max-width: 860px;">Medidas da base geral marcadas como estimativa. Um nutricionista confere o peso, informa a fonte e aprova, corrige ou remove. A medida aprovada deixa de aparecer como estimativa e mostra a fonte e quem revisou. Planos já publicados não mudam.</p>
</div>
<?php if ($flash): ?><div class="admin-alert" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="measure-flash" style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: 6px; background: <?= $flash['type'] === 'error' ? '#FEF3F2' : '#ECFDF3' ?>;"><?= $e($flash['text']) ?></div><?php endif; ?>
<?php if (!empty($importReport['rejected'])): ?>
  <div class="admin-card" style="padding: .75rem 1rem; margin-bottom: 1rem;" data-testid="measure-import-rejected">
    <strong>Linhas rejeitadas do CSV</strong>
    <ul style="margin: .5rem 0 0;"><?php foreach (array_slice($importReport['rejected'], 0, 100) as $r): ?><li>Linha <?= (int)$r['linha'] ?>: <?= $e($r['motivo']) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php /* v54.77: RT nomeado dos catálogos (medidas caseiras e exames). Decisão do Maicon: Raphael Lombardi. */
$rtReady = isset($rt) && $rt->isReady();
$by = static function (array $r) use ($rt, $e): string {
    $who = $e($r['reviewer_name']);
    $reg = trim((string)($r['registered_by_name'] ?? ''));
    if ($reg !== '' && isset($rt) && !$rt->isReviewerEmail($r['registered_by_email'] ?? null) && $reg !== (string)$r['reviewer_name']) {
        $who .= ' <span style="color: var(--admin-gray-500);">(registrado por ' . $e($reg) . ')</span>';
    }
    return $who;
}; ?>
<section class="admin-card" style="padding: .9rem 1rem; margin-bottom: 1rem;" aria-labelledby="rt-title" data-testid="catalog-rt">
  <h3 id="rt-title" style="font-size: 1rem; font-weight: 700; margin: 0 0 .35rem;">Responsável técnico (RT) dos catálogos</h3>
  <?php if ($rtReady): ?>
    <p style="margin: 0;" data-testid="catalog-rt-status">As revisões de medidas caseiras e do catálogo de exames ficam registradas em nome de <strong data-testid="catalog-rt-label"><?= $e($rt->label()) ?></strong>.<?= $rt->email === '' ? ' Quando outra pessoa registra a revisão por ele, o registro mostra também quem registrou.' : '' ?></p>
  <?php else: ?>
    <p style="margin: 0; color: #B54708;" role="status" data-testid="catalog-rt-status"><strong>Revisões bloqueadas.</strong> RT<?= isset($rt) && $rt->hasName() ? ': <strong data-testid="catalog-rt-label">' . $e($rt->name) . '</strong>' : '' ?>. Falta: <?= $e(implode(' e ', isset($rt) ? $rt->missing() : ['nome do nutricionista RT', 'CRN do RT'])) ?>.</p>
  <?php endif; ?>
  <?php if ($canManage): ?>
  <details style="margin-top: .5rem;"<?= $rtReady ? '' : ' open' ?>>
    <summary style="cursor: pointer; font-weight: 600;">Alterar dados do RT</summary>
    <form method="POST" action="/admin/medidas-caseiras/acao" style="display: flex; gap: .75rem; flex-wrap: wrap; align-items: end; margin-top: .5rem;" data-testid="catalog-rt-form">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="acao" value="rt"><input type="hidden" name="_back" value="<?= $e($back) ?>">
      <label>Nome do nutricionista RT<br><input name="rt_nome" required minlength="3" maxlength="120" value="<?= $e($rt->name ?? '') ?>" autocomplete="off"></label>
      <label>CRN<br><input name="rt_crn" maxlength="20" value="<?= $e($rt->crn ?? '') ?>" placeholder="CRN-3 12345" autocomplete="off" aria-describedby="rt-crn-help"></label>
      <label>E-mail da conta dele no console (opcional)<br><input type="email" name="rt_email" maxlength="190" value="<?= $e($rt->email ?? '') ?>" autocomplete="off"></label>
      <button class="btn btn--secondary btn--sm" type="submit">Salvar RT</button>
      <span id="rt-crn-help" style="flex: 1 1 100%; font-size: .8rem; color: var(--admin-gray-500);">Use o CRN do registro no conselho regional (região e número). Sem CRN, nenhuma revisão é gravada.</span>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php if (!$ready): ?>
  <div class="admin-card" style="padding: 1rem;">A migração 99 (NI2-886) ainda não foi aplicada neste banco.</div>
<?php else: ?>
<div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 1rem;" data-testid="measure-stats">
  <div class="admin-card" style="padding: .9rem 1rem;"><div style="font-size: .8rem; color: var(--admin-gray-500);">A revisar</div><div style="font-size: 1.6rem; font-weight: 700;" data-testid="measure-pending"><?= (int)$stats['pending'] ?></div></div>
  <div class="admin-card" style="padding: .9rem 1rem;"><div style="font-size: .8rem; color: var(--admin-gray-500);">Aprovadas</div><div style="font-size: 1.6rem; font-weight: 700;" data-testid="measure-approved"><?= (int)$stats['approved'] ?></div></div>
  <div class="admin-card" style="padding: .9rem 1rem;"><div style="font-size: .8rem; color: var(--admin-gray-500);">Removidas</div><div style="font-size: 1.6rem; font-weight: 700;"><?= (int)$stats['removed'] ?></div></div>
</div>
<details class="admin-card" style="padding: .75rem 1rem; margin-bottom: 1rem;">
  <summary style="cursor: pointer; font-weight: 600;">A revisar por grupo</summary>
  <table class="admin-table" style="margin-top: .5rem;"><thead><tr><th>Grupo</th><th>A revisar</th><th>Já usadas em planos</th></tr></thead><tbody>
  <?php foreach ($stats['by_group'] as $bg): ?><tr><td><?= $e($bg['grupo']) ?></td><td><?= (int)$bg['pendentes'] ?></td><td><?= (int)$bg['usadas'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
</details>
<form method="GET" action="/admin/medidas-caseiras" class="admin-card" style="padding: .75rem 1rem; margin-bottom: 1rem; display: flex; gap: .75rem; flex-wrap: wrap; align-items: end;" data-testid="measure-filter">
  <label>Situação<br><select name="situacao"><?php foreach ($statusLabel as $k => $l): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <label>Grupo<br><select name="grupo"><option value="">Todos</option><?php foreach ($groups as $gr): ?><option value="<?= $e($gr) ?>" <?= $filters['group'] === $gr ? 'selected' : '' ?>><?= $e($gr) ?></option><?php endforeach; ?></select></label>
  <label>Buscar<br><input name="busca" value="<?= $e($filters['q']) ?>" placeholder="alimento ou medida" maxlength="100"></label>
  <label>Ordem<br><select name="ordem"><option value="uso">Mais usadas primeiro</option><option value="nome" <?= $filters['order'] === 'nome' ? 'selected' : '' ?>>Nome do alimento</option></select></label>
  <button class="btn btn--secondary btn--sm" type="submit">Filtrar</button>
  <a class="btn btn--secondary btn--sm" href="/admin/medidas-caseiras/exportar<?= $e($qs()) ?>" data-testid="measure-export">Exportar CSV</a>
  <?php if ($canManage): ?><a class="btn btn--secondary btn--sm" href="/admin/medidas-caseiras/sql" data-testid="measure-sql">SQL das revisões (produção)</a><?php endif; ?>
</form>
<p style="font-size: .85rem; color: var(--admin-gray-500);" data-testid="measure-count"><?= $e(\ClyoNutri\Application\View\UserText::count((int)$total, '{n} medida nesta lista', '{n} medidas nesta lista', 'Nenhuma medida nesta lista')) ?><?= $total > $perPage ? ' · página ' . (int)$page . ' de ' . (int)ceil($total / $perPage) : '' ?>.</p>
<?php if ($canManage && $rtReady && $filters['status'] === 'pending' && $rows): ?>
<form id="measure-batch" method="POST" action="/admin/medidas-caseiras/acao" class="admin-card" style="padding: .75rem 1rem; margin-bottom: .75rem; display: flex; gap: .75rem; flex-wrap: wrap; align-items: end;" data-testid="measure-batch">
  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="acao" value="lote"><input type="hidden" name="_back" value="<?= $e($back) ?>">
  <label style="flex: 1 1 320px;">Fonte para as selecionadas (obrigatória)<br><input name="fonte_lote" required minlength="3" maxlength="250" style="width: 100%;" placeholder="Ex.: Pinheiro ABV et al., Tabela para avaliação de consumo alimentar em medidas caseiras, 5ª ed."></label>
  <button class="btn btn--primary btn--sm" type="submit">Aprovar selecionadas</button>
</form>
<?php endif; ?>
<div class="admin-card"><div class="admin-table-wrapper">
<table class="admin-table" data-testid="measure-table">
  <thead><tr><?php if ($canManage && $rtReady && $filters['status'] === 'pending'): ?><th><span class="sr-only">Selecionar</span></th><?php endif; ?><th>Alimento</th><th>Medida</th><th>Gramas</th><th>Usos</th><th>Fonte / revisão</th><?php if ($canManage && $rtReady && $filters['status'] === 'pending'): ?><th>Ações</th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7">Nenhuma medida nesta lista.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $id = (int)$r['id']; ?>
    <tr data-measure-id="<?= $id ?>">
      <?php if ($canManage && $rtReady && $filters['status'] === 'pending'): ?><td><input type="checkbox" name="ids[]" value="<?= $id ?>" form="measure-batch" aria-label="Selecionar <?= $e($r['alimento'] . ' — ' . $r['measure_name']) ?>"></td><?php endif; ?>
      <td><strong><?= $e($r['alimento']) ?></strong><br><span style="font-size: .8rem; color: var(--admin-gray-500);"><?= $e($r['grupo']) ?></span></td>
      <td><?= $e($r['measure_name']) ?></td>
      <td><?= $g($r['grams_equivalent']) ?> g<?= $r['grams_before_review'] !== null && abs((float)$r['grams_before_review'] - (float)$r['grams_equivalent']) > 0.001 ? '<br><span style="font-size: .8rem; color: var(--admin-gray-500);">antes: ' . $g($r['grams_before_review']) . ' g</span>' : '' ?></td>
      <td><?= (int)$r['usos'] ?></td>
      <td style="font-size: .85rem;">
        <?php if ($r['review_status'] === 'approved'): ?>Aprovada · fonte: <?= $e($r['source']) ?><br>por <?= $by($r) ?> em <?= $e(date('d/m/Y H:i', strtotime((string)$r['reviewed_at']))) ?>
        <?php elseif ($r['review_status'] === 'removed'): ?>Removida por <?= $by($r) ?> em <?= $e(date('d/m/Y H:i', strtotime((string)$r['reviewed_at']))) ?>
        <?php else: ?><span style="color: #B54708;">Estimativa (revisar)</span><?php endif; ?>
      </td>
      <?php if ($canManage && $rtReady && $filters['status'] === 'pending'): ?>
      <td style="min-width: 300px;">
        <form method="POST" action="/admin/medidas-caseiras/acao" style="display: grid; gap: .35rem; grid-template-columns: 1fr 90px;">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="_back" value="<?= $e($back) ?>">
          <label class="sr-only" for="fonte-<?= $id ?>">Fonte</label>
          <input id="fonte-<?= $id ?>" name="fonte" placeholder="Fonte do peso" maxlength="250" style="grid-column: 1 / -1;">
          <label class="sr-only" for="gramas-<?= $id ?>">Gramas corrigidos</label>
          <input id="gramas-<?= $id ?>" name="gramas" inputmode="decimal" placeholder="g corrigidos">
          <span></span>
          <div style="grid-column: 1 / -1; display: flex; gap: .35rem; flex-wrap: wrap;">
            <button class="btn btn--primary btn--sm" name="acao" value="aprovar" type="submit">Aprovar</button>
            <button class="btn btn--secondary btn--sm" name="acao" value="corrigir" type="submit">Corrigir e aprovar</button>
          </div>
        </form>
        <form method="POST" action="/admin/medidas-caseiras/acao" style="display: flex; gap: .35rem; margin-top: .35rem;">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="_back" value="<?= $e($back) ?>"><input type="hidden" name="acao" value="remover">
          <label class="sr-only" for="motivo-<?= $id ?>">Motivo da remoção</label>
          <input id="motivo-<?= $id ?>" name="motivo" placeholder="Motivo para remover" maxlength="250" style="flex: 1;">
          <button class="btn btn--secondary btn--sm" type="submit">Remover</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php if ($total > $perPage): ?><nav aria-label="Páginas" style="margin-top: .75rem; display: flex; gap: .5rem;">
  <?php if ($page > 1): ?><a class="btn btn--secondary btn--sm" href="<?= $e($qs(['pagina' => $page - 1])) ?>">Anterior</a><?php endif; ?>
  <?php if ($page * $perPage < $total): ?><a class="btn btn--secondary btn--sm" href="<?= $e($qs(['pagina' => $page + 1])) ?>">Próxima</a><?php endif; ?>
</nav><?php endif; ?>
<?php if ($canManage && $rtReady): ?>
<form method="POST" action="/admin/medidas-caseiras/acao" enctype="multipart/form-data" class="admin-card" style="padding: .75rem 1rem; margin-top: 1.25rem; display: flex; gap: .75rem; flex-wrap: wrap; align-items: end;" data-testid="measure-import">
  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="acao" value="importar"><input type="hidden" name="_back" value="<?= $e($back) ?>">
  <div style="flex: 1 1 100%;"><strong>Importar CSV revisado</strong><br><span style="font-size: .85rem; color: var(--admin-gray-500);">Use o CSV exportado. Preencha "acao" (aprovar, corrigir ou remover), "fonte_revisao" e, para corrigir, "gramas_revisados". Linha inválida é rejeitada sozinha.</span></div>
  <label>Arquivo<br><input type="file" name="csv" accept=".csv,text/csv" required></label>
  <label style="display: flex; gap: .4rem; align-items: center;"><input type="checkbox" name="simular" value="1" checked> Só simular (não grava)</label>
  <button class="btn btn--primary btn--sm" type="submit">Importar</button>
</form>
<?php endif; ?>
<?php endif; ?>
