<?php
$et=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$timelineTypes=['Consulta','Prontuário','Anamnese','Avaliação','Plano alimentar','Check-in','Cobrança'];
$type=is_string($_GET['timeline_type']??null)?$_GET['timeline_type']:'';
if(!in_array($type,$timelineTypes,true))$type='';
$dateFilter=static function(string $key): string {
    $v=$_GET[$key]??''; if(!is_string($v)||$v==='')return '';
    $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    return $d&&$d->format('Y-m-d')===$v?$v:'';
};
$from=$dateFilter('timeline_from');$to=$dateFilter('timeline_to');
$timelineError=$from!==''&&$to!==''&&$from>$to;
$events=array_values(array_filter($profile['timeline'],static fn($v)=>($type===''||$v['type']===$type)&&($from===''||substr($v['date'],0,10)>=$from)&&($to===''||substr($v['date'],0,10)<=$to)));
// NI2-887: a ficha mostra os 5 mais recentes; "Ver histórico completo" (ou qualquer filtro) abre a lista paginada com filtros.
$filtered=$type!==''||$from!==''||$to!=='';
$full=$filtered||($_GET['historico']??'')==='completo'||isset($_GET['timeline_page']);
$perPage=$full?10:5;
$page=$full&&is_scalar($_GET['timeline_page']??null)?max(1,(int)$_GET['timeline_page']):1;
$pages=max(1,(int)ceil(count($events)/$perPage));$page=min($page,$pages);$visible=array_slice($events,($page-1)*$perPage,$perPage);
$query=['historico'=>'completo','timeline_type'=>$type,'timeline_from'=>$from,'timeline_to'=>$to];
?>
<section class="card" aria-labelledby="patient-timeline" data-testid="longitudinal-summary">
<h2 id="patient-timeline">Histórico recente</h2>
<?php if($full): ?>
<details class="ux52-timeline-filter-box" data-testid="timeline-filters" open><summary>Filtrar histórico</summary>
<p class="cn-profile-note">Resumo com até 20 registros por categoria. Os filtros se aplicam a este resumo; o histórico completo permanece no módulo de origem.</p>
<form method="get" action="/pacientes/<?= (int)$p['id'] ?>#patient-timeline" class="ux52-timeline-filters">
<input type="hidden" name="historico" value="completo">
<label>Tipo de registro<select name="timeline_type" class="form-control"><option value="">Todos</option><?php foreach($timelineTypes as $t): ?><option <?= $type===$t?'selected':'' ?>><?= $et($t) ?></option><?php endforeach; ?></select></label>
<label>Desde<input type="date" class="form-control" name="timeline_from" value="<?= $et($from) ?>"></label>
<label>Até<input type="date" class="form-control" name="timeline_to" value="<?= $et($to) ?>"></label>
<button type="submit" class="btn btn-secondary">Filtrar histórico</button><a href="/pacientes/<?= (int)$p['id'] ?>?historico=completo#patient-timeline">Limpar filtros</a>
</form>
</details>
<?php endif; ?>
<?php if($timelineError): ?><p class="op-message op-message--error" role="alert">A data inicial deve ser anterior ou igual à final.</p><?php elseif(!$visible): ?><p>Nenhum registro encontrado neste resumo para os filtros selecionados.</p><?php else: ?>
<ol class="cn-profile-timeline" data-testid="profile-timeline">
<?php foreach($visible as $event): ?><li><span class="ds-pill ds-pill--neutral"><?= $et($event['type']) ?></span> <time datetime="<?= $et(date('c',strtotime($event['date']))) ?>"><?= $et(date('d/m/Y H:i',strtotime($event['date']))) ?></time>
<a href="<?= $et($event['url']) ?>" title="Referência para suporte: <?= (int)$event['id'] ?>"><?= $et($event['title']) ?></a>
<span class="ux52-event-state"><?= $et($event['state']) ?><?= $event['version']?' · v'.(int)$event['version']:'' ?></span></li><?php endforeach; ?></ol>
<?php if(!$full): ?>
<p class="ux52-timeline-more"><?= $et(\ClyoNutri\Application\View\UserText::count(count($events),'{n} registro','{n} registros')) ?> no resumo.<?php if(count($events)>count($visible)): ?> <a href="/pacientes/<?= (int)$p['id'] ?>?historico=completo#patient-timeline" data-testid="timeline-ver-tudo">Ver histórico completo e filtros</a><?php else: ?> <a href="/pacientes/<?= (int)$p['id'] ?>?historico=completo#patient-timeline" data-testid="timeline-ver-tudo">Filtrar histórico</a><?php endif; ?></p>
<?php else: ?>
<p><?= count($events) ?> registros neste resumo filtrado. Página <?= $page ?> de <?= $pages ?>. <a href="/pacientes/<?= (int)$p['id'] ?>#patient-timeline">Voltar ao resumo</a></p>
<nav aria-label="Páginas do histórico"><?php if($page>1): ?><a class="btn btn-secondary" href="?<?= $et(http_build_query($query+['timeline_page'=>$page-1])) ?>#patient-timeline">Anteriores</a><?php endif; ?><?php if($page<$pages): ?><a class="btn btn-secondary" href="?<?= $et(http_build_query($query+['timeline_page'=>$page+1])) ?>#patient-timeline">Mais registros</a><?php endif; ?></nav>
<?php endif; ?>
<?php endif; ?>
</section>
