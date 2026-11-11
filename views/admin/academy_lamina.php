<?php /* v54.23: lâmina para pacientes no console. Entradas: $handout (null para nova), $flash, $old, $csrfToken. */
use ClyoNutri\Domain\Academy\AcademyHandoutService as H;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$status = $handout ? (string)$handout['status'] : 'new';
$v = static fn(string $k): string => (string)($old[$k] ?? ($handout[$k] ?? ''));
$action = $handout ? '/admin/academy/lamina/' . (int)$handout['id'] . '/lamina-salvar' : '/admin/academy/lamina-salvar';
?>
<p><a href="/admin/academy">← Clyo Academy</a></p>
<?php if ($flash): ?><div class="admin-alert admin-alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="academy-admin-flash"><?= $e($flash['text']) ?></div><?php endif; ?>

<div class="admin-card admin-card--pad" data-testid="academy-handout" data-status="<?= $e($status) ?>">
  <h2 class="admin-page-title"><?= $handout ? $e($handout['title']) : 'Nova lâmina para pacientes' ?></h2>
  <?php if ($handout): ?>
    <p>Situação: <strong><?= $e(H::STATUS[$status] ?? $status) ?></strong><?= $status === 'published' ? ' · visível para todas as clínicas' : ' · as clínicas não veem' ?></p>
    <?php if ($status === 'archived' && !empty($handout['archived_reason'])): ?><p>Motivo do arquivamento: <?= $e($handout['archived_reason']) ?></p><?php endif; ?>
    <p>
      <a class="btn btn--secondary btn--sm" href="/admin/academy/laminas/<?= (int)$handout['id'] ?>/arquivo" target="_blank" rel="noopener" data-testid="academy-handout-admin-pdf">Abrir o PDF</a>
      · <?= $e($handout['file_name']) ?> (<?= $e(H::humanSize((int)$handout['size_bytes'])) ?>)
      · SHA-256 <code class="admin-code"><?= $e(substr((string)$handout['sha256'], 0, 16)) ?>…</code>
    </p>
    <?php if (!empty($handout['preview_storage_name'])): ?>
      <p><img src="/admin/academy/laminas/<?= (int)$handout['id'] ?>/previa" alt="Prévia da lâmina <?= $e($handout['title']) ?>" class="admin-preview-img"></p>
    <?php else: ?>
      <p class="admin-small">Sem imagem de prévia. A clínica vê só o título, o resumo e os botões do PDF.</p>
    <?php endif; ?>
  <?php endif; ?>
  <p class="admin-hint">A lâmina entra como rascunho. Confira o PDF aberto acima antes de publicar. PDF de até 10 MB, sem formulário, script ou senha; prévia opcional em PNG ou JPG de até 3 MB (de preferência a primeira página em tamanho reduzido). Indique a fonte oficial usada no conteúdo.</p>

  <form method="POST" action="<?= $e($action) ?>" enctype="multipart/form-data" data-testid="academy-handout-form" class="admin-form">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label class="admin-form__wide">Título<input type="text" name="title" required maxlength="200" value="<?= $e($v('title')) ?>"></label>
    <label>Endereço (slug)<input type="text" name="slug" maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $e($v('slug')) ?>" placeholder="gerado a partir do título"></label>
    <label>Etiquetas (separadas por vírgula)<input type="text" name="tags" maxlength="500" value="<?= $e($v('tags')) ?>" placeholder="hipertensão, sal, rótulos"></label>
    <label class="admin-form__wide">Resumo (aparece na busca)<textarea name="summary" rows="3" maxlength="1000"><?= $e($v('summary')) ?></textarea></label>
    <label class="admin-form__wide">Fonte<textarea name="source_citation" rows="2" required maxlength="2000" placeholder="Ministério da Saúde. Guia Alimentar para a População Brasileira. 2. ed. Brasília, 2014."><?= $e($v('source_citation')) ?></textarea></label>
    <label>PDF da lâmina<?= $handout ? ' (deixe vazio para manter o atual)' : '' ?><input type="file" name="pdf" accept="application/pdf,.pdf"<?= $handout ? '' : ' required' ?> data-testid="academy-handout-pdf"></label>
    <label>Prévia PNG ou JPG (opcional)<input type="file" name="preview" accept="image/png,image/jpeg,.png,.jpg,.jpeg" data-testid="academy-handout-preview"></label>
    <div class="admin-form__wide"><button type="submit" class="btn btn--primary" data-testid="academy-handout-save"><?= $handout ? 'Salvar alterações' : 'Cadastrar como rascunho' ?></button></div>
  </form>
</div>

<?php if ($handout): ?>
<div class="admin-card admin-card--pad admin-stack">
  <?php if ($status !== 'published'): ?>
    <form method="POST" action="/admin/academy/lamina/<?= (int)$handout['id'] ?>/lamina-publicar" class="admin-flush">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <button type="submit" class="btn btn--primary" data-testid="academy-handout-publish">Publicar para as clínicas</button>
    </form>
  <?php endif; ?>
  <?php if ($status !== 'archived'): ?>
    <form method="POST" action="/admin/academy/lamina/<?= (int)$handout['id'] ?>/lamina-arquivar" class="admin-inline-form">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <label class="admin-grow">Motivo para arquivar<input type="text" name="reason" required minlength="5" maxlength="255"></label>
      <button type="submit" class="btn btn--secondary" data-testid="academy-handout-archive">Arquivar</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
