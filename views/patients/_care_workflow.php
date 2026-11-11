<?php
$ef=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$progressBase='/consulta/'.(int)$careFlow['appointment_id'].'/progresso';
?>
<section class="care-flow" id="care-progress" aria-label="Progresso deste atendimento" data-testid="care-flow">
<h2 class="care-flow__title">Progresso do atendimento</h2>
<?php if(!empty($careFlow['quantitative'])): ?>
<?= \ClyoNutri\Application\View\OperationalUI::progress('care-progress-meter','Etapas necessárias confirmadas: '.$careFlow['done'].' de '.$careFlow['total'], $careFlow['done'],$careFlow['total']) ?>
<?php else: ?><p>Defina a aplicabilidade das etapas para este atendimento. Sem percentual enquanto não houver um conjunto necessário confirmado.</p><?php endif; ?>
<?php if($careFlow['next']): $nextPrimary=empty($attendanceBarPrimary); ?><p data-testid="care-flow-next"><?php if(!$nextPrimary): ?><span>Próxima ação: </span><?php endif; ?><a class="op-button op-button--<?= $nextPrimary?'primary':'secondary' ?>" href="<?= $ef($careFlow['next']['url']) ?>">Continuar <?= $ef($careFlow['next']['label']) ?></a></p><?php elseif(!empty($careFlow['quantitative'])&&($careFlow['remaining_required']??1)===0): ?><p class="op-message op-message--success" role="status" data-testid="care-flow-ready">Etapas necessárias confirmadas. A conclusão do atendimento continua sendo decisão profissional, no hub da consulta.</p><?php else: ?><p>Etapas confirmadas ou dispensadas. A conclusão clínica permanece uma decisão profissional.</p><?php endif; ?>
<ol class="care-flow__steps">
<?php foreach($careFlow['steps'] as $s): ?>
<li class="care-flow__step care-flow__step--<?= $ef($s['state']) ?>" data-step="<?= $ef($s['key']) ?>" data-state="<?= $ef($s['state']) ?>"><a href="<?= $ef($s['url']) ?>"><strong><?= $ef($s['label']) ?></strong><span><?= $ef(\ClyoNutri\Domain\Appointment\CareWorkflow::LABELS[$s['state']]) ?></span><span><?= $ef($s['detail']) ?></span></a>
<details><summary>Aplicabilidade e confirmação</summary>
<p>Atual: <?= $ef(['required'=>'Necessária','optional'=>'Opcional','waived'=>'Dispensada','unconfigured'=>'A definir'][$s['policy']]) ?>. Nenhuma etapa impede a conduta clínica.</p>
<form method="post" action="<?= $progressBase ?>" class="op-stack">
<input type="hidden" name="_csrf_token" value="<?= $ef(\ClyoNutri\Core\Security\Csrf::token()) ?>"><input type="hidden" name="patient_id" value="<?= (int)$careFlow['patient_id'] ?>"><input type="hidden" name="step" value="<?= $ef($s['key']) ?>"><input type="hidden" name="revision" value="<?= $ef($careFlow['revision']) ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="evidence" value="<?= $ef($s['evidence']) ?>">
<label>Decisão<select name="decision" class="form-control"><option value="required">Etapa necessária</option><option value="optional">Etapa opcional</option><option value="waived">Dispensar esta etapa</option><?php if($s['can_confirm']): ?><option value="confirm">Vincular o registro indicado a este atendimento</option><?php endif; ?></select></label>
<label>Motivo se dispensada<select name="reason" class="form-control"><option value="">Selecione se dispensar</option><?php foreach(\ClyoNutri\Domain\Appointment\CareProgress::REASONS as $k=>$v): ?><option value="<?= $ef($k) ?>"><?= $ef($v) ?></option><?php endforeach; ?></select></label>
<button class="op-button op-button--secondary" type="submit">Registrar decisão</button>
</form></details></li>
<?php endforeach; ?></ol>
</section>
