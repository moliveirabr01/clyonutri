<?php
/** Biblioteca de materiais. Entradas: $materials, $q, $cat, $archived, $target (?array paciente), $targetHas, $canManage, $old, $flash, $csrfToken. */
use ClyoNutri\Domain\Materials\MaterialService as M;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$back = '/materiais' . ($target ? '?para=' . (int)$target['id'] : '');
?>
<link rel="stylesheet" href="/assets/css/materials.css?v=v54-66">
<div class="mat">
  <div class="mat-head">
    <div>
      <h1>Materiais educativos</h1>
      <p>Suba PDFs e imagens uma vez e envie para os pacientes. Eles veem e baixam na área do paciente.</p>
    </div>
    <a class="btn btn-secondary" href="<?= $archived ? '/materiais' : '/materiais?arquivados=1' ?>"><?= $archived ? 'Ver biblioteca' : 'Ver arquivados' ?></a>
  </div>

  <?php if ($flash): ?><div class="mat-flash mat-flash--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="materials-flash"><?= $e($flash['text']) ?></div><?php endif; ?>

  <?php if ($target): ?>
    <div class="mat-target" data-testid="materials-target">Escolha o material para enviar para <strong><?= $e($target['name']) ?></strong>. <a href="/pacientes/<?= (int)$target['id'] ?>">Voltar ao paciente</a></div>
  <?php endif; ?>

  <?php if ($canManage && !$archived): ?>
  <details class="mat-card" <?= ($old || !$materials) ? 'open' : '' ?> data-testid="materials-upload">
    <summary style="cursor: pointer; font-weight: 700; min-height: 32px;">Adicionar material</summary>
    <form method="POST" action="/materiais" enctype="multipart/form-data" class="mat-upload" style="margin-top: 12px;">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label class="mat-wide">Arquivo <span class="mat-hint">PDF, PNG ou JPG, até 10 MB</span>
        <input type="file" name="file" accept="application/pdf,image/png,image/jpeg,.pdf,.png,.jpg,.jpeg" required></label>
      <label>Título <input name="title" required maxlength="160" value="<?= $e($old['title'] ?? '') ?>" placeholder="Ex.: Lista de compras saudável"></label>
      <label>Categoria
        <select name="category" required>
          <option value="">Escolha…</option>
          <?php foreach (M::CATEGORIES as $k => $l): ?><option value="<?= $e($k) ?>"<?= ($old['category'] ?? '') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?>
        </select></label>
      <label class="mat-wide">Descrição para o paciente (opcional) <textarea name="description" rows="2" maxlength="2000"><?= $e($old['description'] ?? '') ?></textarea></label>
      <div><button class="btn btn-primary" type="submit">Adicionar à biblioteca</button></div>
    </form>
    <p class="mat-hint" style="margin: 12px 0 0;">Use só materiais seus ou que você tem direito de compartilhar. Não inclua dados de pacientes.</p>
  </details>
  <?php endif; ?>

  <form method="GET" action="/materiais" class="mat-filters" role="search">
    <?php if ($archived): ?><input type="hidden" name="arquivados" value="1"><?php endif; ?>
    <?php if ($target): ?><input type="hidden" name="para" value="<?= (int)$target['id'] ?>"><?php endif; ?>
    <label class="visually-hidden" for="mat-q">Buscar</label><input id="mat-q" type="search" name="q" value="<?= $e($q) ?>" placeholder="Buscar por título ou descrição">
    <label class="visually-hidden" for="mat-cat">Categoria</label>
    <select id="mat-cat" name="categoria"><option value="">Todas as categorias</option><?php foreach (M::CATEGORIES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $cat === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-secondary" type="submit">Filtrar</button>
  </form>

  <?php if (!$materials): ?>
    <div class="mat-card mat-empty" data-testid="materials-empty"><?= $archived ? 'Nenhum material arquivado.' : ($q !== '' || $cat !== '' ? 'Nenhum material com esse filtro.' : 'A biblioteca ainda está vazia. Adicione o primeiro material.') ?></div>
  <?php else: ?>
  <div class="mat-grid" data-testid="materials-list">
    <?php foreach ($materials as $m): ?>
      <article class="mat-item" data-testid="material" data-id="<?= (int)$m['id'] ?>">
        <div class="mat-meta"><span class="mat-tag"><?= $e(M::CATEGORIES[$m['category']] ?? 'Outros') ?></span><span class="mat-type"><?= $e(strtoupper(M::MIME[$m['mime_type']] ?? '')) ?> · <?= $e(M::humanSize((int)$m['size_bytes'])) ?></span></div>
        <h2><a href="/materiais/<?= (int)$m['id'] ?>"><?= $e($m['title']) ?></a></h2>
        <?php if (!empty($m['description'])): ?><p style="margin: 0; font-size: .9rem; color: #475467; overflow-wrap: anywhere;"><?= $e(mb_strimwidth((string)$m['description'], 0, 160, '…', 'UTF-8')) ?></p><?php endif; ?>
        <p class="mat-meta" style="margin: 0;">Enviado para <?= (int)$m['shared_count'] ?> paciente<?= (int)$m['shared_count'] === 1 ? '' : 's' ?></p>
        <div class="mat-actions">
          <a class="btn btn-secondary btn-sm" href="/materiais/<?= (int)$m['id'] ?>/arquivo" target="_blank" rel="noopener">Ver</a>
          <?php if ($target && isset($targetHas[(int)$m['id']])): ?>
            <span class="mat-type" style="align-self: center;" data-testid="material-already-sent">Já enviado</span>
          <?php elseif ($target && $canManage && !$archived): ?>
            <form method="POST" action="/materiais/<?= (int)$m['id'] ?>/enviar">
              <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="patients[]" value="<?= (int)$target['id'] ?>"><input type="hidden" name="back" value="<?= $e($back) ?>">
              <button class="btn btn-primary btn-sm" type="submit" data-testid="material-quick-send" aria-label="Enviar <?= $e($m['title']) ?> para <?= $e($target['name']) ?>">Enviar</button>
            </form>
          <?php else: ?>
            <a class="btn btn-primary btn-sm" href="/materiais/<?= (int)$m['id'] ?>">Enviar para pacientes</a>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
