<?php
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$questionRows = $definition['questions'];
if (!$questionRows) $questionRows = [['key'=>'pergunta_1','text'=>'','type'=>'text']];
?>
<p><a href="/configuracoes/protocolos">← Protocolos da clínica</a></p>
<h1>Editor de protocolos da clínica</h1>
<p>Modelos privados. Editar uma versão publicada cria outra versão e preserva o histórico. O conteúdo exige revisão clínica independente antes de ser ativado.</p>
<?php if ($error): ?><p class="op-message op-message--error" role="alert"><?= $e($error) ?> — o formulário abaixo mantém suas alterações.</p><?php endif; ?>
<?php if ($notice): ?><p class="op-message op-message--info" role="status"><?= $e($notice) ?></p><?php endif; ?>
<form method="POST" action="/configuracoes/protocolos/editor" class="card" data-protocol-editor>
  <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version_id" value="<?= $version ?>"><input type="hidden" name="base_hash" value="<?= $e($baseHash) ?>">
  <label>Título <input class="form-control" name="title" value="<?= $e($definition['title']) ?>" maxlength="160" required></label>
  <?php require __DIR__.'/_dietary_editor.php'; ?>
  <label>Resumo <textarea class="form-control" name="summary"><?= $e($definition['summary'] ?? '') ?></textarea></label>
  <label>Escopo clínico <textarea class="form-control" name="scope" required><?= $e($definition['scope'] ?? '') ?></textarea></label>
  <label>Responsável editorial <input class="form-control" name="editor" value="<?= $e($definition['editor'] ?? '') ?>" required></label>
  <label>Referências clínicas e versão <textarea class="form-control" name="references"><?= $e($references) ?></textarea></label>
  <div data-question-list>
  <?php foreach ($questionRows as $i=>$q): require __DIR__ . '/_protocol_editor_question.php'; endforeach; ?>
  </div>
  <?php if ($preview): ?><fieldset class="card" data-testid="protocol-answer-preview"><legend>Experimente as respostas da prévia</legend><?php foreach ($preview['items'] as $it): if (!$it['visible']) continue; ?><label><?= $e($it['text']) ?><?php $pv = $_POST['answers'][$it['answer_key']] ?? ''; if ($it['options']): ?><select class="form-control" name="answers[<?= $e($it['answer_key']) ?>]<?= $it['type'] === 'multi' ? '[]' : '' ?>" <?= $it['type'] === 'multi' ? 'multiple' : '' ?>><option value="">Selecione</option><?php foreach ($it['options'] as $val=>$label): ?><option value="<?= $e($val) ?>" <?= in_array((string)$val, (array)$pv, true) ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><?php else: ?><input class="form-control" name="answers[<?= $e($it['answer_key']) ?>]" value="<?= $e(is_scalar($pv) ? $pv : '') ?>"><?php endif; ?></label><?php if (isset($preview['errors'][$it['answer_key']])): ?><p class="op-message op-message--error" role="alert"><?= $e($preview['errors'][$it['answer_key']]) ?></p><?php endif; ?><?php endforeach; ?></fieldset><?php endif; ?>
  <button class="btn btn-secondary" type="button" data-add-question>Adicionar pergunta</button>
  <button class="btn btn-secondary" name="action" value="preview">Pré-visualizar e validar</button>
  <button class="btn btn-primary" name="action" value="save">Salvar rascunho</button>
</form>
<template id="protocol-question-template"><?php $i = '__INDEX__'; $q = ['key'=>'','text'=>'','type'=>'text']; require __DIR__ . '/_protocol_editor_question.php'; ?></template>
<?php if ($preview): ?><section class="card" data-testid="protocol-preview"><h2>Prévia validada pelo servidor</h2><p>Os tipos, limites e condições usam o mesmo motor da avaliação.</p><ol><?php foreach ($preview['items'] as $it): ?><li><?= $e($it['text']) ?> · <?= $e($it['type']) ?><?= $it['required'] ? ' · obrigatória' : ' · opcional' ?><?= !$it['visible'] ? ' · depende de resposta anterior' : '' ?><?php if ($it['options']): ?><ul><?php foreach ($it['options'] as $label): ?><li><?= $e($label) ?></li><?php endforeach; ?></ul><?php endif; ?></li><?php endforeach; ?></ol></section><?php endif; ?>
<section class="card"><h2>Modelos e versões privadas</h2><p><a href="/configuracoes/protocolos/editor">Criar novo modelo</a></p>
<?php foreach ($rows as $r): ?><article><h3><?= $e($r['name']) ?> · v<?= (int)$r['version'] ?> · <?= $e(['draft'=>'Rascunho','in_review'=>'Em revisão','approved'=>'Aprovado','retired'=>'Aposentado'][$r['status']]) ?></h3>
  <a href="/configuracoes/protocolos/editor?version_id=<?= (int)$r['id'] ?>"><?= $r['status'] === 'draft' ? 'Editar' : 'Criar nova versão a partir desta' ?></a>
  <?php if (in_array($r['status'], ['draft','in_review'], true)): ?><form method="POST" action="/configuracoes/protocolos/editor"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version_id" value="<?= (int)$r['id'] ?>">
  <?php if ($r['status']==='draft'): ?><button class="btn btn-secondary" name="action" value="submit">Solicitar revisão clínica</button>
  <?php else: ?><label>Próxima revisão <input type="date" name="review_due_at" required></label><button class="btn btn-secondary" name="action" value="approve">Aprovar como revisor clínico</button><?php endif; ?></form><?php endif; ?>
<?php if ($r['status'] === 'approved'): ?><form method="POST" action="/configuracoes/protocolos/editor"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version_id" value="<?= (int)$r['id'] ?>"><label>Identificador da clínica destinatária <input type="number" name="target_clinic" min="1" required></label><button class="btn btn-secondary" name="action" value="share">Compartilhar modelo (exige licença)</button></form><?php endif; ?>
</article><?php endforeach; ?></section>
<?php if ($received): ?><section class="card"><h2>Modelos compartilhados com esta clínica</h2><?php foreach ($received as $share): ?><form method="POST" action="/configuracoes/protocolos/editor"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="share_id" value="<?= (int)$share['id'] ?>"><p><?= $e($share['name']) ?> · v<?= (int)$share['version'] ?></p><button class="btn btn-secondary" name="action" value="import_shared">Criar cópia privada para revisão</button></form><?php endforeach; ?></section><?php endif; ?>
<script src="/assets/js/protocol-editor.js?v=v54-17" defer></script>

<section class="card"><h2>Propostas iniciais por comorbidade</h2><p>Cria conteúdo privado em rascunho, com referência. Nenhuma proposta é aprovada ou ativada automaticamente. Estes modelos mínimos não são anamneses clínicas completas.</p>
<?php foreach(\ClyoNutri\Domain\Dietary\DietaryDefinition::CONDITIONS as $key=>$label):?>
<form method="POST" action="/configuracoes/protocolos/editor"><input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="condition" value="<?= $e($key) ?>"><button class="btn btn-secondary" name="action" value="dietary_starter">Criar proposta para <?= $e($label) ?></button></form>
<?php endforeach;?></section>
