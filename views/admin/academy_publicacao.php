<?php /* v54.22: publicação escrita pela equipe no console. Entradas: $pub (null para nova), $flash, $old, $operatorId, $csrfToken. */
use ClyoNutri\Domain\Academy\AcademyPublishingService as P;
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$status = $pub ? (string)$pub['status'] : 'new';
$editable = $status === 'new' || in_array($status, ['draft', 'rejected', 'archived'], true);
$v = static fn(string $k): string => (string)($old[$k] ?? ($pub[$k] ?? ''));
$action = $pub ? '/admin/academy/publicacao/' . (int)$pub['id'] . '/publicacao-salvar' : '/admin/academy/publicacao-salvar';
?>
<p><a href="/admin/academy">← Clyo Academy</a></p>
<?php if ($flash): ?><div class="admin-alert admin-alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>" data-testid="academy-admin-flash"><?= $e($flash['text']) ?></div><?php endif; ?>

<div class="admin-card admin-card--pad" data-testid="academy-publication" data-status="<?= $e($status) ?>">
  <h2 class="admin-page-title"><?= $pub ? $e($pub['title']) : 'Nova publicação' ?></h2>
  <?php if ($pub): ?>
    <p>Situação: <strong><?= $e(P::STATUS[$status] ?? $status) ?></strong><?= $status === 'published' ? ' · visível para todas as clínicas' : ' · as clínicas não veem' ?><?= (int)$pub['clinic_id'] > 0 ? ' · enviada pela clínica #' . (int)$pub['clinic_id'] . ' antes da v54.22' : '' ?></p>
    <?php if (!empty($pub['editorial_notes']) && in_array($status, ['rejected', 'archived'], true)): ?>
      <p data-testid="academy-publication-notes"><?= $status === 'rejected' ? 'Parecer da revisão' : 'Motivo do arquivamento' ?>: <?= $e($pub['editorial_notes']) ?></p>
    <?php endif; ?>
  <?php endif; ?>
  <p class="admin-hint">O texto é salvo como rascunho. Depois de enviado, outra pessoa da equipe revisa na fila do console e só então ele aparece para as clínicas. Não inclua nada que identifique paciente (nome, CPF, telefone, e-mail, prontuário, data de nascimento): o sistema recusa. Revisão editorial não é revisão por pares acadêmica.</p>

  <?php if ($status === 'submitted'): ?>
    <p>Esta publicação está na fila de revisão. A aprovação ou devolução é feita na página principal da Academy, por outra pessoa da equipe.</p>
  <?php elseif ($status === 'published'): ?>
    <p>Para corrigir uma publicação no ar, arquive primeiro. Ela volta a ser rascunho editável e passa de novo pela revisão.</p>
  <?php endif; ?>

  <?php if ($editable): ?>
  <form method="POST" action="<?= $e($action) ?>" data-testid="academy-publication-form" class="admin-form">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label>Quem assina (nome)<br><input name="author_name" required maxlength="160" value="<?= $e($v('author_name')) ?>"></label>
    <label>CRN com região<br><input name="author_crn" required placeholder="CRN-3 12345" value="<?= $e($v('author_crn')) ?>"></label>
    <label class="admin-form__wide">Título<br><input name="title" required maxlength="255" value="<?= $e($v('title')) ?>"></label>
    <label>Categoria<br><select name="category" required><option value="">Escolha…</option><?php foreach (P::CATEGORIES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $v('category') === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <label class="admin-form__wide">Resumo<br><textarea name="summary" rows="3"><?= $e($v('summary')) ?></textarea></label>
    <label class="admin-form__wide">Texto (exibido como texto simples)<br><textarea name="body_markdown" rows="12"><?= $e($v('body_markdown')) ?></textarea></label>
    <label class="admin-form__wide">Referências (com DOI ou PMID quando houver)<br><textarea name="references_text" rows="4"><?= $e($v('references_text')) ?></textarea></label>
    <label class="admin-form__wide">Conflitos de interesse e financiamento<br><input name="conflict_of_interest_disclosure" maxlength="500" value="<?= $e($v('conflict_of_interest_disclosure')) ?>"></label>
    <div class="admin-actions">
      <button class="btn btn--secondary btn--sm" type="submit" name="save_action" value="draft">Salvar rascunho</button>
      <button class="btn btn--primary btn--sm" type="submit" name="save_action" value="submit">Salvar e enviar para revisão</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php if ($pub && in_array($status, ['published', 'draft', 'rejected'], true)): ?>
<div class="admin-card admin-card--pad">
  <h3 class="admin-section-title">Arquivar</h3>
  <form method="POST" action="/admin/academy/publicacao/<?= (int)$pub['id'] ?>/publicacao-arquivar" data-testid="academy-publication-archive" class="admin-inline-form">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <label>Motivo<br><input name="reason" required minlength="5" maxlength="255" class="admin-field-reason"></label>
    <button class="btn btn--secondary btn--sm" type="submit">Arquivar</button>
  </form>
  <p class="admin-hint">Arquivar tira a publicação do ar para as clínicas. O texto continua guardado e pode ser editado e enviado de novo.</p>
</div>
<?php endif; ?>
