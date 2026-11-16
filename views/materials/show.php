<?php
/** Detalhe do material. Entradas: $m, $shares, $patients, $canManage, $flash, $csrfToken. */
use ClyoNutri\Domain\Materials\MaterialService as M;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$id = (int)$m['id'];
$active = array_filter($shares, static fn($s) => $s['revoked_at'] === null);
$has = array_flip(array_map(static fn($s) => (int)$s['patient_id'], $active));
$fmt = static fn($d) => $d ? date('d/m/Y H:i', strtotime((string)$d)) : '';
?>
<link rel="stylesheet" href="/assets/css/materials.css?v=v54-66">
<div class="mat">
  <p style="margin: 0;"><a href="/materiais">← Materiais educativos</a></p>
  <?php if ($flash): ?><div class="mat-flash mat-flash--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="materials-flash"><?= $e($flash['text']) ?></div><?php endif; ?>

  <div class="mat-head">
    <div>
      <div class="mat-meta"><span class="mat-tag"><?= $e(M::CATEGORIES[$m['category']] ?? 'Outros') ?></span><span class="mat-type"><?= $e(strtoupper(M::MIME[$m['mime_type']] ?? '')) ?> · <?= $e(M::humanSize((int)$m['size_bytes'])) ?></span><?= $m['status'] === 'archived' ? '<span class="mat-type">Arquivado</span>' : '' ?></div>
      <h1 data-testid="material-title" style="overflow-wrap: anywhere;"><?= $e($m['title']) ?></h1>
      <?php if (!empty($m['description'])): ?><p style="white-space: pre-line;"><?= $e($m['description']) ?></p><?php endif; ?>
    </div>
    <div class="mat-actions">
      <a class="btn btn-secondary" href="/materiais/<?= $id ?>/arquivo?baixar=1">Baixar</a>
      <?php if ($canManage): ?>
        <form method="POST" action="/materiais/<?= $id ?>/<?= $m['status'] === 'archived' ? 'reativar' : 'arquivar' ?>"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><button class="btn btn-secondary" type="submit" data-testid="material-archive"><?= $m['status'] === 'archived' ? 'Reativar' : 'Arquivar' ?></button></form>
      <?php endif; ?>
    </div>
  </div>

  <div style="display: grid; gap: 24px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: start;">
    <section class="mat-card" aria-labelledby="mat-prev">
      <h2 id="mat-prev" style="margin-top: 0; font-size: 1.1rem;">Prévia</h2>
      <?php if ($m['mime_type'] === 'application/pdf'): ?>
        <iframe class="mat-preview" src="/materiais/<?= $id ?>/arquivo" title="Prévia de <?= $e($m['title']) ?>"></iframe>
      <?php else: ?>
        <img class="mat-preview-img" src="/materiais/<?= $id ?>/arquivo" alt="<?= $e($m['title']) ?>">
      <?php endif; ?>
    </section>

    <?php if ($canManage && $m['status'] === 'active'): ?>
    <section class="mat-card" aria-labelledby="mat-send" data-testid="material-share">
      <h2 id="mat-send" style="margin-top: 0; font-size: 1.1rem;">Enviar para pacientes</h2>
      <form method="POST" action="/materiais/<?= $id ?>/enviar" class="mat-form" style="display: grid; gap: 12px;">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
        <label for="mat-find">Buscar paciente <input id="mat-find" type="search" data-mat-filter placeholder="Digite parte do nome" autocomplete="off"></label>
        <div class="mat-picker" role="group" aria-label="Pacientes" data-mat-list>
          <?php if (!$patients): ?><p>Nenhum paciente ativo.</p><?php endif; ?>
          <?php foreach ($patients as $p): $got = isset($has[(int)$p['id']]); ?>
            <label data-name="<?= $e(mb_strtolower((string)$p['name'], 'UTF-8')) ?>"><input type="checkbox" name="patients[]" value="<?= (int)$p['id'] ?>"<?= $got ? ' disabled' : '' ?>> <?= $e($p['name']) ?><?= $got ? ' <span class="mat-hint">(já recebeu)</span>' : '' ?></label>
          <?php endforeach; ?>
        </div>
        <label>Mensagem para o paciente (opcional) <textarea name="note" rows="2" maxlength="500" placeholder="Ex.: Leia antes da próxima consulta."></textarea></label>
        <div><button class="btn btn-primary" type="submit" data-testid="material-send">Enviar</button></div>
      </form>
    </section>
    <?php endif; ?>
  </div>

  <section class="mat-card" aria-labelledby="mat-hist" data-testid="material-shares">
    <h2 id="mat-hist" style="margin-top: 0; font-size: 1.1rem;">Quem recebeu (<?= count($active) ?>)</h2>
    <?php if (!$shares): ?><p>Ainda não foi enviado para ninguém.</p><?php else: ?>
    <div style="overflow-x: auto;"><table class="mat-table">
      <thead><tr><th scope="col">Paciente</th><th scope="col">Situação</th><th scope="col">Enviado em</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
      <tbody>
      <?php foreach ($shares as $s): ?>
        <tr data-testid="material-share-row" data-patient="<?= (int)$s['patient_id'] ?>">
          <td><a href="/pacientes/<?= (int)$s['patient_id'] ?>"><?= $e($s['patient_name']) ?></a></td>
          <td><?= $s['revoked_at'] ? 'Revogado em ' . $e($fmt($s['revoked_at'])) : ($s['first_opened_at'] ? 'Aberto em ' . $e($fmt($s['first_opened_at'])) : 'Ainda não abriu') ?></td>
          <td><?= $e($fmt($s['shared_at'])) ?></td>
          <td><?php if ($canManage && !$s['revoked_at']): ?>
            <form method="POST" action="/materiais/envios/<?= (int)$s['id'] ?>/revogar"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="back" value="/materiais/<?= $id ?>"><button class="btn btn-secondary btn-sm" type="submit" data-testid="material-revoke">Revogar</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <?php if ($canManage): ?>
  <details class="mat-card">
    <summary style="cursor: pointer; font-weight: 700;">Editar título, descrição e categoria</summary>
    <form method="POST" action="/materiais/<?= $id ?>" class="mat-upload" style="margin-top: 12px;">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label>Título <input name="title" required maxlength="160" value="<?= $e($m['title']) ?>"></label>
      <label>Categoria <select name="category" required><?php foreach (M::CATEGORIES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $m['category'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
      <label class="mat-wide">Descrição <textarea name="description" rows="2" maxlength="2000"><?= $e($m['description']) ?></textarea></label>
      <div><button class="btn btn-secondary" type="submit">Salvar</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>
<script src="/assets/js/materials.js?v=v54-21" defer></script>
