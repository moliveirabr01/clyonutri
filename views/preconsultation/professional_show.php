<?php
use ClyoNutri\Domain\PreConsultation\PreConsultationService;
use ClyoNutri\Domain\PreConsultation\QuestionBank;

$pid = (int)$patient['id'];
$qid = (int)$q['id'];
$base = "/pacientes/{$pid}/pre-consulta/{$qid}";
$statusLabel = ['draft' => 'Em edição (ainda não enviado)', 'sent' => 'Enviado, aguardando o paciente', 'in_progress' => 'Paciente começou a responder', 'submitted' => 'Respondido pelo paciente', 'reviewed' => 'Revisado', 'revoked' => 'Link revogado'];
$selected = [];
foreach ($q['questions'] as $item) { $selected[$item['id']] = $item; }
$suggested = [];
foreach ($q['suggestion'] as $s) { $suggested[$s['id']] = $s; }
$answered = in_array($q['status'], ['submitted', 'reviewed'], true);
?>
<nav aria-label="Navegação" style="margin-bottom: var(--space-3); font-size: 0.9rem;"><a href="/pacientes/<?= $pid ?>/pre-consulta">← Pré-consultas de <?= $e($patient['name']) ?></a></nav>
<h1 style="font-size: 1.5rem; font-weight: 700; color: var(--brand-navy-900); margin: 0 0 4px;">Pré-consulta</h1>
<p style="color: var(--neutral-gray-500); margin: 0 0 var(--space-4);" data-testid="pc-status">
  <?= $e($q['is_expired'] ? 'Link expirado' : ($statusLabel[$q['status']] ?? $q['status'])) ?>
  · Objetivos: <?= $e(implode(', ', array_map(fn($o) => QuestionBank::OBJECTIVES[$o]['label'] ?? $o, $q['objectives'])) ?: 'nenhum (sugestão ignorada)') ?>
  · Banco de perguntas <?= $e($q['bank_version']) ?>
</p>

<?php if ($flash): ?>
  <div role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>" class="card" style="border-left: 4px solid <?= ($flash['type'] ?? '') === 'error' ? '#C62828' : 'var(--semantic-success)' ?>; margin-bottom: var(--space-4);"><?= $e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<?php if ($link): ?>
  <div class="card" style="margin-bottom: var(--space-4); border-left: 4px solid var(--brand-copper-600);" data-testid="pc-link">
    <h2 style="font-size: 1.1rem; margin: 0 0 8px;">Envie o link ao paciente</h2>
    <label class="form-label" for="pc-link-input">Link do paciente (mostrado só agora; gerar outro link invalida este)</label>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: stretch;">
      <input class="form-control" id="pc-link-input" type="text" readonly value="<?= $e($link) ?>" style="flex: 1 1 280px;">
      <button type="button" class="btn btn-secondary" id="pc-copy-link" data-copy-target="pc-link-input" data-testid="pc-copy-link">Copiar link</button>
      <?php if (!empty($whatsappUrl)): ?>
        <a class="btn" href="<?= $e($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer" data-testid="pc-whatsapp"
           style="background: #1F7A4A; color: #fff; border: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; text-decoration: none;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.4a.5.5 0 0 0 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.2c0-.1-.2-.2-.5-.3z"/></svg>
          Enviar pelo WhatsApp
        </a>
      <?php endif; ?>
    </div>
    <p role="status" aria-live="polite" id="pc-copy-status" style="margin: 6px 0 0; font-size: 0.85rem; color: var(--semantic-success, #18794E);"></p>
    <small style="display: block; color: var(--neutral-gray-500);">
      Para abrir, o paciente confirma a data de nascimento cadastrada.
      <?php if (!empty($whatsappUrl) && empty($whatsappHasPhone)): ?>
        O paciente não tem celular válido no cadastro: o WhatsApp vai pedir para você escolher o contato.
        <a href="/pacientes/<?= $pid ?>/editar">Cadastrar celular</a>.
      <?php endif; ?>
    </small>
  </div>
<?php endif; ?>

<?php if ($q['status'] === 'draft' && $canEdit): ?>
<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="pc-edit">
  <h2 id="pc-edit" style="font-size: 1.15rem; margin: 0 0 6px;">Revise as perguntas</h2>
  <p style="margin: 0 0 12px; color: #475569; font-size: 0.9rem;">
    <?= $q['suggestion_ignored'] ? 'Você escolheu ignorar a sugestão: só as perguntas gerais estão marcadas.' : 'Marcadas: sugestão pelos objetivos. Inclua, retire ou mude a obrigatoriedade; perguntas que dependem de outra levam a outra junto.' ?>
  </p>
  <form method="POST" action="<?= $base ?>/perguntas">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <?php $currentSection = null; foreach (QuestionBank::questions() as $id => $bq):
        if ($bq['section'] !== $currentSection): if ($currentSection !== null): ?></fieldset><?php endif; $currentSection = $bq['section']; ?>
        <fieldset style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px;">
          <legend style="padding: 0 6px; font-weight: 600;"><?= $e(QuestionBank::SECTIONS[$currentSection]) ?></legend>
      <?php endif; $isSel = isset($selected[$id]); $isReq = $isSel ? !empty($selected[$id]['required']) : $bq['required']; ?>
      <div style="display: grid; grid-template-columns: 1fr auto; gap: 8px; padding: 6px 0; border-top: 1px solid #f1f5f9;" data-testid="pc-q-<?= $e($id) ?>">
        <label style="display: flex; gap: 8px; align-items: flex-start;">
          <input type="checkbox" name="questions[]" value="<?= $e($id) ?>" <?= $isSel ? 'checked' : '' ?> style="margin-top: 3px;">
          <span><?= $e($bq['text']) ?>
            <?php if (isset($suggested[$id]) && !in_array('base', $bq['tags'], true)): ?><span class="badge badge-info" style="margin-left: 6px;">Sugerida</span><?php endif; ?>
            <?php if (!empty($bq['condition'])): ?><small style="display: block; color: #64748b;">Aparece só conforme a resposta de: “<?= $e(QuestionBank::questions()[$bq['condition']['question']]['text']) ?>”</small><?php endif; ?>
            <?php if (isset($suggested[$id])): ?><small style="display: block; color: #64748b;"><?= $e($suggested[$id]['reason']) ?></small><?php endif; ?>
          </span>
        </label>
        <label style="display: flex; gap: 6px; align-items: center; font-size: 0.85rem; white-space: nowrap;">
          <input type="checkbox" name="required[]" value="<?= $e($id) ?>" <?= $isReq ? 'checked' : '' ?>> Obrigatória
        </label>
      </div>
    <?php endforeach; ?></fieldset>
    <button type="submit" class="btn btn-secondary">Salvar perguntas</button>
  </form>
</section>
<?php endif; ?>

<?php if ($canEdit && in_array($q['status'], ['draft', 'sent', 'in_progress'], true)): ?>
<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="pc-send">
  <h2 id="pc-send" style="font-size: 1.15rem; margin: 0 0 12px;"><?= $q['status'] === 'draft' ? 'Enviar ao paciente' : 'Reenviar link' ?></h2>
  <form method="POST" action="<?= $base ?>/enviar" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <div class="form-group">
      <label class="form-label" for="pc-ttl">Validade do link (dias)</label>
      <input class="form-control" id="pc-ttl" type="number" name="ttl_days" min="1" max="<?= PreConsultationService::MAX_TTL_DAYS ?>" value="<?= PreConsultationService::DEFAULT_TTL_DAYS ?>" style="max-width: 120px;">
    </div>
    <fieldset class="form-group" style="border: 0; padding: 0; margin: 0;">
      <legend class="form-label">Canal</legend>
      <label style="display: flex; gap: 6px;"><input type="radio" name="channel" value="whatsapp" checked> WhatsApp (mensagem pronta para enviar)</label>
      <label style="display: flex; gap: 6px;"><input type="radio" name="channel" value="email"> E-mail do cadastro + link para copiar</label>
      <label style="display: flex; gap: 6px;"><input type="radio" name="channel" value="link"> Só gerar o link</label>
    </fieldset>
    <button type="submit" class="btn btn-primary"><?= $q['status'] === 'draft' ? 'Enviar' : 'Gerar novo link' ?></button>
  </form>
  <?php if ($q['status'] !== 'draft'): ?>
    <form method="POST" action="<?= $base ?>/revogar" style="margin-top: 12px;">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <button type="submit" class="btn btn-outline">Revogar link</button>
    </form>
    <p style="font-size: 0.85rem; color: #475569; margin: 8px 0 0;">Expira em <?= $e(date('d/m/Y H:i', strtotime((string)$q['token_expires_at']))) ?><?= $q['opened_at'] ? ' · aberto em ' . $e(date('d/m/Y H:i', strtotime((string)$q['opened_at']))) : ' · ainda não aberto' ?><?= $q['draft_saved_at'] ? ' · rascunho salvo em ' . $e(date('d/m/Y H:i', strtotime((string)$q['draft_saved_at']))) : '' ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="pc-answers">
  <h2 id="pc-answers" style="font-size: 1.15rem; margin: 0 0 12px;"><?= $answered ? 'Respostas do paciente' : 'Perguntas do questionário' ?></h2>
  <?php if ($answered): ?><p style="font-size: 0.85rem; color: #475569; margin: 0 0 12px;">Enviado em <?= $e(date('d/m/Y H:i', strtotime((string)$q['submitted_at']))) ?>. Relato do paciente, sem interpretação automática.</p><?php endif; ?>
  <dl style="margin: 0;" data-testid="pc-answer-list">
    <?php foreach ($q['questions'] as $item):
        if ($answered && !PreConsultationService::isVisible($item, $q['answers'])) continue;
        $has = array_key_exists($item['id'], $q['answers']); ?>
      <div style="padding: 8px 0; border-top: 1px solid #f1f5f9;">
        <dt style="font-weight: 600;"><?= $e($item['text']) ?><?= !empty($item['required']) ? ' <span style="color:#B94F1F" aria-label="obrigatória">*</span>' : '' ?></dt>
        <dd style="margin: 4px 0 0; color: #334155;"><?= $answered ? ($has ? nl2br($e(PreConsultationService::answerText($item, $q['answers'][$item['id']]))) : '<em>Sem resposta</em>') : $e(QuestionBank::SECTIONS[$item['section']]) ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>
</section>

<?php if ($answered && $canEdit): ?>
<section class="card" style="margin-bottom: var(--space-5);" aria-labelledby="pc-review">
  <h2 id="pc-review" style="font-size: 1.15rem; margin: 0 0 12px;">Revisão</h2>
  <form method="POST" action="<?= $base ?>/revisar" style="margin-bottom: 12px;">
    <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
    <div class="form-group">
      <label class="form-label" for="pc-notes">Anotações da revisão (opcional)</label>
      <textarea class="form-control" id="pc-notes" name="review_notes" rows="3" maxlength="3000"><?= $e($q['review_notes'] ?? '') ?></textarea>
    </div>
    <button type="submit" class="btn btn-secondary"><?= $q['status'] === 'reviewed' ? 'Atualizar revisão' : 'Marcar como revisado' ?></button>
  </form>
  <?php if (empty($q['imported_anamnesis_id'])): ?>
    <form method="POST" action="<?= $base ?>/importar">
      <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
      <button type="submit" class="btn btn-primary">Levar respostas para o rascunho da anamnese</button>
      <small style="display: block; color: var(--neutral-gray-500); margin-top: 6px;">Cria (ou complementa) um rascunho na anamnese, identificado como relato da pré-consulta. Você revisa e finaliza lá.</small>
    </form>
  <?php else: ?>
    <p>Respostas importadas em <?= $e(date('d/m/Y H:i', strtotime((string)$q['imported_at']))) ?>. <a href="/pacientes/<?= $pid ?>/anamnese">Abrir anamnese</a></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($snapshots): ?>
<details class="card"><summary>Registro de salvamentos (auditoria)</summary>
  <ul style="font-size: 0.85rem;"><?php foreach ($snapshots as $s): ?><li><?= $e(date('d/m/Y H:i:s', strtotime((string)$s['saved_at']))) ?> · <?= $s['kind'] === 'submitted' ? 'envio final' : 'rascunho' ?> · SHA-256 <?= $e(substr((string)$s['answers_sha256'], 0, 16)) ?>…</li><?php endforeach; ?></ul>
</details>
<?php endif; ?>

<?php if ($link): ?>
<script src="/assets/js/pre-consulta-profissional.js" nonce="<?= $e(\ClyoNutri\Core\Http\SecurityHeaders::nonce()) ?>" defer></script>
<?php endif; ?>
