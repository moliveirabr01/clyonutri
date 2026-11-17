<?php
$ce=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$calendarCsrf=\ClyoNutri\Core\Security\Csrf::token();
$calendarScope=function(?string $selected=null)use($bookingProfessionalId,$canManageBooking,$calendarProfessionals,$ce){$selected=$selected??($canManageBooking?'clinic':'own'); ?>
<select class="form-control" name="calendar_scope" required>
<?php if($canManageBooking): ?><option value="clinic" <?= $selected==='clinic'?'selected':'' ?>>Toda a clínica</option><?php endif; ?>
<?php if($bookingProfessionalId>0): ?><option value="own" <?= $selected==='own'?'selected':'' ?>>Minha agenda</option><?php endif; ?>
<?php if($canManageBooking): foreach($calendarProfessionals as $professional): if((int)$professional['id']===$bookingProfessionalId)continue; ?><option value="prof:<?= (int)$professional['id'] ?>" <?= $selected===('prof:'.(int)$professional['id'])?'selected':'' ?>><?= $ce($professional['name']) ?></option><?php endforeach; endif; ?>
</select>
<?php };
?>
<section class="clyo-calendar" aria-labelledby="calendar-heading">
<?php $calendarPart=($agendaSection??'feriados')==='regras'?'regras':'feriados'; ?>
<header class="clyo-calendar__header"><p class="clyo-calendar__eyebrow">Configurações / Agenda</p><?php if($calendarPart==='regras'): ?><h2 id="calendar-heading">Regras e lista de espera</h2><p>Intervalos entre consultas e lista de espera da clínica.</p><?php else: ?><h2 id="calendar-heading">Feriados e bloqueios</h2><p>Organize os dias em que sua clínica não atende.</p><?php endif; ?></header>
<div class="clyo-calendar__body">
<?php if(!$calendarAvailable): ?><p>A atualização de calendário ainda não está disponível neste servidor.</p>
<?php elseif($calendarPart==='feriados'): ?>
<?php /* NI2-615: importar feriados nacionais com revisão antes de gravar */ ?>
<details class="clyo-calendar__details clyo-calendar__import u-border-t-0 u-mt-0 u-pt-0 u-mb-5" data-testid="holiday-import" <?= $holidayImportPreview!==null?'open':'' ?>>
<summary>Importar feriados nacionais (<?= $ce(implode('/',$holidayImportYears)) ?>)</summary>
<p>Escolha o ano. A lista aparece para revisão e nada é gravado até você clicar em <strong>Salvar feriados selecionados</strong>. Fonte: <?= $ce(\ClyoNutri\Domain\Booking\NationalHolidays::SOURCE) ?>. Carnaval e Corpus Christi são pontos facultativos (não feriados nacionais): aparecem desmarcados; marque se o consultório fecha. Feriados estaduais e municipais continuam manuais.</p>
<p><a class="btn btn-primary" data-testid="holiday-import-year-todos" href="/configuracoes?tab=agenda&amp;secao=feriados&amp;importar=todos#holiday-import-preview" <?= !empty($holidayImportAll)?'aria-current="true"':'' ?>>Revisar <?= $ce(implode(' e ',$holidayImportYears)) ?></a> <?php foreach($holidayImportYears as $impYear): ?><a class="btn btn-secondary" data-testid="holiday-import-year-<?= (int)$impYear ?>" href="/configuracoes?tab=agenda&amp;secao=feriados&amp;importar=<?= (int)$impYear ?>#holiday-import-preview" <?= $holidayImportYear===$impYear?'aria-current="true"':'' ?>>Revisar <?= (int)$impYear ?></a> <?php endforeach; ?></p>
<?php if($holidayImportPreview!==null): ?>
<form class="clyo-calendar__form" method="post" action="/configuracoes" id="holiday-import-preview" data-testid="holiday-import-preview">
<input type="hidden" name="_csrf_token" value="<?= $ce($calendarCsrf) ?>"><input type="hidden" name="section" value="calendar_import"><input type="hidden" name="import_year" value="<?= !empty($holidayImportAll)?'todos':(int)$holidayImportYear ?>">
<div class="clyo-calendar__form-title"><h3>Feriados nacionais de <?= !empty($holidayImportAll)?$ce(implode(' e ',$holidayImportYears)):(int)$holidayImportYear ?></h3><span class="clyo-calendar__subtle">Dia inteiro · revise antes de salvar</span></div>
<ul class="clyo-calendar__import-list">
<?php foreach($holidayImportPreview as $h): ?>
<li><label><input type="checkbox" name="holiday_dates[]" value="<?= $ce($h['date']) ?>" <?= $h['already']?'disabled':($h['optional']?'':'checked') ?> <?= $h['optional']?'data-optional="1"':'' ?>> <strong><?= $ce(date('d/m/Y',strtotime($h['date']))) ?></strong> · <?= $h['optional']?'<em>Ponto facultativo</em> · ':'' ?><?= $ce($h['name']) ?> <span class="clyo-calendar__subtle">(<?= $ce($h['basis']) ?>)</span><?= $h['already']?' <em class="clyo-calendar__subtle">já cadastrado</em>':($h['other_rules']>0?' <em class="clyo-calendar__subtle">a data já tem '.\ClyoNutri\Application\View\UserText::count((int)$h['other_rules'], '{n} feriado', '{n} feriados').' com outro nome</em>':'') ?></label></li>
<?php endforeach; ?>
</ul>
<div class="clyo-calendar__grid"><label class="clyo-calendar__field">Aplicar a<?php $calendarScope(); ?></label></div>
<div class="clyo-calendar__actions"><a class="btn btn-secondary" href="/configuracoes?tab=agenda&amp;secao=feriados">Cancelar</a><button class="btn btn-primary" type="submit" data-testid="holiday-import-save">Salvar feriados selecionados</button></div>
</form>
<?php endif; ?>
</details>
<?php if($holidayImportPreview===null): /* NI2-615: com a prévia aberta, só a revisão aparece */ ?>
<form class="clyo-calendar__form" method="post" action="/configuracoes">
<input type="hidden" name="_csrf_token" value="<?= $ce($calendarCsrf) ?>"><input type="hidden" name="section" value="calendar_rule"><input type="hidden" name="kind" value="holiday">
<div class="clyo-calendar__form-title"><h3>Novo feriado</h3><span class="clyo-calendar__subtle">Dia inteiro ou período</span></div>
<div class="clyo-calendar__grid"><label class="clyo-calendar__field">Data<input class="form-control" type="date" name="rule_date" required></label><label class="clyo-calendar__field">Aplicar a<?php $calendarScope(); ?></label></div>
<div class="clyo-calendar__grid"><label class="clyo-calendar__field">Tipo<select class="form-control" name="label" required><?php foreach(\ClyoNutri\Domain\Booking\BookingCalendarService::HOLIDAY_LABELS as $holidayLabel): ?><option value="<?= $ce($holidayLabel) ?>"><?= $ce($holidayLabel) ?></option><?php endforeach; ?></select></label><label class="clyo-calendar__field">Nome do feriado (opcional)<input class="form-control" type="text" name="holiday_name" maxlength="60" placeholder="Ex.: Finados, Aniversário da cidade" data-testid="holiday-name"></label></div>
<?php $calendarPeriodRule=['kind'=>'holiday'];require __DIR__.'/_calendar_period_fields.php'; ?>
<div class="clyo-calendar__actions"><button class="btn btn-secondary" type="reset">Limpar</button><button class="btn btn-primary" type="submit">Salvar feriado</button></div>
</form>
<h3>Datas cadastradas</h3>
<?php if(!$calendarRules): ?><p class="clyo-calendar__note">Nenhum feriado ou exceção cadastrado.</p><?php else: ?>
<?php $calendarMonths=['01'=>'JAN','02'=>'FEV','03'=>'MAR','04'=>'ABR','05'=>'MAI','06'=>'JUN','07'=>'JUL','08'=>'AGO','09'=>'SET','10'=>'OUT','11'=>'NOV','12'=>'DEZ']; ?>
<?php $calendarDateGroups=[];foreach($calendarRules as $savedRule)$calendarDateGroups[$savedRule['rule_date']][]=$savedRule; ?>
<?php foreach($calendarDateGroups as $ruleDate=>$dateRules): ?>
<details class="clyo-calendar__date-group"><summary><?= $ce(date('d/m/Y',strtotime($ruleDate))) ?> · <strong><?= $ce(implode(', ',array_unique(array_column($dateRules,'label')))) ?></strong> · <?= \ClyoNutri\Application\View\UserText::count(count($dateRules), '{n} regra', '{n} regras') ?> · Ver escopos e períodos</summary>
<p>Feriados e bloqueios prevalecem sobre aberturas. A disponibilidade final depende de todas as regras aplicáveis; remover uma pode não liberar o dia. Consultas existentes são mantidas.</p>
<?php foreach($dateRules as $rule): $ruleScope=$rule['professional_id']===null?'Toda a clínica':($calendarProfessionalNames[(int)$rule['professional_id']]??'Agenda do profissional');$rulePeriod=$rule['start_time']!==null?$rule['start_time'].'–'.$rule['end_time']:'Dia inteiro'; ?>
<article class="clyo-calendar__entry" id="calendar-rule-<?= (int)$rule['id'] ?>">
<div class="clyo-calendar__date" aria-label="<?= $ce(substr($rule['rule_date'],8,2).'/'.substr($rule['rule_date'],5,2).'/'.substr($rule['rule_date'],0,4)) ?>"><span><?= $ce($calendarMonths[substr($rule['rule_date'],5,2)]??'') ?></span><strong><?= $ce(substr($rule['rule_date'],8,2)) ?></strong></div>
<div class="clyo-calendar__entry-copy"><strong><?= $ce($rule['label']) ?></strong><span>Regra #<?= (int)$rule['id'] ?></span><span class="clyo-calendar__subtle"><?= $ce($ruleScope.' · '.$rulePeriod.' · '.substr($rule['rule_date'],0,4)) ?></span></div>
<span class="clyo-calendar__badge"><?= $rule['kind']==='open'?'Abertura especial':'Agenda bloqueada' ?></span>
<a class="btn btn-secondary" href="/agenda?date=<?= $ce($rule['rule_date']) ?>">Ver na agenda</a>
<?php if($canManageBooking || ($rule['professional_id']!==null && $bookingProfessionalId>0 && (int)$rule['professional_id']===$bookingProfessionalId)): ?><form class="clyo-calendar__remove" method="post" action="/configuracoes"><input type="hidden" name="_csrf_token" value="<?= $ce($calendarCsrf) ?>"><input type="hidden" name="section" value="calendar_remove"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><button type="submit" class="btn btn-secondary" aria-label="<?= $ce('Remover '.$rule['label'].' em '.$rule['rule_date']) ?>">Remover regra #<?= (int)$rule['id'] ?></button></form><?php endif; ?>
<?php if($canManageBooking || ($rule['professional_id']!==null && $bookingProfessionalId>0 && (int)$rule['professional_id']===$bookingProfessionalId)): ?>
<details class="clyo-calendar__edit"><summary class="btn btn-secondary">Editar regra #<?= (int)$rule['id'] ?> — data e horário</summary>
<form class="clyo-calendar__form" method="post" action="/configuracoes">
<input type="hidden" name="_csrf_token" value="<?= $ce($calendarCsrf) ?>"><input type="hidden" name="section" value="calendar_update"><input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>"><input type="hidden" name="kind" value="<?= $ce($rule['kind']) ?>">
<div class="clyo-calendar__grid"><label class="clyo-calendar__field">Data<input class="form-control" name="rule_date" type="date" required value="<?= $ce($rule['rule_date']) ?>"></label><label class="clyo-calendar__field">Aplicar a<?php $calendarScope($rule['professional_id']===null?'clinic':((int)$rule['professional_id']===$bookingProfessionalId?'own':'prof:'.(int)$rule['professional_id'])); ?></label></div>
<?php if($rule['kind']==='holiday'): [$ruleHolidayType,$ruleHolidayName]=\ClyoNutri\Domain\Booking\BookingCalendarService::splitHolidayLabel($rule['label']); ?><div class="clyo-calendar__grid"><label class="clyo-calendar__field">Tipo<select class="form-control" name="label" required><?php foreach(\ClyoNutri\Domain\Booking\BookingCalendarService::HOLIDAY_LABELS as $holidayLabel): ?><option value="<?= $ce($holidayLabel) ?>" <?= $ruleHolidayType===$holidayLabel?'selected':'' ?>><?= $ce($holidayLabel) ?></option><?php endforeach; ?></select></label><label class="clyo-calendar__field">Nome do feriado (opcional)<input class="form-control" type="text" name="holiday_name" maxlength="60" value="<?= $ce($ruleHolidayName) ?>"></label></div><?php else: ?><label class="clyo-calendar__field">Descrição<input class="form-control" name="label" maxlength="100" required value="<?= $ce($rule['label']) ?>"></label><?php endif; ?>
<?php $calendarPeriodRule=$rule;require __DIR__.'/_calendar_period_fields.php'; ?>
<div class="clyo-calendar__actions"><button class="btn btn-primary" type="submit">Salvar alterações</button></div>
</form></details><?php endif; ?>
</article>
<?php endforeach; ?></details><?php endforeach; endif; ?>
<p class="clyo-calendar__note">O feriado impede novas marcações. Consultas já agendadas são mantidas.</p>
<details class="clyo-calendar__details"><summary>Bloqueios e aberturas especiais</summary><p>Aberturas substituem a jornada semanal da data. Feriados e bloqueios continuam tendo prioridade.</p>
<form class="clyo-calendar__form" method="post" action="/configuracoes">
<input type="hidden" name="_csrf_token" value="<?= $ce($calendarCsrf) ?>"><input type="hidden" name="section" value="calendar_rule">
<div class="clyo-calendar__grid"><label class="clyo-calendar__field">Data<input class="form-control" type="date" name="rule_date" required></label><label class="clyo-calendar__field">Aplicar a<?php $calendarScope(); ?></label></div>
<div class="clyo-calendar__grid"><label class="clyo-calendar__field">Tipo<select class="form-control" name="kind"><option value="closed">Bloqueio (dia ou período)</option><option value="open">Abertura especial</option></select></label><label class="clyo-calendar__field">Descrição<input class="form-control" type="text" name="label" maxlength="100" required></label></div>
<?php $calendarPeriodRule=['kind'=>'closed'];require __DIR__.'/_calendar_period_fields.php'; ?>
<p class="clyo-calendar__subtle">Para bloquear o dia inteiro, deixe os horários vazios. Aberturas especiais exigem início e fim. Não inclua dados de pacientes na descrição.</p><div class="clyo-calendar__actions"><button class="btn btn-primary" type="submit">Salvar exceção</button></div>
</form></details>
<?php endif; ?>
<?php elseif($canManageBooking): ?>
<div class="clyo-calendar__policy" data-testid="calendar-policy"><p>O espaço entre duas consultas soma o intervalo posterior da primeira e o anterior da próxima. Os intervalos também precisam caber na jornada.</p>
<form method="post" action="/configuracoes">
<input type="hidden" name="_csrf_token" value="<?= $ce($calendarCsrf) ?>"><input type="hidden" name="section" value="calendar_policy">
<div class="clyo-calendar__grid"><label class="clyo-calendar__field">Minutos antes de cada consulta<input class="form-control" type="number" name="buffer_before_minutes" min="0" max="120" required value="<?= (int)$calendarPolicy['buffer_before_minutes'] ?>"></label><label class="clyo-calendar__field">Minutos depois de cada consulta<input class="form-control" type="number" name="buffer_after_minutes" min="0" max="120" required value="<?= (int)$calendarPolicy['buffer_after_minutes'] ?>"></label></div>
<p><label><input type="checkbox" name="waitlist_enabled" value="1" <?= !empty($calendarPolicy['waitlist_enabled'])?'checked':'' ?>> Habilitar lista de espera para pacientes autenticados</label></p>
<label class="clyo-calendar__field">Prazo para aceitar uma oferta (minutos)<input class="form-control" type="number" name="offer_ttl_minutes" min="5" max="60" required value="<?= (int)$calendarPolicy['offer_ttl_minutes'] ?>"></label>
<div class="clyo-calendar__actions"><button class="btn btn-primary" type="submit">Salvar regras da clínica</button></div>
</form><h3>Lista de espera da clínica</h3><p>As ofertas seguem a ordem de entrada entre os pedidos compatíveis e dependem do processamento agendado. Não confirmam consultas automaticamente.</p>
<?php if(!$waitlistSummary): ?><p class="clyo-calendar__subtle">Nenhum pedido registrado.</p><?php else: ?><ul class="clyo-calendar__counts"><?php foreach($waitlistSummary as $summary): ?><li><?= $ce(['waiting'=>'Aguardando','offered'=>'Oferta ativa','fulfilled'=>'Aceita','cancelled'=>'Cancelada','expired'=>'Expirada'][$summary['status']]??$summary['status']) ?>: <?= (int)$summary['total'] ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>
</div></section>

<script nonce="<?= $ce(\ClyoNutri\Core\Http\SecurityHeaders::nonce()) ?>">
(function(){
const root=document.getElementById('calendar-heading').closest('section');
const linkedRule=document.getElementById(location.hash.slice(1));if(linkedRule&&linkedRule.closest('section')===root){const editor=linkedRule.querySelector('.clyo-calendar__edit');if(editor)editor.open=true;const group=linkedRule.closest('.clyo-calendar__date-group');if(group)group.open=true;}
const savedRules=<?= json_encode(array_map(static fn($r)=>['date'=>$r['rule_date'],'scope'=>$r['professional_id']],$calendarRules),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
root.querySelectorAll('form').forEach(function(form){
if(form.querySelector('[name="section"][value="calendar_rule"]')){
 const date=form.querySelector('[name="rule_date"]'),note=document.createElement('p');note.setAttribute('role','status');date.closest('div').after(note);
 date.addEventListener('change',()=>{const n=savedRules.filter(rule=>rule.date===date.value).length;note.textContent=n?(n===1?'Já existe 1 regra nesta data.':'Já existem '+n+' regras nesta data.')+' Confira escopos e períodos antes de cadastrar outra. Nenhuma regra será removida automaticamente.':'';});
}
const period=form.querySelector('[data-calendar-period]');if(!period)return;
const times=form.querySelectorAll('input[type="time"]'),kind=form.querySelector('[name="kind"]');
function sync(){const open=kind&&kind.value==='open';if(open)period.value='partial';const fullOption=period.querySelector('option[value="full"]');if(fullOption)fullOption.disabled=open;times.forEach(function(input){input.disabled=period.value==='full';input.required=!input.disabled;});}
period.addEventListener('change',sync);if(kind)kind.addEventListener('change',sync);form.addEventListener('reset',function(){setTimeout(sync,0);});sync();
});
})();
</script>
