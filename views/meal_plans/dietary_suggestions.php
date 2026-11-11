<?php $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); $conditionNames=array_column($preview['cards'],'protocol_name','protocol_code'); ?>
<p><a href="/planos/<?= $planId ?>">← Voltar ao plano</a></p>
<h1>Sugestões por comorbidade</h1>
<p>Plano: <?= $e($preview['plan']['title']) ?>. Revise cada orientação e o conjunto de condições antes de confirmar. Nenhuma sugestão é aplicada automaticamente.</p>
<?php if($error):?><p role="alert" class="op-message op-message--error"><?= $e($error) ?> Os textos digitados foram mantidos para comparação; revise o contexto atualizado.</p><?php endif;?>
<?php if($notice):?><p role="status" class="op-message op-message--success"><?= $e($notice) ?></p><?php endif;?>
<?php foreach($preview['messages'] as $message):?><p class="op-message op-message--info"><?= $e($message) ?></p><?php endforeach;?>
<details class="card"><summary>Condições e restrições que precisam de revisão</summary>
 <ul><?php foreach($preview['active_conditions'] as $c):?><li><?= $e($conditionNames[$c['protocol_code']]??(\ClyoNutri\Domain\Dietary\DietaryDefinition::CONDITIONS[$c['protocol_code']]??'Condição registrada — consulte a anamnese')) ?> — <?= $e(\ClyoNutri\Domain\ClinicalProtocol\PatientConditionService::STATUS_LABELS[$c['status']]) ?>; origem: <?= $e(\ClyoNutri\Domain\ClinicalProtocol\PatientConditionService::PROVENANCE_LABELS[$c['provenance']]??$c['provenance']) ?></li><?php endforeach;?></ul>
 <p>Confira também alergias e restrições registradas: <?= $e($preview['allergy']['allergies_raw']??'') ?> <?= $e($preview['allergy']['restrictions_raw']??'') ?>.</p>
 <p>Ausência de alerta não comprova compatibilidade. Os ajustes devem considerar medicamentos, exames, fase da doença e preferências.</p>
</details>
<?php if($preview['cards']):?>
<form method="POST" class="card" action="/planos/<?= $planId ?>/sugestoes-dieteticas">
 <input type="hidden" name="_csrf_token" value="<?= $e($csrfToken) ?>">
 <input type="hidden" name="request_key" value="<?= $e($requestKey) ?>">
 <input type="hidden" name="revision" value="<?= $e($preview['workflow']['revision']) ?>">
 <input type="hidden" name="context_sha256" value="<?= $e($preview['context_sha256']) ?>">
 <?php foreach($preview['cards'] as $i=>$card):$id=$card['id'];$rule=$card['rule'];$blocked=(bool)$card['blocked']; ?>
 <fieldset style="min-width:0;margin:1rem 0;padding:1rem;overflow-wrap:anywhere">
  <legend><?= $e($rule['title']) ?></legend>
  <p><?= $e($card['protocol_name']) ?> · versão <?= $card['version'] ?> · <?= $e(\ClyoNutri\Domain\ClinicalProtocol\PatientConditionService::STATUS_LABELS[$card['condition_status']]) ?></p>
  <p><?= $e($rule['rationale']) ?></p>
  <p><a href="<?= $e($rule['source_url']) ?>" target="_blank" rel="noopener noreferrer">Consultar fonte de <?= $e($rule['title']) ?></a></p>
  <?php foreach($card['blocked'] as $block):?><p class="op-message op-message--error"><?= $e($block) ?></p><?php endforeach;?>
  <label style="display:flex;align-items:center;gap:0.75rem;min-height:44px;cursor:pointer;font-weight:500;">
    <input type="checkbox" name="selected[]" value="<?= $e($id) ?>" <?= $blocked?'disabled':'' ?> style="width:20px;height:20px;accent-color:var(--botanical-green-600,#0F3E17);cursor:pointer;">
    <span>Incluir esta orientação após minha revisão</span>
  </label>
  <label for="diet-text-<?= $i ?>" style="display:block;margin-top:0.5rem;font-weight:600;">Texto final para o paciente</label>
  <textarea class="form-control" id="diet-text-<?= $i ?>" name="texts[<?= $e($id) ?>]" rows="4" maxlength="1500" <?= $blocked?'disabled':'' ?> style="width:100%;margin-top:0.25rem;"><?= $e(is_string($posted['texts'][$id]??null)?$posted['texts'][$id]:$rule['guidance']) ?></textarea>
 </fieldset>
 <?php endforeach;?>
 <div style="margin-top:1.5rem;">
   <label for="diet-reason" style="display:block;font-weight:600;">Justificativa clínica e avaliação das combinações *</label>
   <textarea class="form-control" id="diet-reason" name="reason" minlength="10" maxlength="1500" required rows="3" style="width:100%;margin-top:0.25rem;" placeholder="Descreva sua avaliação clínica das condições e comorbidades..."><?= $e(is_string($posted['reason']??null)?$posted['reason']:'') ?></textarea>
 </div>
 <div style="margin-top:1rem;">
   <label style="display:flex;align-items:flex-start;gap:0.75rem;min-height:44px;cursor:pointer;padding:4px 0;">
     <input type="checkbox" name="confirmed" value="1" required style="width:20px;height:20px;margin-top:2px;accent-color:var(--botanical-green-600,#0F3E17);cursor:pointer;">
     <span style="font-size:0.95rem;line-height:1.4;">Revisei as condições, alergias, restrições, fontes e textos finais. Confirmo a adequação das orientações selecionadas para este paciente.</span>
   </label>
 </div>
 <button class="btn btn-primary" type="submit" style="min-height:44px;padding:0.75rem 1.5rem;font-weight:600;margin-top:1rem;" <?= count(array_filter($preview['cards'],fn($card)=>!$card['blocked']))===0?'disabled':'' ?>>Confirmar e incluir no rascunho</button>
</form>
<?php endif;?>
<p><a href="/configuracoes/protocolos/editor">Revisar modelos e sugestões da clínica</a></p>
<?php if($preview['history']):?><section class="card"><h2>Confirmações registradas</h2><ul><?php foreach($preview['history'] as $h):?><li>Confirmação <?= (int)$h['id'] ?> — <?= $e($h['created_at']) ?> UTC · profissional identificado na trilha clínica.</li><?php endforeach;?></ul><p>As orientações ficam nas observações do plano. Reavalie-as antes de publicar se o contexto clínico mudar.</p></section><?php endif;?>
