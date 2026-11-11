<?php if(!empty($agendaDayRules)): ?>
<div class="agenda-calendar-markers" data-calendar-date="<?= htmlspecialchars($agendaMarkerDate,ENT_QUOTES,'UTF-8') ?>" aria-label="Feriados e exceções desta data">
<?php
  // NI2-615: o nome do feriado aparece no resumo (feriados primeiro, depois bloqueios e aberturas), não só a contagem.
  $agendaRuleNames=[];
  foreach([['holiday'],['closed'],['open']] as [$agendaKind])foreach($agendaDayRules as $agendaR)if($agendaR['kind']===$agendaKind)$agendaRuleNames[$agendaR['label']]=true;
  $agendaRuleNames=array_keys($agendaRuleNames);
?>
<details><summary><strong data-testid="agenda-calendar-names"><?= htmlspecialchars(implode(' · ',$agendaRuleNames),ENT_QUOTES,'UTF-8') ?></strong><?= count($agendaDayRules)>1?' ('.count($agendaDayRules).' regras)':'' ?> · Ver períodos e escopos</summary>
<p class="agenda-calendar-note">Feriados e bloqueios prevalecem sobre aberturas. Remover uma regra pode manter outras restrições.</p>
<?php foreach($agendaDayRules as $agendaRule): ?>
<a class="agenda-calendar-marker <?= $agendaRule['kind']==='open'?'agenda-calendar-marker--open':'' ?>" data-calendar-rule="<?= (int)$agendaRule['id'] ?>" href="/configuracoes?tab=agenda&amp;secao=feriados#calendar-rule-<?= (int)$agendaRule['id'] ?>">
<strong><?= htmlspecialchars($agendaRule['label'],ENT_QUOTES,'UTF-8') ?></strong>
<span><?= htmlspecialchars($agendaRule['period_label'],ENT_QUOTES,'UTF-8') ?></span>
<small><?= htmlspecialchars($agendaRule['scope_label'],ENT_QUOTES,'UTF-8') ?></small>
</a>
<?php endforeach; ?></details>
</div>
<?php endif; ?>
